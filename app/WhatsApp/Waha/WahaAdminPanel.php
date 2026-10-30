<?php

namespace App\WhatsApp\Waha;

use App\Models\User;
use App\Models\WhatsAppMessage;
use App\WhatsApp\WahaProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Lógica del panel /admin/whatsapp: estado REAL de la sesión, acciones de control y mensaje de prueba.
 *
 * Reglas:
 *  - «Conectado» solo con WAHA en WORKING y el número esperado; una API que responde 200 no es una sesión conectada
 *    (tras pedir un código de emparejamiento la sesión ya conoce el número en `me` sin estar vinculada).
 *  - Consultar es de SOLO LECTURA. Iniciar/recuperar/restablecer son acciones explícitas, con tope de frecuencia (sin bucles).
 *  - Independiente de WHATSAPP_ENABLED: esa bandera gobierna los envíos a usuarios; esta, el control administrativo.
 *  - El mensaje de prueba tiene texto y destinatario FIJOS (configuración del despliegue); pasa por WahaProvider, así que
 *    respeta lista autorizada, deduplicado, ritmo y estado de la sesión, y queda en whatsapp_messages.
 */
final class WahaAdminPanel
{
    public const TEST_MESSAGE = '¡Hola! 👋 Esta es una notificación de prueba de MOVA. Estamos comprobando que nuestro sistema automático de WhatsApp funciona correctamente.';

    public const TEST_REFERENCE_PREFIX = 'admin_test#';

    private const CONTROL_LIMITER = 'waha-admin-control';

    public function __construct(private readonly ?WahaClient $client = null) {}

    public static function enabled(): bool
    {
        return (bool) config('services.waha.admin_control_enabled', false);
    }

    private function client(): WahaClient
    {
        return $this->client ?? WahaClient::fromConfig();
    }

    /** Estado completo para la interfaz. Una sola consulta de solo lectura a WAHA (caché de 2 s para varias pestañas). */
    public function snapshot(): array
    {
        $info = $this->enabled() ? $this->sessionInfo() : null;

        return $this->build($info);
    }

    /** @return array{ok: bool, status: ?string, me: ?array, error: ?string, kind: ?string} */
    private function sessionInfo(): array
    {
        return Cache::remember('waha:admin:session', 2, function () {
            try {
                $info = $this->client()->sessionStatus();
            } catch (\Throwable) {
                $info = ['ok' => false, 'status' => null, 'me' => null, 'error' => 'Configuración de WAHA inválida.', 'kind' => 'config'];
            }
            if ($info['ok']) {
                WahaSessionState::record($info['status']);
            }
            unset($info['data']); // la configuración de la sesión puede traer claves/cabeceras: no se cachea ni se usa

            return $info;
        });
    }

