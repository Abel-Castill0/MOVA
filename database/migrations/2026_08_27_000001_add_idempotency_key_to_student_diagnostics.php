<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BUG-2 (docs/MOVA_AUDIT_PHASE0.md, sección Q) — DiagnosticsController::store()
 * era la única mutación que crea datos reales (un StudentDiagnostic + una
 * ClassRequest) sin ningún guard de idempotencia, a diferencia de cada
 * mutación financiera del sistema. Un doble-click o un reintento de red
 * creaba dos diagnósticos y dos solicitudes de clase reales.
 *
 * `idempotency_key` es un hash determinístico del contenido real del
 * diagnóstico (padre + alumno + materia + texto + objetivo + urgencia), NO
 * un valor aleatorio ni acotado por tiempo: dos envíos con el mismo
 * contenido, para el mismo alumno, se tratan como el mismo diagnóstico
 * (idempotente); un envío con texto distinto genera una clave distinta y
 * crea un diagnóstico nuevo legítimamente. Mismo patrón que
 * `credit_transactions.idempotency_key`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_diagnostics', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable()->unique()->after('urgency');
        });
    }

    public function down(): void
    {
        Schema::table('student_diagnostics', function (Blueprint $table) {
            $table->dropColumn('idempotency_key');
        });
    }
};
