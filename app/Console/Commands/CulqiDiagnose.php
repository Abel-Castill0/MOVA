<?php

namespace App\Console\Commands;

use App\Payment\Culqi\CulqiClient;
use App\Payment\Culqi\CulqiConfig;
use Illuminate\Console\Command;

/**
 * Diagnóstico de Culqi: solo configuración (sin red) y, con --remote, consultas
 * de SOLO LECTURA (GET) a la API de pruebas. Nunca imprime valores de llaves.
 */
class CulqiDiagnose extends Command
{
    protected $signature = 'culqi:diagnose {--remote : además consulta la API de Culqi con GET de solo lectura (solo modo test)}';

    protected $description = 'Culqi: valida configuración (presencia/formato/modo, sin mostrar secretos) y, opcionalmente, la conexión de solo lectura.';

    public function handle(CulqiClient $client): int
    {
        $rows = [];
        foreach ([
            'CULQI_ENV' => config('payments.culqi.env'),
            'CULQI_PUBLIC_KEY' => CulqiConfig::publicKey(),
            'CULQI_SECRET_KEY' => CulqiConfig::secretKey(),
            'CULQI_WEBHOOK_SECRET' => config('payments.culqi.webhook_secret'),
            'CULQI_RSA_ID' => config('payments.culqi.rsa_id'),
            'CULQI_RSA_PUBLIC_KEY' => config('payments.culqi.rsa_public_key'),
        ] as $name => $value) {
            $rows[] = [$name, filled($value) ? 'presente' : 'AUSENTE', $this->shape($name, $value)];
        }
        $this->table(['variable', 'estado', 'formato/modo'], $rows);

        $this->line('Proveedor activo: PAYMENT_PROVIDER='.config('payments.provider').' · PAYMENTS_ENABLED='.var_export((bool) config('payments.enabled'), true));
        $this->line('Webhooks: CULQI_WEBHOOKS_ENABLED='.var_export((bool) config('payments.culqi.webhooks_enabled'), true).' · cifrado RSA: '.var_export((bool) config('payments.culqi.encrypt_payloads'), true));

        $problems = CulqiConfig::problems();
        foreach ($problems as $p) {
            $this->error($p);
        }
        if ($problems === []) {
            $this->info('Configuración coherente.');
        }

        if (! $this->option('remote')) {
            return $problems === [] ? self::SUCCESS : self::FAILURE;
        }

        if ($problems !== [] || ! CulqiConfig::isTestMode()) {
            $this->error('--remote solo se ejecuta con configuración coherente en modo test. No se llamó a Culqi.');

            return self::FAILURE;
        }

        $ok = true;
        foreach ([
            'GET /v2/charges' => fn () => $client->listCharges(['limit' => 1]),
            'GET /v2/refunds' => fn () => $client->listRefunds(['limit' => 1]),
            'GET /v2/orders' => fn () => $client->listOrders(['limit' => 1]),
            'GET /v2/events' => fn () => $client->listEvents(['limit' => 1]),
        ] as $label => $call) {
            $r = $call();
            $ok = $ok && $r->successful();
            $this->line(sprintf('%-18s HTTP %s %s', $label, $r->status ?: 'n/a', $r->successful() ? 'OK ('.count($r->body['data'] ?? []).' elemento(s) en la página)' : 'FALLÓ '.($r->transportError ?? $r->errorCode() ?? '')));
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function shape(string $name, mixed $value): string
    {
        if (! filled($value)) {
            return '-';
        }

        return match ($name) {
            'CULQI_ENV' => (string) $value,
            'CULQI_PUBLIC_KEY' => CulqiConfig::keyMode((string) $value, 'pk') ? 'pk_'.CulqiConfig::keyMode((string) $value, 'pk') : 'formato inválido',
            'CULQI_SECRET_KEY' => CulqiConfig::keyMode((string) $value, 'sk') ? 'sk_'.CulqiConfig::keyMode((string) $value, 'sk') : 'formato inválido',
            'CULQI_RSA_ID' => preg_match('/^[0-9a-f-]{36}$/i', (string) $value) ? 'uuid' : 'formato inesperado',
            'CULQI_RSA_PUBLIC_KEY' => str_contains(str_replace('\n', "\n", (string) $value), 'BEGIN PUBLIC KEY') ? 'PEM' : 'formato inesperado',
            default => 'len='.strlen((string) $value),
        };
    }
}
