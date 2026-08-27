<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-18 — Soft delete para `students`.
 *
 * StudentController::destroy() hacia un borrado fisico. Con historial academico
 * eso significaba una excepcion cruda de FK en MySQL (classes.student_id es
 * RESTRICT) o, en SQLite, la destruccion de las clases del alumno.
 *
 * El soft delete resuelve las dos cosas a la vez: la fila sobrevive, asi que
 * las clases que la referencian siguen siendo coherentes y el ledger conserva
 * su respaldo; y el alumno desaparece de los listados del padre sin necesidad
 * de filtros manuales.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('students', 'deleted_at')) {
            return;
        }

        Schema::table('students', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('students', 'deleted_at')) {
            return;
        }

        Schema::table('students', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
