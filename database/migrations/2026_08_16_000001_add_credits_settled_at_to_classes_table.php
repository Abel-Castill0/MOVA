<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * C-1 Entrega 1 — infraestructura, sin comportamiento.
 *
 * `credits_settled_at` marca cuándo se liquidaron los créditos de una clase
 * (por consumo o por devolución). NO es la autoridad financiera: esa sigue
 * siendo el ledger `credit_transactions`, cuyo índice UNIQUE sobre
 * `idempotency_key` es lo que realmente impide una doble liquidación. Esta
 * columna es una caché derivada que permite acotar la consulta del futuro
 * comando de liquidación sin un subquery NOT EXISTS por cada candidata, y
 * hace testeable la invariante.
 *
 * Esta migración es PURAMENTE ESTRUCTURAL: no rellena la columna. El backfill
 * histórico va en su propia entrega, para poder desplegar el esquema nuevo
 * con la aplicación anterior sin romper nada.
 *
 * El índice acompaña a la columna en la misma migración a propósito: un
 * índice sobre una columna que todavía no existe no tiene sentido, y
 * separarlos abriría una ventana en la que un despliegue podría quedar a
 * medias.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->timestamp('credits_settled_at')->nullable()->after('status');
            $table->index(['status', 'credits_settled_at'], 'classes_status_settled_index');
        });
    }

    public function down(): void
    {
        $this->assertRollbackDoesNotDiscardSettlementHistory();

        Schema::table('classes', function (Blueprint $table) {
            $table->dropIndex('classes_status_settled_index');
            $table->dropColumn('credits_settled_at');
        });
    }

    /**
     * Eliminar la columna con datos borraría el vínculo entre cada clase y el
     * momento en que se liquidó, información que no puede reconstruirse desde
     * el esquema anterior. Mismo criterio que
     * harden_monetization_records::assertRollbackDoesNotDiscardFinancialHistory().
     */
    private function assertRollbackDoesNotDiscardSettlementHistory(): void
    {
        $settled = DB::table('classes')->whereNotNull('credits_settled_at')->count();

        if ($settled > 0) {
            throw new RuntimeException(
                'Rollback abortado: eliminar credits_settled_at descartaría el histórico de '
                ."liquidación de {$settled} clases. Requiere remediación aprobada."
            );
        }
    }
};
