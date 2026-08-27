<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Representa UN intento de cobro automático (Culqi/otro PSP) para UNA
// RechargeRequest ya existente — relación 1:1. RechargeRequest sigue siendo
// la entidad financiera (paquete/créditos/monto ya congelados ahí desde
// config/credits.php); PaymentOrder solo agrega lo específico del
// proveedor externo: su id remoto, su estado de cobro, su expiración.
// amount_minor es una FOTOGRAFÍA propia en centavos — no se recalcula del
// paquete si config/credits.php cambia después de crear la orden.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recharge_request_id')->unique()->constrained()->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('provider_order_id', 191)->nullable();
            $table->enum('status', ['created', 'pending', 'paid', 'failed', 'expired', 'cancelled'])->default('created');
            $table->unsignedInteger('amount_minor');
            $table->string('currency', 3)->default('PEN');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_order_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_orders');
    }
};