    private function build(?array $info): array
    {
        $expected = $this->expectedDigits();
        $state = 'disabled';
        $connectedDigits = null;
        $error = null;
        $raw = $info['status'] ?? null;

        if ($info !== null) {
            if (! $info['ok']) {
                $state = 'unavailable';
                $error = match ($info['kind'] ?? null) {
                    'auth' => 'WAHA rechazó las credenciales de MOVA (revisa la API key en Azure).',
                    'unreachable' => 'No hay conexión con el servidor de WAHA (¿VM apagada o red privada caída?).',
                    'server' => 'WAHA respondió con un error interno.',
                    'config' => 'La configuración de WAHA es inválida.',
                    default => 'No se pudo consultar a WAHA.',
                };
            } else {
                $connectedDigits = ($raw === 'WORKING') ? $this->digitsFromMe($info['me'] ?? null) : null;
                $state = match ($raw) {
                    'WORKING' => ($expected === null || $connectedDigits === null) ? 'unverified_number' : ($connectedDigits !== $expected ? 'wrong_number' : 'connected'),
                    'SCAN_QR_CODE' => 'waiting_qr',
                    'STARTING' => 'starting',
                    'STOPPED' => 'disconnected',
                    default => 'error', // FAILED y cualquier valor desconocido
                };
                $error = match ($state) {
                    'error' => 'La sesión falló y no se reconecta sola. Pulsa «Recuperar sesión»; si vuelve a fallar, «Restablecer vinculación».',
                    'unverified_number' => 'La sesión está conectada pero no se pudo verificar el número (o falta WAHA_EXPECTED_NUMBER). No se habilita la prueba ni restablecer hasta confirmarlo.',
                    'wrong_number' => 'Está vinculado un número distinto del dedicado de MOVA. Restablece la vinculación y escanea con el número correcto.',
                    'disconnected' => 'La vinculación no se completó o expiró (WAHA espera unos 2–3 minutos). Inicia una nueva.',
                    default => null,
                };
            }
        }

        $channelOn = (bool) config('services.whatsapp.enabled', false);
        $testRecipient = $this->testRecipientDigits();
        $testBlock = $this->testBlockReason($state, $testRecipient);
        $limiterRemaining = RateLimiter::remaining(self::CONTROL_LIMITER, 4);

        return [
            'generatedAt' => now()->toIso8601String(),
            'controlEnabled' => $this->enabled(),
            'state' => $state,
            'rawStatus' => $raw,
            'error' => $error,
            'number' => [
                'connected' => $connectedDigits ? $this->pretty($connectedDigits) : null,
                'expected' => $expected ? $this->pretty($expected) : null,
                'matches' => ($connectedDigits && $expected) ? $connectedDigits === $expected : null,
            ],
            'lastConnectedAt' => WahaSessionState::lastWorkingAt()?->toIso8601String(),
            'server' => ['reachable' => $info !== null && $info['ok']],
            'environment' => [
                'label' => (string) config('services.waha.environment_label', 'piloto'),
                'pilotExpiresAt' => $this->pilotExpiresAt(),
            ],
            'notifications' => [
                'enabled' => $channelOn,
                'provider' => (string) config('services.whatsapp.provider', 'fake'),
                'allowlistActive' => trim((string) config('services.waha.allowed_recipients', '')) !== '',
                'pausedUntil' => WahaSessionState::pausedUntil()?->toIso8601String(),
            ],
            'actions' => [
                'start' => $state === 'disconnected',
                'restart' => in_array($state, ['error', 'starting', 'waiting_qr', 'disconnected'], true),
                // Nunca con la sesión conectada o sin verificar; en «error» solo si WAHA reporta FAILED explícitamente.
                'reset' => ($state === 'error' && $raw === 'FAILED') || in_array($state, ['disconnected', 'waiting_qr', 'wrong_number'], true),
                'pair' => $state === 'waiting_qr' && $expected !== null,
                'qr' => $state === 'waiting_qr',
                'controlAttemptsLeft' => $limiterRemaining,
            ],
            'test' => [
                'canSend' => $testBlock === null,
                'blockedReason' => $testBlock,
                'recipient' => $testRecipient ? $this->maskPretty($testRecipient) : null,
                'message' => self::TEST_MESSAGE,
                'last' => $this->lastTest(),
            ],
        ];
    }

    // ── Acciones de control ──────────────────────────────────────────────────────────────────────────

    /** @return array{ok: bool, message: string} */
    public function control(string $action, User $admin): array
    {
        $lock = Cache::lock('waha:admin:control', 30);
        if (! $lock->get()) {
            return ['ok' => false, 'message' => 'Hay otra acción sobre la sesión en curso. Espera unos segundos.'];
        }

        try {
            return $this->performControl($action, $admin);
        } finally {
            $lock->release();
        }
    }

