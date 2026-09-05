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
$profile = App\Models\User::where('email','profesor@mova.test')->firstOrFail()->teacherProfile;
$recharge = App\Models\RechargeRequest::firstOrCreate(['operation_number'=>'PHASE2B-QA-REVERSE'], [
    'teacher_profile_id'=>$profile->id, 'package_name'=>'Baseline QA', 'credits'=>5,
    'amount_pen'=>5, 'payment_method'=>'yape', 'status'=>'pending',
]);
if ($recharge->status === 'pending') app(App\Services\RechargeApprovalService::class)->credit($recharge, $admin->id);
echo "Stabilization fixtures ready\n";
