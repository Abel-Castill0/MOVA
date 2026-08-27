<?php

namespace Tests\Feature;

use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * N1 (docs/MOVA_AUDIT_PHASE0.md, sección Q) — `Subject::firstOrCreateByName()`
 * era el único punto de unicidad del sistema sin el patrón try/catch +
 * refetch usado en todos los demás (`idempotency_key`,
 * `operation_number_normalized`, `phone_verified_normalized`). Bajo una
 * carrera real (dos requests creando el mismo nombre de materia nuevo a la
 * vez), el segundo caería con un `UniqueConstraintViolationException` sin
 * capturar → 500 crudo, en vez de recuperar la fila que el primero acaba de
 * crear.
 *
 * SQLite en test es de una sola conexión (no permite una carrera real de dos
 * procesos), así que la carrera se simula insertando la fila "ganadora" a
 * mano ANTES de llamar a firstOrCreateByName con el mismo nombre — desde el
 * punto de vista del método, el efecto observable (SELECT no encuentra nada
 * propio, INSERT choca con una fila que apareció mientras tanto) es idéntico.
 */
class SubjectRaceConditionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * SQLite en test es de una sola conexión — no permite reproducir la
     * ventana exacta de la carrera (dos procesos reales entre el SELECT y el
     * INSERT), la misma limitación honesta que ya documenta
     * FinancialConcurrencyTest para el resto del sistema financiero. Lo que
     * SÍ se puede probar aquí, con evidencia real: (1) llamar al método dos
     * veces con nombres que normalizan igual nunca crea una segunda fila
     * (idempotencia real del camino común, el SELECT-primero); (2) el UNIQUE
     * de base de datos que respalda el catch existe de verdad, no es
     * solamente una suposición del código PHP (test siguiente). La garantía
     * de que el catch en sí se ejecuta correctamente bajo una colisión real
     * queda respaldada por (2) + la revisión de código, no por una prueba
     * dinámica de la ventana exacta.
     */
    public function test_calling_it_twice_with_equivalent_names_never_creates_a_second_row(): void
    {
        $first = Subject::firstOrCreateByName('Cálculo III');
        $second = Subject::firstOrCreateByName('cálculo   III'); // mismo nombre, distinto espaciado/capitalización

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Subject::where('normalized_name', $first->normalized_name)->count());
    }

    public function test_the_unique_constraint_itself_is_real_at_the_database_level(): void
    {
        Subject::create(['name' => 'Física I', 'level' => 'universidad']);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        // Mismo texto normalizable a "fisica i" — Subject::booted() recalcula
        // normalized_name desde `name` en cada save (isDirty('name') es
        // siempre true en un create()), así que no basta con pasar
        // normalized_name explícito con un `name` distinto: hay que usar un
        // `name` que normalice al mismo valor para provocar la colisión real
        // que confirma que la protección es el índice UNIQUE de BD, no solo
        // la lógica de la aplicación — coherente con el resto del sistema
        // financiero, donde el UNIQUE es la garantía real.
        Subject::create(['name' => 'Física I', 'level' => 'universidad']);
    }

    public function test_a_genuinely_new_subject_name_is_still_created_normally(): void
    {
        $subject = Subject::firstOrCreateByName('Biología Molecular');

        $this->assertNotNull($subject->id);
        $this->assertSame('Biología Molecular', $subject->name);
        $this->assertSame(1, Subject::count());
    }
}