    /** @return array{ok: bool, message: string} */
    private function performControl(string $action, User $admin): array
    {
        if (! $this->enabled()) {
            return ['ok' => false, 'message' => 'El control administrativo de WhatsApp está desactivado en este entorno.'];
        }

        Cache::forget('waha:admin:session');
        $snap = $this->build($this->sessionInfo());
        $state = $snap['state'];

        $allowed = match ($action) {
            'start' => $snap['actions']['start'],
            'restart' => $snap['actions']['restart'],
            'reset' => $snap['actions']['reset'],
            default => false,
        };
        if (! $allowed) {
            return ['ok' => false, 'message' => $state === 'connected'
                ? 'La sesión ya está conectada: no hace falta (ni conviene) tocarla.'
                : 'Esa acción no aplica al estado actual de la sesión.'];
        }

        // Sin bucles: como máximo 4 acciones de control cada 15 minutos para toda la plataforma.
        if (! RateLimiter::attempt(self::CONTROL_LIMITER, 4, fn () => true, 900)) {
            return ['ok' => false, 'message' => 'Se alcanzó el límite de acciones sobre la sesión (4 cada 15 min). Espera antes de reintentar.'];
        }

        $client = $this->client();
        $ok = match ($action) {
            'start' => $client->startSession(),
            'restart' => $client->restartSession(),
            // Restablecer = cerrar la sesión local (descarta credenciales sin completar o de otro número) y arrancar limpia.
            // Solo se llega aquí por una acción explícita y confirmada del administrador, nunca automáticamente.
            'reset' => $client->logoutSession() && $this->startAfterLogout($client),
        };

        Log::notice('[WhatsApp/Admin] Acción de control sobre la sesión', ['action' => $action, 'admin_id' => $admin->id, 'from_state' => $state, 'accepted' => $ok]);
        Cache::forget('waha:admin:session');

        return ['ok' => $ok, 'message' => $ok
            ? 'Acción enviada a WAHA. El estado se actualiza en unos segundos.'
            : 'WAHA no aceptó la acción. Revisa el estado del servidor.'];
    }

    private function startAfterLogout(WahaClient $client): bool
    {
        usleep(1_500_000);

        return $client->startSession();
    }

    /** @return array{ok: bool, code: ?string, message: ?string} */
    public function pairingCode(User $admin): array
    {
        $expected = $this->expectedDigits();
        if (! $this->enabled() || $expected === null) {
            return ['ok' => false, 'code' => null, 'message' => 'El número dedicado de MOVA no está configurado.'];
        }

        Cache::forget('waha:admin:session');
        if (! $this->build($this->sessionInfo())['actions']['pair']) {
            return ['ok' => false, 'code' => null, 'message' => 'Solo se puede pedir un código con la sesión esperando vinculación.'];
        }

        if (! RateLimiter::attempt('waha-admin-pair', 3, fn () => true, 300)) {
            return ['ok' => false, 'code' => null, 'message' => 'Demasiados códigos solicitados. Espera unos minutos.'];
        }

        $result = $this->client()->requestPairingCode($expected);
        Log::notice('[WhatsApp/Admin] Código de emparejamiento solicitado', ['admin_id' => $admin->id, 'issued' => $result['code'] !== null]); // sin el código

        return ['ok' => $result['code'] !== null, 'code' => $result['code'], 'message' => $result['reason']];
    }

    // ── Mensaje de prueba ────────────────────────────────────────────────────────────────────────────

    /** @return array{ok: bool, message: string} */
    public function sendTest(User $admin): array
    {
        Cache::forget('waha:admin:session');
        $snap = $this->build($this->enabled() ? $this->sessionInfo() : null);

        if (! $snap['test']['canSend']) {
            return ['ok' => false, 'message' => $snap['test']['blockedReason']];
        }

        if (! RateLimiter::attempt('waha-admin-test:'.$admin->id, 3, fn () => true, 600)) {
            return ['ok' => false, 'message' => 'Límite de pruebas alcanzado (3 cada 10 min).'];
        }

        $recipient = (string) $this->testRecipientDigits();
        $reference = self::TEST_REFERENCE_PREFIX.$admin->id.':'.now()->getTimestamp();
        $sent = (new WahaProvider($this->client))->sendTemplate($recipient, 'generic_notification', [self::TEST_MESSAGE], $reference);

        Log::notice('[WhatsApp/Admin] Mensaje de prueba solicitado', ['admin_id' => $admin->id, 'accepted' => $sent]);

        $row = WhatsAppMessage::where('client_reference', $reference)->latest('id')->first();

        return $sent
            ? ['ok' => true, 'message' => 'WAHA aceptó el mensaje. «Aceptado» no es «entregado»: la entrega se confirma con el ACK.']
            : ['ok' => false, 'message' => $this->failureText($row)];
    }

