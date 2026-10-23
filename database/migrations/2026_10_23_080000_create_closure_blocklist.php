<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lista de bloqueo de re-registro (solo hashes HMAC, nunca el correo ni el teléfono): evita que una cuenta
// suspendida o con deuda use el cierre automático para volver a registrarse limpia. Forward-only.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('closure_blocklist', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 8);
            $table->char('hash', 64);
            $table->string('reason', 32);
            $table->timestamp('created_at')->nullable();
            $table->unique(['kind', 'hash']);
        });
    }

    public function down(): void
    {
        // Forward-only: la evidencia antifraude no se destruye al revertir una versión.
    }
};
