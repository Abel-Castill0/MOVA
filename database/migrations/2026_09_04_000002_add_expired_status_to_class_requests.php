<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * §14 — Añade 'expired' al enum de `class_requests.status`.
 *
 * POR QUÉ HACE FALTA: una solicitud `open` que nadie acepta se quedaba abierta
 * PARA SIEMPRE (docs/MOVA_SYSTEM_MAP.md R-12). No existía ningún estado ni
 * ningún proceso que la cerrara: la bandeja del profesor acumulaba solicitudes
 * de hace meses y el contador `open_requests` del panel de admin dejaba de
 * significar nada.
 *
 * NO se elimina 'completed' en esta migración, aunque la auditoría confirmó que
 * es un estado MUERTO (nadie lo escribe ni lo lee, H-09). Quitar un valor de un
 * enum es una migración destructiva sobre datos históricos y no aporta nada a la
 * estabilización: se deja documentado como deuda, no se arrastra aquí.
 *
 * PATRÓN DUAL, como el resto de migraciones de enum del repo: Doctrine DBAL no
 * sabe introspeccionar "enum" en SQLite, así que `Blueprint::change()` no sirve.
 * MySQL usa ALTER MODIFY; SQLite reconstruye la columna.
 */
return new class extends Migration
{
    private const OLD_STATUSES = [
        'pending_parent_approval', 'open', 'accepted',
        'rejected', 'teacher_rejected', 'completed',
    ];

    private const NEW_STATUSES = [
        'pending_parent_approval', 'open', 'accepted',
        'rejected', 'teacher_rejected', 'completed', 'expired',
    ];

    public function up(): void
    {
        $this->applyStatuses(self::NEW_STATUSES);
    }

    /**
     * Revertir NO puede perder información en silencio.
     *
     * Si ya hay solicitudes en 'expired', estrechar el enum las convertiría en
     * un valor inválido (MySQL las truncaría a '' o al primer valor, SQLite
     * fallaría el CHECK). Se devuelven a 'open' primero, que es de donde
     * vinieron y el único estado desde el que se llega a 'expired'.
     *
     * CONSECUENCIA OPERATIVA EXPLÍCITA (revisión §8): ese remapeo REABRE esas
     * solicitudes. Volverán a aparecer en la bandeja de los profesores y a
     * contar como demanda viva en el panel de admin, y el barrido horario las
     * volverá a expirar en cuanto se reaplique la migración.
     *
     * No es un bug: es la pérdida de información inherente a estrechar un enum,
     * y las tres alternativas son peores — no hacer nada corrompe el dato en
     * silencio, y mapear a 'rejected' afirmaría un rechazo que nunca ocurrió.
     * Se documenta aquí para que un rollback sea una decisión informada y no
     * una sorpresa. Cero efecto financiero: una solicitud nunca tuvo créditos
     * reservados.
     */
    public function down(): void
    {
        DB::table('class_requests')->where('status', 'expired')->update(['status' => 'open']);

        $this->applyStatuses(self::OLD_STATUSES);
    }

    private function applyStatuses(array $statuses): void
    {
        if (DB::getDriverName() === 'mysql') {
            $list = implode(',', array_map(fn (string $s) => "'{$s}'", $statuses));

            DB::statement("ALTER TABLE `class_requests` MODIFY `status` ENUM({$list}) NOT NULL DEFAULT 'open'");

            return;
        }

        $this->rebuildStatusColumn($statuses);
    }

    /**
     * Mismo procedimiento que
     * 2026_08_27_000002_widen_class_requests_status_enum_for_sqlite.php: se
     * copia a propósito en vez de extraerlo a un helper compartido — una
     * migración debe seguir describiendo el esquema tal y como era cuando se
     * escribió, y acoplarla a código que puede cambiar después la volvería
     * irreproducible.
     */
    private function rebuildStatusColumn(array $allowedStatuses): void
    {
        Schema::table('class_requests', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });

        Schema::table('class_requests', function (Blueprint $table) use ($allowedStatuses) {
            $table->enum('status_tmp', $allowedStatuses)->default('open')->after('status');
        });

        DB::statement('UPDATE class_requests SET status_tmp = status');

        Schema::table('class_requests', function (Blueprint $table) {
            $table->dropColumn('status');
        });

        Schema::table('class_requests', function (Blueprint $table) {
            $table->renameColumn('status_tmp', 'status');
        });

        Schema::table('class_requests', function (Blueprint $table) {
            $table->index('status');
        });
    }
};