    private function failureText(?WhatsAppMessage $row): string
    {
        if ($row === null) {
            return 'No se envió: el mensaje ya se había enviado hace poco (se evitan duplicados) o el sistema lo descartó.';
        }

        return match ($row->status->value) {
            'unknown' => 'Resultado incierto: puede haber salido. No se reintenta para evitar un duplicado; revisa el ACK.',
            'skipped' => 'No se envió: '.($row->skip_reason?->value ?? 'omitido por una regla de seguridad').'.',
            default => 'No se envió: '.($row->error ? mb_substr($row->error, 0, 160) : 'WAHA rechazó el envío').'.',
        };
    }

    private function testBlockReason(string $state, ?string $recipient): ?string
    {
        if (! $this->enabled()) {
            return 'El control administrativo está desactivado.';
        }
        if ($state !== 'connected') {
            return 'Solo se puede enviar con la sesión conectada y vinculada al número correcto.';
        }
        if ($recipient === null) {
            return 'No hay destinatario de prueba configurado.';
        }
        if (! $this->isAllowlisted($recipient)) {
            return 'El destinatario de prueba no está en la lista autorizada del piloto.';
        }

        return null;
    }

    private function lastTest(): ?array
    {
        $row = WhatsAppMessage::where('client_reference', 'like', self::TEST_REFERENCE_PREFIX.'%')->latest('id')->first();
        if ($row === null) {
            return null;
        }

        return [
            'status' => $row->status->value,
            'messageId' => $row->provider_message_id,
            'at' => $row->created_at?->toIso8601String(),
            'statusUpdatedAt' => $row->status_updated_at?->toIso8601String(),
            'deliveredAt' => $row->delivered_at?->toIso8601String(),
            'readAt' => $row->read_at?->toIso8601String(),
            'error' => $row->error ? mb_substr($row->error, 0, 160) : null,
            'skipReason' => $row->skip_reason?->value,
        ];
    }

    // ── Utilidades ───────────────────────────────────────────────────────────────────────────────────

    private function expectedDigits(): ?string
    {
        $v = trim((string) config('services.waha.expected_number', ''));

        return $v === '' ? null : WahaClient::digits($v);
    }

    private function testRecipientDigits(): ?string
    {
        $v = trim((string) config('services.waha.admin_test_recipient', ''));

        return $v === '' ? null : WahaClient::digits($v);
    }

    private function isAllowlisted(string $digits): bool
    {
        $list = array_filter(array_map(fn ($n) => WahaClient::digits((string) $n), explode(',', (string) config('services.waha.allowed_recipients', ''))));

        return $list !== [] && in_array($digits, $list, true);
    }

    /** @param  mixed  $me */
    private function digitsFromMe($me): ?string
    {
        $id = is_array($me) ? ($me['id'] ?? null) : null;
        if (! is_string($id)) {
            return null;
        }
        $user = explode(':', explode('@', $id)[0])[0]; // 51905922835:12@s.whatsapp.net → 51905922835
        $digits = preg_replace('/\D+/', '', $user) ?? '';

        return $digits === '' ? null : $digits;
    }

    private function pretty(string $digits): string
    {
        return str_starts_with($digits, '51') && strlen($digits) === 11
            ? '+51 '.substr($digits, 2, 3).' '.substr($digits, 5, 3).' '.substr($digits, 8, 3)
            : '+'.$digits;
    }

    private function maskPretty(string $digits): string
    {
        return str_starts_with($digits, '51') && strlen($digits) === 11
            ? '+51 '.substr($digits, 2, 3).' *** '.substr($digits, 8, 3)
            : '+'.substr($digits, 0, 3).'***';
    }

    private function pilotExpiresAt(): ?string
    {
        $v = trim((string) config('services.waha.pilot_expires_at', ''));
        if ($v === '') {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse($v)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }
}
