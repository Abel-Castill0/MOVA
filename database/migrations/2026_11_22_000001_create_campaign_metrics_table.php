<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contadores diarios de la campaña física con QR (/profe, /familias): visitas, reproducciones y clics en «Empieza ahora».
 * Solo agregados por día (hora de Lima): ni IP, ni agente, ni identificador de persona.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_metrics', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->string('campaign', 32);
            $table->string('event', 16);
            $table->unsignedInteger('count')->default(0);
            $table->timestamps();
            $table->unique(['day', 'campaign', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_metrics');
    }
};
