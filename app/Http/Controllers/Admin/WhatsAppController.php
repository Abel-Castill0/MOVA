<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminMfaService;
use App\WhatsApp\Waha\WahaAdminPanel;
use App\WhatsApp\Waha\WahaClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Panel nativo de MOVA para vincular y administrar el WhatsApp dedicado (WAHA).
 *
 * Seguridad (rutas bajo role:admin + admin.mfa; las acciones sensibles exigen además MFA reciente — admin.mfa:sensitive):
 *  - El navegador NUNCA habla con WAHA: Laravel consulta por la red privada y entrega solo lo necesario. La API key no sale del servidor.
 *  - Consultar estado y QR es de SOLO LECTURA; reiniciar/restablecer son POST explícitos, confirmados y con tope de frecuencia.
 *  - El QR solo se entrega si la sesión está realmente esperando vinculación y es un PNG válido (nunca JSON/HTML de error como imagen).
 *  - Ni el QR ni el código de emparejamiento se registran en logs; todas las respuestas llevan Cache-Control: no-store.
 *  - El mensaje de prueba va a un destinatario FIJO de configuración (no hay campo de destinatario) y no depende de WHATSAPP_ENABLED.
 */
class WhatsAppController extends Controller
{
    private const NO_STORE = ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff'];

    public function __construct(private readonly WahaAdminPanel $panel, private readonly AdminMfaService $mfa) {}

    public function index(Request $request): InertiaResponse
    {
        $stepUp = $this->stepUp($request);
        if (! $stepUp['valid']) {
            // Tras verificar el MFA, el administrador vuelve aquí.
            $request->session()->put('url.intended', route('admin.whatsapp.index'));
        }

        return Inertia::render('Admin/WhatsAppStatus', [
            'panel' => $this->panel->snapshot(),
            'stepUp' => $stepUp,
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        return response()->json(['panel' => $this->panel->snapshot(), 'stepUp' => $this->stepUp($request)], 200, self::NO_STORE);
    }

    /** PNG del QR actual. Si no hay un QR real disponible responde JSON con la causa (nunca una imagen falsa). */
    public function qr(): Response|JsonResponse
    {
        $snap = $this->panel->snapshot();

        if (! $snap['controlEnabled']) {
            return response()->json(['message' => 'El control administrativo de WhatsApp está desactivado.'], 403, self::NO_STORE);
        }

        if ($snap['state'] !== 'waiting_qr') {
            return response()->json(['message' => 'La sesión no está esperando vinculación.', 'state' => $snap['state']], 409, self::NO_STORE);
        }

        $qr = WahaClient::fromConfig()->qr();

        if ($qr['png'] === null) {
            return response()->json(['message' => $qr['reason']], 502, self::NO_STORE);
        }

        return response($qr['png'], 200, ['Content-Type' => 'image/png'] + self::NO_STORE);
    }

    /** start | restart | reset. Restablecer descarta credenciales sin completar: exige confirmación escrita. */
    public function control(Request $request, string $action): JsonResponse
    {
        $request->validate(['confirm' => $action === 'reset' ? ['required', 'in:RESTABLECER'] : ['required', 'accepted']]);

        $result = $this->panel->control($action, $request->user());

        return response()->json($result + ['panel' => $this->panel->snapshot()], $result['ok'] ? 200 : 422, self::NO_STORE);
    }

    public function pairingCode(Request $request): JsonResponse
    {
        $result = $this->panel->pairingCode($request->user());

        return response()->json($result, $result['ok'] ? 200 : 422, self::NO_STORE);
    }

    public function sendTest(Request $request): JsonResponse
    {
        $result = $this->panel->sendTest($request->user());

        return response()->json($result + ['panel' => $this->panel->snapshot()], $result['ok'] ? 200 : 422, self::NO_STORE);
    }

    /** @return array{valid: bool, secondsLeft: int, challengeUrl: string} */
    private function stepUp(Request $request): array
    {
        $window = (int) config('mova_security.admin_mfa.step_up_minutes', 15) * 60;
        $elapsed = $this->mfa->secondsSinceVerified($request, $request->user());
        $valid = app()->environment('local') || ($elapsed !== null && $elapsed <= $window);

        return [
            'valid' => $valid,
            'secondsLeft' => $valid && $elapsed !== null ? max(0, $window - $elapsed) : 0,
            'challengeUrl' => route('admin.mfa.challenge'),
        ];
    }
}
