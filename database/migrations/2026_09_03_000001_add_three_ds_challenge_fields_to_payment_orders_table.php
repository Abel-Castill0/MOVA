<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 3DS CHALLENGE (MOVA Card Payment Brick 3DS): Payments API devuelve, solo
// cuando `three_d_secure_mode=optional` desencadena un Challenge
// (status='pending', status_detail='pending_challenge'), un objeto
// `three_ds_info` con `external_resource_url` + `creq` — los datos que el
// frontend necesita para dibujar el iframe del banco (ver documentación
// oficial "Integrate 3DS", sección Payments API, verificada vía MCP).
//
// Estas columnas son puramente DE PRESENTACIÓN — nunca se leen para decidir
// si un pago está aprobado ni participan en la reconciliación server-to-
// server (esa verdad sigue viniendo siempre de status/status_detail vía
// MercadoPagoPaymentStatusMapper, ver su docblock). Se persisten porque el
// dato solo llega UNA vez, en la respuesta síncrona de creación — si no se
// guarda aquí, un reload de la pantalla de checkout durante el Challenge no
// tendría forma de volver a dibujar el iframe.
//
// creq es un token de un solo Challenge (~5 minutos de validez según la
// documentación oficial) — no es PAN/CVV ni un secreto de cuenta, pero
// tampoco necesita vivir para siempre: se limpia a null en cuanto la
// reconciliación resuelve el intento a un estado terminal (paid/failed/
// reversed — ver MercadoPagoPaymentReconciliationService).
//
// TEXT en vez de VARCHAR(N) para ambas columnas (ronda de hardening final):
// MOVA no controla ni el largo ni la forma exacta de una URL de ACS
// generada por un banco emisor — la documentación oficial no publica un
// límite. Un VARCHAR corto arriesgaría truncar silenciosamente un dato que
// de todas formas nunca se usa como texto acotado (solo se reenvía tal cual
// al frontend, ver extractChallengeFields()); TEXT elimina ese riesgo
// sin coste real en MySQL/SQLite para un campo nullable y de vida corta.
//
// `three_ds_expires_at` (ronda de semántica final) — columna DEDICADA,
// deliberadamente separada de `payment_orders.expires_at`. Esa otra columna
// ya existe desde la migración original de la tabla con un significado
// propio y más amplio: "su expiración" del INTENTO COMPLETO frente al
// proveedor (ver docblock de esa migración), emparejada con el propio
// `status` enum ('created'|'pending'|'paid'|'failed'|'expired'|'cancelled')
// — 'expired' se LEE como estado terminal en varios puntos del código
// (resolveAttemptRow(), applyPaid/applyFailed(), PaymentWebhookService,
// safeStatus()) aunque hoy nada todavía lo ESCRIBA; el diseño claramente lo
// reserva para un futuro barrido genérico de intentos vencidos. Si ese
// barrido llegara a implementarse y este Challenge hubiera reutilizado
// `expires_at`, un simple vencimiento de la ventana de 5 minutos del
// Challenge marcaría el PaymentOrder ENTERO como `status='expired'` —mucho
// más severo que lo que un Challenge vencido debe causar (solo ocultar el
// iframe, jamás bloquear un reintento ni tocar `status`). Columna separada
// para no arriesgar esa colisión de significado.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_orders', function (Blueprint $table) {
            $table->text('three_ds_challenge_url')->nullable()->after('provider_status_detail');
            $table->text('three_ds_creq')->nullable()->after('three_ds_challenge_url');
            $table->timestamp('three_ds_expires_at')->nullable()->after('three_ds_creq');
        });
    }

    public function down(): void
    {
        Schema::table('payment_orders', function (Blueprint $table) {
            $table->dropColumn(['three_ds_challenge_url', 'three_ds_creq', 'three_ds_expires_at']);
        });
    }
};
