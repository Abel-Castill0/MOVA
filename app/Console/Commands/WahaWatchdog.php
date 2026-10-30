<?php

namespace App\Console\Commands;

use App\Models\OperationalAlert;
use App\Services\OperationalAlertService;
use App\WhatsApp\Waha\WahaClient;
use App\WhatsApp\Waha\WahaSessionState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Vigilante de la sesión de WAHA: recuperación NO DESTRUCTIVA por estados, con backoff y alerta a administradores.
 *
 *  WORKING        → no toca nada; cierra las alertas abiertas y reinicia el backoff.
 *  STARTING/SCAN  → espera (no crea otra sesión). SCAN_QR_CODE tras haber estado conectada = reautenticación necesaria → alerta.
 *  STOPPED        → arranca la sesión EXISTENTE (start oficial), con backoff.
 *  FAILED         → un único `restart` (que conserva las credenciales) por episodio con backoff largo; nunca logout ni borrado.
 *  Inalcanzable   → solo registra y, tras varios fallos seguidos, alerta (no hay nada seguro que ejecutar sin servidor).
 *
 * Jamás hace logout, jamás restablece la vinculación, jamás borra credenciales: eso solo lo hace un administrador desde el panel.
 */
class WahaWatchdog extends Command
{
    protected $signature = 'mova:waha-watchdog';

    protected $description = 'Vigila la sesión de WAHA: recuperación no destructiva con backoff y alerta a administradores';

    /** Esperas (min) entre intentos de recuperación del mismo episodio. */
    private const BACKOFF_MINUTES = [10, 60, 360, 720];

    private const ALERT_LINK = 'waha:link_lost';

    private const ALERT_DOWN = 'waha:unavailable';

    public function handle(OperationalAlertService $alerts): int
    {
        if (! (bool) config('services.waha.admin_control_enabled', false)) {
            return self::SUCCESS;
        }

        try {
            $info = WahaClient::fromConfig()->sessionStatus();
        } catch (\Throwable) {
            $info = ['ok' => false, 'status' => null, 'error' => 'config', 'kind' => 'config'];
        }

        if (! $info['ok']) {
            Cache::add('waha:watchdog:down', 0, now()->addHours(6));
            $fails = (int) Cache::increment('waha:watchdog:down');
            if ($fails >= 3) {
                $alerts->raise(self::ALERT_DOWN, OperationalAlert::TYPE_HEALTH_CHECK, 'WAHA no responde',
                    'MOVA no puede consultar el servidor de WhatsApp (WAHA) desde hace varios minutos. La sesión guardada no se toca; revisa la VM.',
                    ['kind' => $info['kind'] ?? null], OperationalAlert::SEVERITY_WARNING);
            }
            $this->line('WAHA no alcanzable ('.$fails.')');

            return self::SUCCESS;
        }

        Cache::forget('waha:watchdog:down');
        WahaSessionState::pruneContacts();
        $status = (string) ($info['status'] ?? 'UNKNOWN');
        WahaSessionState::record($status);
        $alerts->resolve(self::ALERT_DOWN);

        match ($status) {
            'WORKING' => $this->recovered($alerts),
            'STOPPED' => $this->stopped($alerts),
            'FAILED' => $this->failed($alerts),
            'SCAN_QR_CODE' => $this->needsReauth($alerts),
            default => null, // STARTING y desconocidos: esperar y conservar el diagnóstico
        };

        $this->line('Estado: '.$status);

        return self::SUCCESS;
    }

    private function recovered(OperationalAlertService $alerts): void
    {
        WahaSessionState::erase('watchdog_attempts');
        $alerts->resolve(self::ALERT_LINK);
    }

    private function needsReauth(OperationalAlertService $alerts): void
    {
        // Esperando QR sin haber estado nunca conectada = vinculación en curso; tras haberlo estado = vinculación perdida.
        if (WahaSessionState::lastWorkingAt() !== null && WahaSessionState::linkLost() !== null) {
            $this->raiseLost($alerts, 'SCAN_QR_CODE');
        }
    }

    /**
     * Solo se recupera una sesión que YA estuvo conectada. Una que nunca llegó a WORKING (vinculación a medias, QR sin escanear) la
     * gestiona el administrador desde el panel: reintentar login con credenciales incompletas no sirve y podría pisar una vinculación en curso.
     */
    private function stopped(OperationalAlertService $alerts): void
    {
        if (WahaSessionState::lastWorkingAt() !== null) {
            $this->attempt('start', $alerts);
        }
    }

    private function failed(OperationalAlertService $alerts): void
    {
        if (WahaSessionState::lastWorkingAt() === null) {
            return;
        }
        $this->raiseLost($alerts, 'FAILED');
        $this->attempt('restart', $alerts);
    }

    private function raiseLost(OperationalAlertService $alerts, string $status): void
    {
        $alerts->raise(self::ALERT_LINK, OperationalAlert::TYPE_HEALTH_CHECK, 'La vinculación de WhatsApp se perdió',
            'WAHA reporta '.$status.' tras haber estado conectado. Puede haber sido revocada por WhatsApp. No se borró nada: abre /admin/whatsapp, revisa «Dispositivos vinculados» en el celular y restablece la vinculación si hace falta.',
            ['status' => $status], OperationalAlert::SEVERITY_CRITICAL);
    }

    /** Un intento no destructivo (start/restart) respetando el backoff del episodio. */
    private function attempt(string $action, OperationalAlertService $alerts): void
    {
        $state = (array) (WahaSessionState::recall('watchdog_attempts') ?? ['n' => 0, 'next' => 0]);
        $n = (int) ($state['n'] ?? 0);

        if ($n >= count(self::BACKOFF_MINUTES) || now()->getTimestamp() < (int) ($state['next'] ?? 0)) {
            return;
        }

        // Mismo lock que el panel: un restablecimiento manual y un reintento automático nunca se pisan.
        $lock = Cache::lock('waha:admin:control', 30);
        if (! $lock->get()) {
            return;
        }
        try {
            $client = WahaClient::fromConfig();
            $ok = $action === 'start' ? $client->startSession() : $client->restartSession();
        } finally {
            $lock->release();
        }
        // Durable (tabla waha_state): sobrevive a los reinicios de contenedor; el episodio dura hasta volver a WORKING.
        WahaSessionState::remember('watchdog_attempts', ['n' => $n + 1, 'next' => now()->addMinutes(self::BACKOFF_MINUTES[$n])->getTimestamp()]);
        Log::notice('[WhatsApp/WAHA] Vigilante: intento de recuperación no destructivo', ['action' => $action, 'attempt' => $n + 1, 'accepted' => $ok]);
    }
}
