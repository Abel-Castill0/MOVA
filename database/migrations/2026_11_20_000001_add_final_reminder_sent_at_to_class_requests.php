<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca del último recordatorio a profesores (24 h antes de la franja pedida, o del vencimiento de un acompañamiento).
 * Aditiva y nula: las solicitudes existentes no cambian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_requests', function (Blueprint $table) {
            $table->timestamp('final_reminder_sent_at')->nullable()->after('request_reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('class_requests', function (Blueprint $table) {
            $table->dropColumn('final_reminder_sent_at');
        });
    }
};
