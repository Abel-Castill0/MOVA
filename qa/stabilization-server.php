<?php
// Router exclusivo del gate E2E; nunca se carga desde public/index.php.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('local') || config('database.default') !== 'sqlite'
    || realpath(config('database.connections.sqlite.database')) !== realpath(__DIR__.'/../storage/logs/phase2b-e2e.sqlite')) {
    http_response_code(503); exit('QA target rejected');
}
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $asset = realpath(__DIR__.'/../public'.$path);
    if ($path !== '/' && $asset && is_file($asset) && str_starts_with($asset, realpath(__DIR__.'/../public').DIRECTORY_SEPARATOR)) return false;
    // Simula solamente la identidad que Socialite entregaría tras OAuth.
    // Se mantiene el controlador, las rutas, CSRF y las sesiones reales.
    $identity = (new Laravel\Socialite\Two\User)->map(['id'=>'qa-google','name'=>'Familia Google QA','email'=>'google-phase2b@mova.test']);
    $provider = Mockery::mock();
    $provider->shouldReceive('stateless')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($identity);
    Laravel\Socialite\Facades\Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request = Illuminate\Http\Request::capture());
    $response->send();
    $kernel->terminate($request, $response);
    return;
}
// Fixtures con identidad sintética; no corre contra la BD de desarrollo.
Illuminate\Support\Facades\Notification::fake();
$admin = App\Models\User::firstOrCreate(['email'=>'admin-phase2b@mova.test'], [
    'name'=>'Admin QA','password'=>Illuminate\Support\Facades\Hash::make('password123'),'email_verified_at'=>now(),
]);
$admin->assignRole('admin');
// P0-C: admin QA enrolado en MFA con un secret SINTÉTICO fijo (solo esta base
// QA); qa/lib/totp.mjs genera el código real para el challenge.
if ($admin->two_factor_confirmed_at === null) {
    $admin->forceFill(['two_factor_secret'=>'MOVAQAE2ETOTPSECRETBASE32ONLYAAA','two_factor_confirmed_at'=>now()])->save();
}
$profile = App\Models\User::where('email','profesor@mova.test')->firstOrFail()->teacherProfile;
$recharge = App\Models\RechargeRequest::firstOrCreate(['operation_number'=>'PHASE2B-QA-REVERSE'], [
    'teacher_profile_id'=>$profile->id, 'package_name'=>'Baseline QA', 'credits'=>5,
    'amount_pen'=>5, 'payment_method'=>'yape', 'status'=>'pending',
]);
if ($recharge->status === 'pending') app(App\Services\RechargeApprovalService::class)->credit($recharge, $admin->id);

// ── Fase 3B: incidencias operativas para el QA visual ────────────────────────
//
// Deterministas y creadas por clave fija, de modo que cada `prepare` produzca
// exactamente los mismos estados. Cubren lo que la pantalla debe saber pintar:
// crítica cerrable, crítica NO cerrable, aviso, cerrada con motivo, contexto de
// varios campos y contexto vacío.
//
// Se escriben directamente y no vía OperationalAlertService porque el servicio
// notifica a los admins: aquí solo se quiere el estado en pantalla, no el envío.
$alertFixtures = [
    [
        'alert_key' => 'payment_webhook:9001:failed',
        'type' => App\Models\OperationalAlert::TYPE_RECONCILIATION_FAILURE,
        'severity' => App\Models\OperationalAlert::SEVERITY_CRITICAL,
        'title' => 'Webhook de Mercado Pago agotó sus reintentos',
        'message' => 'Una notificación de pago no pudo procesarse tras 5 intentos. El pago que describe puede no estar reflejado en MOVA.',
        // Incluye una clave NO permitida a propósito: el QA visual debe
        // demostrar que la allowlist la deja fuera de la pantalla.
        'context' => ['PaymentWebhook' => 9001, 'Error' => 'cURL error 28 https://api.mercadopago.com?access_token=APP_USR-fixture'],
        'occurrences' => 3,
    ],
    [
        'alert_key' => 'ledger:anomaly',
        'type' => App\Models\OperationalAlert::TYPE_LEDGER_ANOMALY,
        'severity' => App\Models\OperationalAlert::SEVERITY_CRITICAL,
        'title' => 'El ledger de créditos no cuadra',
        'message' => 'La reconciliación encontró movimientos de crédito que no explican los saldos almacenados.',
        'context' => ['Lecciones examinadas' => 128, 'Lecciones anómalas' => 2, 'Profesores descuadrados' => 1],
        'occurrences' => 1,
    ],
    [
        'alert_key' => 'lesson:9002:needs_admin_review',
        'type' => App\Models\OperationalAlert::TYPE_LESSON_NEEDS_REVIEW,
        'severity' => App\Models\OperationalAlert::SEVERITY_WARNING,
        'title' => 'Una clase quedó esperando decisión',
        'message' => 'La liquidación automática no pudo cerrarla y necesita que un administrador decida.',
        'context' => ['Clase' => 9002, 'Motivo' => 'Sin confirmación del padre'],
        'occurrences' => 1,
    ],
    [
        'alert_key' => 'health:APP_DEBUG_IN_PRODUCTION',
        'type' => App\Models\OperationalAlert::TYPE_HEALTH_CHECK,
        'severity' => App\Models\OperationalAlert::SEVERITY_WARNING,
        'title' => 'Configuración que conviene revisar',
        'message' => 'El chequeo horario encontró un ajuste de entorno que no corresponde a producción.',
        'context' => [],
        'occurrences' => 7,
        // Se deja CERRADA a propósito: sin ninguna incidencia de este tipo
        // abierta, la capacidad «Configuración y entorno» cae en `unknown`, y
        // el QA visual necesita ver ese estado —el que nunca debe leerse como
        // sano— junto a los `attention` del resto.
        'resolved_at' => now()->subHours(6),
    ],
    [
        'alert_key' => 'payment_webhook:9003:review',
        'type' => App\Models\OperationalAlert::TYPE_WEBHOOK_REVIEW,
        'severity' => App\Models\OperationalAlert::SEVERITY_CRITICAL,
        'title' => 'Notificación de pago marcada para revisión',
        'message' => 'MOVA no aplicó ninguna acción financiera automática sobre este aviso.',
        'context' => ['Motivo' => 'Estado ambiguo del proveedor', 'PaymentWebhook' => 9003],
        'occurrences' => 1,
        'resolved_at' => now()->subHours(2),
        'resolved_by' => $admin->id,
        'resolved_by_name' => $admin->name,
        'resolution_note' => 'Verificado en el panel de Mercado Pago: el pago nunca se acreditó y el comprobante fue anulado por el pagador.',
    ],
];

foreach ($alertFixtures as $fixture) {
    App\Models\OperationalAlert::updateOrCreate(
        ['alert_key' => $fixture['alert_key']],
        $fixture + [
            'first_detected_at' => now()->subDay(),
            'last_detected_at' => now()->subMinutes(35),
        ]
    );
}

echo "Stabilization fixtures ready\n";
