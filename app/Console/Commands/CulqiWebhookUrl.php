<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

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
        'order.status.changed',
    ];

    public function handle(): int
    {
        $secret = (string) config('payments.culqi.webhook_secret');
        if ($secret === '') {
            $this->error('CULQI_WEBHOOK_SECRET no está configurado. Genera uno largo (p. ej. `openssl rand -hex 24`) y guárdalo en el .env / gestor de secretos.');

            return self::FAILURE;
        }

        // Revelar el secreto es una acción sensible: solo en una terminal interactiva, con confirmación explícita y
        // con una línea de auditoría (sin el secreto). Un pipe/CI/agente no interactivo nunca lo obtiene.
        if ($this->option('reveal')) {
            if (! $this->input->isInteractive()) {
                $this->error('--reveal exige una terminal interactiva: el secreto del webhook no se imprime en ejecuciones automáticas.');

                return self::FAILURE;
            }
            if (! $this->confirm('Vas a mostrar el secreto del webhook de Culqi en pantalla. ¿Estás en una terminal privada y sin grabación?', false)) {
                $this->warn('Cancelado: no se mostró el secreto.');

                return self::FAILURE;
            }
            Log::notice('[Culqi] Secreto del webhook revelado por consola.', ['usuario_so' => get_current_user(), 'entorno' => app()->environment()]);
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
