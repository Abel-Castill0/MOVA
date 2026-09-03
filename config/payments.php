<?php

// Configuración del sistema de pagos automáticos (Culqi/otro PSP) — capa
// que se agrega ENCIMA del sistema de recargas manual existente
// (config/credits.php, RechargeRequest), no lo reemplaza. Mientras
// 'provider' sea 'fake', ningún profesor puede pagar de verdad por esta vía
// — solo existe para tests/desarrollo. Cambiar a 'culqi' requiere haber
// implementado App\Payment\CulqiPaymentProvider primero (ver ese archivo).
return [
    // F-03: 'enabled' declara si los pagos automáticos están operativos. Es lo
    // que permite a ProviderGuard distinguir "todavía no conectamos Culqi"
    // (enabled=false, fake aceptable) de "los pagos están vivos pero apuntan
    // al proveedor falso" (enabled=true + fake en producción = arranque
    // abortado). Sin esta bandera no se puede diferenciar una integración
    // pendiente de una integración rota.
    'enabled' => env('PAYMENTS_ENABLED', false),

    'provider' => env('PAYMENT_PROVIDER', 'fake'),

    // PROVIDER SOURCE OF TRUTH (MOVA Yape Checkout Pre-Card Hardening): la
    // ÚNICA lista de proveedores reales de pago soportados por MOVA.
    // AppServiceProvider (ProviderGuard::resolve()) y
    // Console\Commands\HealthCheck la leen desde aquí — antes cada uno
    // tenía su propio array hardcodeado y ya habían divergido una vez
    // (HealthCheck se quedó con solo 'culqi' cuando se agregó Mercado
    // Pago, reportando PAYMENT_PROVIDER_INVALID pese a una config
    // perfectamente válida). No es 'fake' — ProviderGuard::FAKE ya lo
    // representa por separado y no es "un proveedor real soportado".
    'supported_providers' => ['culqi', 'mercadopago'],

    'culqi' => [
        'public_key' => env('CULQI_PUBLIC_KEY'),
        'private_key' => env('CULQI_PRIVATE_KEY'),
        'webhook_secret' => env('CULQI_WEBHOOK_SECRET'),
        'env' => env('CULQI_ENV', 'test'),
    ],

    // Mercado Pago — Payments API (`POST/GET /v1/payments`), Perú/MPE. La
    // aplicación existente se creó originalmente para Orders API — ese
    // pivot está documentado en la sesión, ver docs/payments-architecture.md;
    // el access_token no está atado por Mercado Pago a un "api_type"
    // declarado (autentica la cuenta vendedora), así que se reutiliza sin
    // crear una aplicación nueva. access_token/webhook_secret son SIEMPRE
    // los de TEST hasta que 'provider' cambie a 'mercadopago' Y este
    // comentario se actualice explícitamente tras activar producción.
    //
    // webhook_secret NUNCA viene de get_credentials/application_list — se
    // genera aparte, al CONFIGURAR el webhook en el panel (Tus integraciones
    // > Webhooks > Configurar notificación > Guardar configuración).
    // mercadopago:diagnose (GET /v1/payment_methods) nunca necesita este
    // valor, solo access_token — la disponibilidad del webhook es un
    // chequeo aparte, y ProviderGuard tampoco lo exige al arrancar (sección
    // 13: solo cuando el webhook esté realmente configurado/activo).
    'mercadopago' => [
        'base_url' => env('MERCADOPAGO_BASE_URL', 'https://api.mercadopago.com'),
        'access_token' => env('MERCADOPAGO_ACCESS_TOKEN'),
        // Public key: NO es secreta (documentada para uso en el navegador,
        // Card Payment Brick / mp.yape) — se guarda igual para que una
        // futura capa de checkout la lea de un canal seguro del backend
        // (no del Access Token). El backend en sí no la usa en ninguna
        // llamada HTTP propia todavía (sin frontend esta ronda).
        'public_key' => env('MERCADOPAGO_PUBLIC_KEY'),
        'webhook_secret' => env('MERCADOPAGO_WEBHOOK_SECRET'),
        // WEBHOOK ENABLEMENT (ronda de hardening distribuido): antes,
        // webhook_secret era condicionalmente obligatorio de forma
        // IMPLÍCITA ("cuando el webhook esté configurado/activo" — sin que
        // ningún flag lo declarara). Ahora es explícito: en false (default
        // seguro — nada asume que el webhook ya está configurado en el
        // panel de Mercado Pago), MercadoPagoWebhookController rechaza
        // CUALQUIER notificación entrante sin importar si trae una firma
        // válida, y ProviderGuard NO exige webhook_secret. En true (y
        // Mercado Pago habilitado), ProviderGuard SÍ exige webhook_secret
        // no vacío al arrancar — fail closed, nunca "omitido en silencio"
        // (ver AppServiceProvider).
        'webhooks_enabled' => env('MERCADOPAGO_WEBHOOKS_ENABLED', false),
        // Sin default hardcodeado a propósito (antes tenía el App ID real
        // como fallback — corregido: un Application ID real no debe vivir
        // como valor por defecto en el repositorio, aunque no sea secreto;
        // ver .env.example). Vinculado en
        // MercadoPagoPaymentProvider::verifyWebhook() contra el
        // `application_id` raíz del webhook.
        'application_id' => env('MERCADOPAGO_APPLICATION_ID'),
        // collector_id/user_id del vendedor esperado (numérico, no
        // secreto) — se valida contra el `user_id` raíz del webhook y el
        // `collector_id` de GET /v1/payments/{id} cuando esté configurado.
        // Sin este valor, ese chequeo puntual se omite en desarrollo — pero
        // ProviderGuard lo EXIGE cuando PAYMENTS_ENABLED=true y
        // PAYMENT_PROVIDER=mercadopago (ver AppServiceProvider).
        'expected_collector_id' => env('MERCADOPAGO_EXPECTED_COLLECTOR_ID'),
        // CORREGIDO (ronda de hardening final): antes tenía un default
        // silencioso `(bool) env(..., false)` — TEST se asumía sin que
        // nadie lo hubiera decidido explícitamente. Ahora se guarda el
        // valor CRUDO (string 'true'/'false' o null si no está definido) —
        // ProviderGuard::requireConfig() exige que no esté vacío cuando
        // Mercado Pago está habilitado (AppServiceProvider), y
        // MercadoPagoPaymentProvider::parseExpectedLiveMode() lo interpreta
        // de forma robusta en el único punto donde se usa. NUNCA se infiere
        // del prefijo de la credencial (ver CLAUDE.md) — interruptor
        // explícito que un humano decide a mano. Se valida contra
        // 'live_mode' (siempre presente en la raíz del webhook, confirmado
        // para cualquier tópico).
        'expected_live_mode' => env('MERCADOPAGO_EXPECTED_LIVE_MODE'),

        // Umbrales de MercadoPagoWebhookRecoveryService — nunca
        // hardcodeados en el servicio/comando, siempre configurables sin
        // tocar código (ver app/Console/Commands/MercadoPagoReconcile.php).
        'recovery' => [
            // payment_webhooks en 'received' más viejos que esto → reencolados.
            'stale_received_minutes' => (int) env('MERCADOPAGO_RECOVERY_STALE_RECEIVED_MINUTES', 15),
            // payment_orders no-terminales (pending) más viejas que esto → reconciliadas directamente.
            'stuck_order_minutes' => (int) env('MERCADOPAGO_RECOVERY_STUCK_ORDER_MINUTES', 30),
            // Ventana de reconciliación acotada para pagos YA 'paid' — detecta
            // un refund cuyo webhook nunca llegó, sin escanear para siempre:
            // solo se revisan pagos pagados entre hace `paid_lookback_min_age_minutes`
            // y hace `paid_lookback_days`. Default 90: Mercado Pago permite
            // reembolsos dentro de un horizonte de 90 días — un lookback más
            // corto dejaría refunds legítimos fuera de la única red de
            // seguridad local (ver docblock de
            // MercadoPagoWebhookRecoveryService sobre por qué esto sigue
            // siendo una consulta acotada por rango con índice, no un table
            // scan, incluso a 90 días).
            'paid_lookback_days' => (int) env('MERCADOPAGO_RECOVERY_PAID_LOOKBACK_DAYS', 90),
            'paid_lookback_min_age_minutes' => (int) env('MERCADOPAGO_RECOVERY_PAID_LOOKBACK_MIN_AGE_MINUTES', 60),
            // Tope de reintentos del propio RECOVERY (distinto de $tries del
            // job) antes de dejar de reencolar un payment_webhooks 'failed'
            // y caer a 'review' para revisión humana — evita un loop
            // received→failed→received infinito.
            'max_recovery_attempts' => (int) env('MERCADOPAGO_RECOVERY_MAX_ATTEMPTS', 3),
            // Tamaño de lote por barrido — evita cargar miles de filas en
            // memoria si el atraso creciera mucho.
            'batch_size' => (int) env('MERCADOPAGO_RECOVERY_BATCH_SIZE', 200),

            // UNKNOWN PAYMENT RECOVERY (ronda de hardening distribuido):
            // presupuesto de la búsqueda DIRIGIDA por external_reference
            // para intentos con submission_status='uncertain' — ver
            // MercadoPagoPaymentReconciliationService::reconcileUncertainSubmission().
            // Distinto de max_recovery_attempts (ese es el budget del
            // reencolado de payment_webhooks 'failed', no de esta búsqueda).
            //
            // uncertain_search_min_age_minutes: no tiene sentido buscar
            // segundos después del intento — le da tiempo a Mercado Pago de
            // indexar el pago si de verdad se creó, y evita gastar el
            // budget de reintentos antes de que haya pasado suficiente
            // tiempo. Solo aplica al barrido en LOTE
            // (MercadoPagoWebhookRecoveryService); el gate síncrono dentro
            // de createPaymentAttempt() (sección TOKEN + IDEMPOTENCY
            // INVARIANT) SIEMPRE busca de inmediato, sin importar la edad —
            // ahí el usuario está esperando una respuesta, no tiene sentido
            // hacerlo esperar más solo por este umbral.
            'uncertain_search_min_age_minutes' => (int) env('MERCADOPAGO_RECOVERY_UNCERTAIN_MIN_AGE_MINUTES', 5),
            // Tope de intentos de BÚSQUEDA (no de creación de pago) antes
            // de caer a 'review' sin más reintentos automáticos — mismo
            // espíritu que max_recovery_attempts pero para este presupuesto
            // separado (ver migración 2026_09_01_000006,
            // payment_orders.recovery_attempts).
            'uncertain_search_max_attempts' => (int) env('MERCADOPAGO_RECOVERY_UNCERTAIN_MAX_ATTEMPTS', 5),
        ],
    ],
];
