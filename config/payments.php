<?php

// Configuración del sistema de pagos automáticos (Culqi/otro PSP) — capa
// que se agrega ENCIMA del sistema de recargas manual existente
// (config/credits.php, RechargeRequest), no lo reemplaza. Mientras
// 'provider' sea 'fake', ningún profesor puede pagar de verdad por esta vía
// — solo existe para tests/desarrollo. 'culqi' (App\Payment\CulqiPaymentProvider,
// docs/payments-culqi.md) y 'mercadopago' son los proveedores reales; solo uno
// puede estar activo, y ambos exigen su configuración financiera al arrancar.
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
    //
    // 'culqi' vuelve a esta lista (2026-10-22) porque CulqiPaymentProvider ya está
    // implementado y probado; AppServiceProvider exige su configuración financiera
    // mínima (llaves + modo coherente) antes de habilitarlo, igual que Mercado Pago.
    'supported_providers' => ['mercadopago', 'culqi'],

    // Culqi — API V2 (https://apidocs.culqi.com/, spec OpenAPI apiculqi.yaml).
    // Cargos con token de tarjeta/Yape, devoluciones y eventos. Las llaves
    // viven SOLO en .env / gestor de secretos; el modo (test|live) lo decide
    // un humano en CULQI_ENV y App\Payment\Culqi\CulqiConfig lo cruza con el
    // prefijo de la llave (pk_test_/sk_test_ vs pk_live_/sk_live_) para abortar
    // el arranque ante cualquier mezcla (fail-closed).
    'culqi' => [
        'public_key' => env('CULQI_PUBLIC_KEY'),
        // CULQI_SECRET_KEY es el nombre que usa CulqiPanel ("Secret API Key");
        // CULQI_PRIVATE_KEY se conserva por compatibilidad con .env previos.
        'private_key' => env('CULQI_SECRET_KEY', env('CULQI_PRIVATE_KEY')),
        // Secreto en la URL del webhook (/api/webhooks/culqi/{secreto}). Culqi
        // NO firma sus notificaciones (la documentación oficial no describe
        // firma, HMAC ni cabecera de autenticación), así que este valor solo
        // filtra tráfico ajeno: la verdad de un pago SIEMPRE se consulta a la
        // API de Culqi con la llave secreta antes de acreditar.
        'webhook_secret' => env('CULQI_WEBHOOK_SECRET'),
        'webhooks_enabled' => env('CULQI_WEBHOOKS_ENABLED', false),
        'env' => env('CULQI_ENV', 'test'),
        'base_url' => env('CULQI_BASE_URL', 'https://api.culqi.com'),
        // Tokens (POST /v2/tokens, /v2/tokens/yape) se crean en otro host con la llave pública.
        'secure_url' => env('CULQI_SECURE_URL', 'https://secure.culqi.com'),
        // Cifrado híbrido AES-256-GCM + RSA-OAEP-SHA256 del payload (opcional,
        // ver App\Payment\Culqi\CulqiPayloadEncryptor). El ID y la llave PÚBLICA
        // RSA salen de CulqiPanel > Desarrollo > RSA.
        'rsa_id' => env('CULQI_RSA_ID'),
        'rsa_public_key' => env('CULQI_RSA_PUBLIC_KEY'),
        'encrypt_payloads' => env('CULQI_ENCRYPT_PAYLOADS', false),
        'connect_timeout' => (int) env('CULQI_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('CULQI_TIMEOUT', 15),
        // Tope de importe (céntimos) para operaciones de ESCRITURA de sandbox
        // (comando culqi:sandbox-smoke y servidor MCP). Nunca aplica a producción.
        'sandbox_max_amount_minor' => (int) env('CULQI_SANDBOX_MAX_AMOUNT_MINOR', 1000),
        // Prefijo del correo sintético de la sonda culqi:sandbox-smoke (se le añade un sufijo único por ejecución).
        'sandbox_payer_email_local' => env('CULQI_SANDBOX_PAYER_EMAIL_LOCAL', 'sandbox-smoke'),
        'recovery' => [
            // payment_orders pendientes/inciertas más viejas que esto se reconcilian contra la API.
            'stuck_order_minutes' => (int) env('CULQI_RECOVERY_STUCK_ORDER_MINUTES', 15),
            // Ventana (minutos) alrededor de la creación del intento para buscar un cargo incierto.
            'uncertain_search_window_minutes' => (int) env('CULQI_RECOVERY_UNCERTAIN_WINDOW_MINUTES', 60),
            'uncertain_search_max_attempts' => (int) env('CULQI_RECOVERY_UNCERTAIN_MAX_ATTEMPTS', 5),
            'batch_size' => (int) env('CULQI_RECOVERY_BATCH_SIZE', 100),
            // Un intento 'submitting' más reciente que esto se considera POST en vuelo (no incierto):
            // ni el doble clic ni el cron lo reconcilian ni gastan su presupuesto de búsquedas.
            'in_flight_grace_seconds' => (int) env('CULQI_RECOVERY_IN_FLIGHT_GRACE_SECONDS', 120),
            // Pagos ya 'paid' se re-verifican contra la API durante estos días (devoluciones/disputas sin webhook).
            'paid_lookback_days' => (int) env('CULQI_RECOVERY_PAID_LOOKBACK_DAYS', 14),
        ],
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

        // Allowlist de correos que pueden usar el checkout automático (QA/staging).
        // Vacía = sin restricción. Ver App\Support\CheckoutAllowlist.
        'checkout_allowlist' => env('MERCADOPAGO_CHECKOUT_ALLOWLIST'),

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
