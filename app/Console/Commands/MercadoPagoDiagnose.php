<?php

namespace App\Console\Commands;

use App\Payment\MercadoPagoPaymentProvider;
use Illuminate\Console\Command;

/**
 * Comando de diagnóstico OPT-IN — nunca corre en la suite automatizada ni
 * en ningún scheduler. Único propósito: confirmar, contra la API real de
 * Mercado Pago (siempre con las credenciales TEST de config/payments.php),
 * que MERCADOPAGO_ACCESS_TOKEN funciona de verdad — sin que el operador
 * tenga que pegar el token en ningún comando ni verlo en ningún log (lee
 * config()/env() internamente, nunca lo imprime).
 *
 * Sirve para resolver la pregunta pendiente: si el fallo previo
 * (`MP_API_DOWN: Failure while creating seller app`) dejó el entorno TEST
 * realmente inutilizable, o si las credenciales igual funcionan para
 * llamadas de solo lectura como esta.
 *
 * CORREGIDO — TEST WEBHOOK REALITY (ronda de hardening final): este
 * comando SOLO cubre el "payment smoke" de autenticación/conectividad
 * (GET /v1/payment_methods). La documentación oficial confirma que un pago
 * creado con credenciales TEST NO dispara webhooks automáticamente — un
 * futuro "payment smoke" real (POST /v1/payments + GET /v1/payments/{id})
 * NUNCA verificará por sí solo el camino del webhook. Verificar el webhook
 * en vivo exige un "webhook smoke" SEPARADO: el simulador de webhooks del
 * panel de Mercado Pago (DevPanel), o una notificación firmada armada a
 * mano y enviada al endpoint de MOVA — nunca un E2E automático
 * pago→webhook, porque Mercado Pago no lo ofrece. No prometer eso en
 * ningún reporte futuro.
 *
 * Uso: php artisan mercadopago:diagnose
 */
class MercadoPagoDiagnose extends Command
{
    protected $signature = 'mercadopago:diagnose';

    protected $description = 'Verifica conectividad/autenticación reales contra Mercado Pago (TEST) sin imprimir secretos — no usar con credenciales de producción.';

    public function handle(MercadoPagoPaymentProvider $provider): int
    {
        if (! config('payments.mercadopago.access_token')) {
            $this->error('MERCADOPAGO_ACCESS_TOKEN no está configurado en .env — nada que probar.');
            $this->line('Configúralo con las credenciales TEST de tu aplicación de Mercado Pago (panel: Tus integraciones) antes de reintentar.');

            return self::FAILURE;
        }

        $this->info('Consultando GET /v1/payment_methods (autenticación + conectividad en un solo llamado)...');

        $methods = $provider->fetchPaymentMethods();

        if ($methods === null) {
            $this->error('LIVE TEST BLOCKED BY MERCADO PAGO');
            $this->line('La llamada falló (ver logs para el detalle — nunca se imprime el token). Puede ser el mismo MP_API_DOWN reportado antes, u otro problema de la cuenta/entorno TEST. Reintenta más tarde; no se sustituye con credenciales de producción.');

            return self::FAILURE;
        }

        $this->info('✓ Autenticación y conectividad OK — Mercado Pago aceptó el Access Token TEST.');
        $this->line('Esto es solo el "payment smoke" — NO verifica el camino del webhook (Mercado Pago no dispara webhooks para pagos TEST). Usa el simulador de webhooks del panel para eso.');
        $this->newLine();

        if ($methods === []) {
            $this->warn('La cuenta no reporta ningún método de pago habilitado todavía.');
        } else {
            $this->table(
                ['id', 'name', 'payment_type_id', 'status'],
                array_map(
                    static fn (array $m) => [$m['id'], $m['name'], $m['payment_type_id'], $m['status']],
                    $methods
                )
            );

            // array_any() es de PHP 8.4 — este proyecto mantiene ^8.1
            // (sección 5 del encargo: no elevar el runtime), así que se usa
            // array_filter()+empty() en su lugar.
            $ids = array_filter(array_column($methods, 'id'));
            $yapeIds = array_filter($ids, static fn ($id) => str_contains(strtolower((string) $id), 'yape'));
            $hasYape = ! empty($yapeIds);
            $this->line($hasYape
                ? '✓ Yape aparece en la lista real de métodos habilitados para esta cuenta.'
                : '⚠ Yape NO aparece en la lista real — no asumir que está disponible solo porque la documentación genérica de Perú lo menciona (sección 15 del encargo).');
        }

        return self::SUCCESS;
    }
}
