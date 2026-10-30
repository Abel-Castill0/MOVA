<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\WhatsAppTestNotification;
use App\WhatsApp\Waha\WahaClient;
use App\WhatsApp\Waha\WahaSessionState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Panel de administración de la sesión WAHA.
 *
 * - Consultar el estado es de SOLO LECTURA (antes, abrir este panel ejecutaba logout + restart y podía desvincular el número).
 * - Reiniciar la sesión es una acción POST explícita del administrador.
 * - El envío de prueba YA NO se salta nada: solo va a usuarios registrados con teléfono verificado y consentimiento, y viaja
 *   por WhatsAppChannel (baja, suspensión, cuenta cerrada, lista del piloto, dedupe y límites incluidos).
 */
class WhatsAppController extends Controller
{
    public function index(): InertiaResponse
    {
        return Inertia::render('Admin/WhatsAppStatus', [
            'sessionInfo' => $this->statusPayload(),
            'wahaConfig' => [
                'session' => config('services.waha.session', 'default'),
                // Solo el host: la URL puede llevar una IP privada, nunca credenciales.
                'url' => parse_url((string) config('services.waha.url'), PHP_URL_HOST) ?: '—',
            ],
            'channel' => [
                'enabled' => (bool) config('services.whatsapp.enabled', false),
                'provider' => (string) config('services.whatsapp.provider', 'fake'),
                'allowlist' => trim((string) config('services.waha.allowed_recipients', '')) !== '',
                'pausedUntil' => WahaSessionState::pausedUntil()?->toIso8601String(),
            ],
        ]);
    }

    public function status(): JsonResponse
    {
        return response()->json($this->statusPayload());
    }

    /** PNG del QR de vinculación (solo administradores). Sin QR disponible → 404. */
    public function qr(): Response
    {
        $png = WahaClient::fromConfig()->qrPng();

        abort_if($png === null, 404);

        return response($png, 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'no-store, private']);
    }

    public function restart(): RedirectResponse
    {
        $ok = WahaClient::fromConfig()->restartSession();

        return back()->with($ok ? 'success' : 'error', $ok ? 'Sesión de WAHA reiniciada; actualiza el estado en unos segundos.' : 'WAHA no aceptó el reinicio.');
    }

    public function sendTest(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'min:8', 'max:20'],
            'message' => ['required', 'string', 'min:1', 'max:500'],
            'sync' => ['nullable', 'boolean'],
        ]);

        $normalized = User::normalizePhone($validated['phone']);
        $user = $normalized ? User::where('phone_verified_normalized', $normalized)->first() : null;

        if ($user === null) {
            return back()->with('error', 'Ese teléfono no pertenece a ningún usuario con teléfono verificado: el panel solo envía a destinatarios registrados.');
        }

        if (! config('services.whatsapp.enabled', false)) {
            return back()->with('error', 'El canal de WhatsApp está desactivado (WHATSAPP_ENABLED=false).');
        }

        if ($user->suspended_at !== null || $user->account_closed_at !== null) {
            return back()->with('error', 'La cuenta está suspendida o cerrada: no se envía.');
        }

        if (! $user->wantsWhatsAppNotifications()) {
            return back()->with('error', 'El usuario no ha dado su consentimiento para WhatsApp (o lo retiró): no se envía.');
        }

        $notification = new WhatsAppTestNotification($validated['message']);
        \Illuminate\Support\Facades\Log::info('[WhatsApp/Admin] Envío de prueba por el canal.', ['admin_id' => $request->user()->id, 'user_id' => $user->id]);

        // El canal es la autoridad final: aunque esta pre-comprobación cambiara, WhatsAppChannel vuelve a verificar todo.
        $request->boolean('sync') ? $user->notifyNow($notification) : $user->notify($notification);

        return back()->with('success', $request->boolean('sync')
            ? 'Mensaje procesado por WhatsAppChannel. Revisa whatsapp_messages para ver el resultado (enviado/omitido).'
            : 'Mensaje encolado en WhatsAppChannel.');
    }

    private function statusPayload(): array
    {
        $info = WahaClient::fromConfig()->sessionStatus();

        // Mantiene la forma que ya consumía la página.
        return [
            'success' => $info['ok'],
            'status' => $info['status'] ?? 'UNKNOWN',
            'data' => $info['data'],
            'error' => $info['error'],
        ];
    }
}
