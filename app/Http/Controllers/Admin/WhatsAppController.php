<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendWhatsAppNotificationJob;
use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WhatsAppController extends Controller
{
    public function __construct(
        protected WhatsAppService $whatsAppService
    ) {}

    /**
     * Renderiza la vista de estado y prueba de WAHA en el panel de administración.
     */
    public function index(): Response
    {
        $statusInfo = $this->whatsAppService->checkSessionStatus();

        return Inertia::render('Admin/WhatsAppStatus', [
            'sessionInfo' => $statusInfo,
            'wahaConfig'  => [
                'session' => config('services.waha.session', 'default'),
                'url'     => config('services.waha.url', 'http://localhost:3000'),
            ],
        ]);
    }

    /**
     * Endpoint API para consultar el estado en tiempo real (polling o refresh).
     */
    public function status(): JsonResponse
    {
        $statusInfo = $this->whatsAppService->checkSessionStatus();

        return response()->json($statusInfo);
    }

    /**
     * Envía un mensaje de prueba utilizando la cola asíncrona.
     */
    public function sendTest(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'phone'   => ['required', 'string', 'min:8', 'max:20'],
            'message' => ['required', 'string', 'min:1', 'max:1000'],
            'sync'    => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('sync')) {
            // Envío síncrono para prueba inmediata
            $result = $this->whatsAppService->sendText($validated['phone'], $validated['message']);

            if ($result['success']) {
                return back()->with('success', 'Mensaje de WhatsApp enviado directamente con éxito.');
            }

            return back()->with('error', 'Error al enviar mensaje: ' . ($result['error'] ?? 'Falló conexión con WAHA.'));
        }

        // Envío asíncrono recomendado
        SendWhatsAppNotificationJob::dispatch($validated['phone'], $validated['message']);

        return back()->with('success', 'Mensaje encolado en SendWhatsAppNotificationJob exitosamente.');
    }
}
