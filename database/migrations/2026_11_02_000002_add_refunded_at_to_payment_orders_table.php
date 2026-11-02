<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_orders', function (Blueprint $table) {
            // Cargo devuelto completamente, distinto de una recarga revertida.
            // Puede haber más de un cargo confirmado por RechargeRequest.
            $table->timestamp('refunded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payment_orders', fn (Blueprint $table) => $table->dropColumn('refunded_at'));
    }
};
