<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// C-P0-MINOR-CONSENT — evidencia append-only del consentimiento que el
// padre/apoderado otorga AL REGISTRAR a un alumno concreto. legal_acceptances
// no sirve: solo conoce (user, documento), no a qué menor se refiere.
//
// Sin backfill a propósito: los alumnos creados antes de esta tabla NO tienen
// consentimiento específico registrado y no se infiere uno de ninguna otra
// acción. Mismo criterio de borrado que legal_acceptances: el modelo impide
// update/delete; solo la eliminación física del titular (alumno o padre) en
// cascada la retira.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_data_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('statement_version', 32);
            $table->string('privacy_version', 32);
            $table->timestamp('accepted_at');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->index('student_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_data_consents');
    }
};
