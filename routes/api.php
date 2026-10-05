<?php

use App\Http\Controllers\JaasWebhookController;
use App\Http\Controllers\MercadoPagoWebhookController;
use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Webhook de WhatsApp Cloud API (Meta) — diseñado pero INERTE hasta que
// existan credenciales reales; ver App\Http\Controllers\WhatsAppWebhookController
// y docs/whatsapp-architecture.md. Sin META_WHATSAPP_WEBHOOK_VERIFY_TOKEN /
// META_WHATSAPP_APP_SECRET configurados, ambos métodos rechazan todo.
// throttle:120,1 generoso a propósito: Meta puede entregar ráfagas de
// estados (sent/delivered/read) para muchos mensajes a la vez.
Route::prefix('webhooks/whatsapp')->middleware('throttle:120,1')->group(function () {
    Route::get('/', [WhatsAppWebhookController::class, 'verify'])->name('webhooks.whatsapp.verify');
    Route::post('/', [WhatsAppWebhookController::class, 'handle'])->name('webhooks.whatsapp.handle');
});

// Webhook de Mercado Pago (Orders API) — diseñado pero INERTE hasta que
// MERCADOPAGO_WEBHOOK_SECRET esté configurado (verifyWebhook() rechaza todo
// sin él, ver MercadoPagoPaymentProvider). Sin auth de sesión ni excepción
// de CSRF: esta ruta ya vive fuera del grupo 'web' por estar en api.php.
// throttle generoso a propósito (sección 11 del encargo original): Mercado
// Pago reintenta notificaciones legítimamente, y un límite agresivo aquí
// terminaría descartando reintentos reales, no abuso.
//
// withoutMiddleware(ThrottleRequests::api) — hallazgo confirmado con
// `php artisan route:list -vv --path=webhooks/mercadopago` (ronda de
// hardening): RouteServiceProvider aplica 'throttle:api' (60/min por IP,
// ver configureRateLimiting()) a TODO routes/api.php antes de que corra el
// 'throttle:120,1' de este grupo — el límite EFECTIVO real era 60/min, más
// estricto que el pensado, pudiendo descartar reintentos legítimos de
// Mercado Pago. Se quita SOLO en esta ruta, sin tocar el throttle global de
// la API para el resto de endpoints.
Route::prefix('webhooks/mercadopago')->middleware('throttle:120,1')->group(function () {
    Route::post('/', [MercadoPagoWebhookController::class, 'handle'])
        ->name('webhooks.mercadopago.handle')
        ->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class.':api');
});

// Presencia de JaaS (PARTICIPANT_JOINED / PARTICIPANT_LEFT). SOLO guarda
// evidencia en lesson_presence_events: no cambia estados de clase, créditos ni
// liquidación (las reglas de asistencia no están definidas). Desactivado por
// defecto (JAAS_WEBHOOKS_ENABLED=false → 404) y autenticado con la firma
// X-Jaas-Signature (JAAS_WEBHOOK_SIGNING_SECRET) y/o un header Authorization
// opcional (JAAS_WEBHOOK_AUTH_TOKEN); ver config/jaas.php. Mismo motivo que el webhook de Mercado Pago para
// quitar el throttle 'api': los reintentos legítimos de JaaS no deben
// descartarse por el límite global de 60/min.
Route::post('webhooks/jaas', [JaasWebhookController::class, 'handle'])
    ->middleware('throttle:120,1')
    ->name('webhooks.jaas.handle')
    ->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class.':api');
