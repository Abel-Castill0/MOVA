<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Muestra la URL que hay que registrar en CulqiPanel (Eventos > Webhooks).
 * El segmento final es un secreto: por defecto se enmascara y solo se imprime
 * completo con --reveal (pensado para que lo ejecute el propietario en su
 * propia terminal, nunca para pegarlo en chats ni logs).
 */
class CulqiWebhookUrl extends Command
{
    protected $signature = 'culqi:webhook-url {--base= : URL base pública (por defecto APP_URL)} {--reveal : imprime el secreto completo}';

    protected $description = 'Culqi: URL de webhook a registrar en CulqiPanel y eventos recomendados (secreto enmascarado salvo --reveal).';

    public const EVENTS = [
        'charge.creation.succeeded',
        'charge.creation.failed',
        'refund.creation.succeeded',
        'refund.creation.failed',
    ];

    public function handle(): int
    {
        $secret = (string) config('payments.culqi.webhook_secret');
        if ($secret === '') {
            $this->error('CULQI_WEBHOOK_SECRET no está configurado. Genera uno largo (p. ej. `openssl rand -hex 24`) y guárdalo en el .env / gestor de secretos.');

            return self::FAILURE;
        }

        $base = rtrim((string) ($this->option('base') ?: config('app.url')), '/');
        // Enmascarado: no se muestra ni un solo carácter ni la longitud del secreto.
        $shown = $this->option('reveal') ? $secret : '<CULQI_WEBHOOK_SECRET>';

        $this->line("URL del webhook:  {$base}/api/webhooks/culqi/{$shown}");
        $this->line('Método: POST · Respuesta esperada: HTTP 200');
        $this->line('Eventos a asociar en CulqiPanel (un webhook por evento): '.implode(', ', self::EVENTS));
        $this->line('Activación en MOVA: CULQI_WEBHOOKS_ENABLED=true (hoy: '.var_export((bool) config('payments.culqi.webhooks_enabled'), true).').');

        if (! $this->option('reveal')) {
            $this->comment('Secreto enmascarado. Ejecuta con --reveal en TU terminal para copiarlo; no lo pegues en chats ni commits.');
        }

        if (! str_starts_with($base, 'https://')) {
            $this->warn('La URL no es https: CulqiPanel exige una URL pública; en local usa un túnel https (ngrok, cloudflared…).');
        }

        return self::SUCCESS;
    }
}
