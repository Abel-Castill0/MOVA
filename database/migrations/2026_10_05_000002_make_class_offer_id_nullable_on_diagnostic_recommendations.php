<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Las recomendaciones se basan ahora en el perfil docente, no en una
     * ClassOffer (ya no se crean ofertas nuevas). Las filas históricas
     * conservan su `class_offer_id`; las nuevas lo dejan en NULL.
     *
     * Forward-fix: no se reescribe 2026_06_26_200001. El índice único
     * (diagnóstico, oferta) se conserva — con NULL no restringe nada — y se
     * añade un índice (diagnóstico, profesor) NO único: puede haber filas
     * históricas con dos ofertas del mismo profesor, y la unicidad por
     * profesor la garantiza el servicio.
     */
    public function up(): void
    {
        Schema::table('diagnostic_recommendations', function (Blueprint $table) {
            $table->unsignedBigInteger('class_offer_id')->nullable()->change();
            $table->index(['student_diagnostic_id', 'teacher_profile_id'], 'diag_rec_diag_teacher_idx');
        });
    }

    public function down(): void
    {
        Schema::table('diagnostic_recommendations', function (Blueprint $table) {
            $table->dropIndex('diag_rec_diag_teacher_idx');
        });
        // NOT NULL no se restaura: borraría o invalidaría filas nuevas sin oferta.
    }
};
