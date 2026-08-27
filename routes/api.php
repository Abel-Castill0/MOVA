<?php

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
