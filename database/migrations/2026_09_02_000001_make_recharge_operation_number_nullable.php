<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P1 (MOVA Yape Checkout Pre-Card Hardening).
 *
 * SEMÁNTICA (obligatoria, no dejar nulls sin significado):
 *
 *     recharge_requests.operation_number = NULL  ⟺  payment_method='mercadopago'
 *                                                    (checkout automático —
 *                                                    la identidad del pago
 *                                                    vive en
 *                                                    PaymentOrder.provider_order_id,
 *                                                    nunca aquí)
 *     recharge_requests.operation_number = <valor> ⟺  flujo manual
 *                                                      (yape/transfer/legacy)
 *                                                      — número que el
 *                                                      profesor escribió y
 *                                                      un admin revisa
 *
 * Hasta ahora la columna era NOT NULL + UNIQUE por (payment_method,
 * operation_number_normalized) porque solo existía el flujo manual, donde
 * el número de operación es un dato real de negocio. El checkout
 * automático (CreditCheckoutController::store()) no tiene ningún número de
 * operación que el profesor escriba — generaba un UUID placeholder
 * ('MP-...') únicamente para satisfacer esta restricción heredada, sin
 * significado de negocio ni de auditoría. Ese placeholder nunca se
 * mostraba al profesor y no es lo que un operador esperaría ver si buscara
 * "el número de operación" de un pago Mercado Pago.
 *
 * Riesgo verificado antes de decidir: ningún consumidor directo de
 * `operation_number` (RechargeRejectedNotification,
 * NewRechargeRequestNotification, ConcurrencyProbe) se dispara para
 * payment_method=mercadopago — ambas notificaciones solo se envían desde
 * el flujo manual (CreditController::storeRecharge()) y desde
 * Admin\RechargeController::reject(), que RechargeRequestPolicy::reject()
 * ya bloquea para recargas mercadopago (ver P0 de esta misma ronda). El
 * índice único compuesto (payment_method, operation_number_normalized) no
 * necesita tocarse: tanto MySQL como SQLite excluyen NULL de la
 * comprobación de unicidad, así que múltiples recargas mercadopago con
 * operation_number_normalized=NULL conviven sin colisión.
 *
 * Alternativa descartada — una segunda columna tipo
 * `provider_reference`/`settlement_mode`: MOVA ya tiene un discriminador
 * (`payment_method`) y un lugar donde vive la identidad del proveedor
 * (`PaymentOrder.provider_order_id`, `PaymentOrder.externalReference()`) —
 * añadir una tercera columna redundante violaría el Dependency/Abstraction
 * Budget de CLAUDE.md sin resolver nada que estas dos ya no resuelvan.
 *
 * Técnica: SQL crudo en MySQL + ->change() en SQLite, el mismo patrón ya
 * usado en 2026_08_16_000003_make_class_events_actor_id_nullable.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE recharge_requests MODIFY operation_number VARCHAR(255) NULL');
            DB::statement('ALTER TABLE recharge_requests MODIFY operation_number_normalized VARCHAR(191) NULL');

            return;
        }

        Schema::table('recharge_requests', function (Blueprint $table) {
            $table->string('operation_number')->nullable()->change();
            $table->string('operation_number_normalized', 191)->nullable()->change();
        });
    }

    public function down(): void
    {
        $this->assertRollbackDoesNotDiscardProviderManagedRecharges();

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE recharge_requests MODIFY operation_number VARCHAR(255) NOT NULL');
            DB::statement('ALTER TABLE recharge_requests MODIFY operation_number_normalized VARCHAR(191) NOT NULL');

            return;
        }

        Schema::table('recharge_requests', function (Blueprint $table) {
            $table->string('operation_number')->nullable(false)->change();
            $table->string('operation_number_normalized', 191)->nullable(false)->change();
        });
    }

    /**
     * Volver a NOT NULL con recargas mercadopago ya existentes (operation_
     * number NULL por diseño) rompería su propia fila al aplicar el
     * rollback, o forzaría a inventarles un valor sin significado — lo
     * mismo que esta migración eliminó.
     */
    private function assertRollbackDoesNotDiscardProviderManagedRecharges(): void
    {
        $providerManaged = DB::table('recharge_requests')
            ->where('payment_method', 'mercadopago')
            ->whereNull('operation_number')
            ->count();

        if ($providerManaged > 0) {
            throw new RuntimeException(
                "Rollback abortado: existen {$providerManaged} recarga(s) mercadopago con operation_number NULL por diseño. "
                .'Revertir a NOT NULL las dejaría sin un valor válido.'
            );
        }
    }
};
