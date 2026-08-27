<?php

namespace Tests\Feature;

use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Defensa en profundidad para TeacherProfile (ver el docblock de
 * $hidden en el modelo para el razonamiento completo, campo por campo).
 * No sustituye el allow-list explícito de columnas que
 * MarketplaceController::index()/TeacherPublicController::show() ya usan
 * — protege contra el día en que algún código nuevo serialice el modelo
 * completo sin pensarlo, el mismo tipo de error que ya ocurrió una vez
 * (ver TeacherPublicVisibilityTest.php y el P0 de
 * docs/MOVA_DESIGN_AUDIT_FINAL.md), aunque por una causa distinta.
 *
 * Prueba el modelo directamente (->toArray()), no un controller — esto es
 * una propiedad de TeacherProfile en sí misma, no de una ruta concreta.
 */
class TeacherProfileHiddenFieldsTest extends TestCase
{
    use RefreshDatabase;

    private TeacherProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->profile = TeacherProfile::create([
            'user_id' => $user->id,
            'bio' => 'Bio de prueba',
            'hourly_rate' => 25,
            'yape_number' => '999888777',
            'plin_number' => '111222333',
            'is_verified' => false,
            'credits_available' => 3,
            'credits_reserved' => 1,
            'completed_classes_count' => 7,
            'is_experienced' => true,
            'mentorship_slots_total' => 5,
            'mentorship_slots_taken' => 2,
            'rejected_at' => now(),
            'rejection_reason' => 'Motivo de prueba',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);
    }

    /**
     * @dataProvider hiddenFieldsProvider
     */
    public function test_field_is_absent_from_array_serialization(string $field): void
    {
        $this->assertArrayNotHasKey($field, $this->profile->fresh()->toArray());
    }

    public static function hiddenFieldsProvider(): array
    {
        return [
            'user_id' => ['user_id'],
            'credits_available' => ['credits_available'],
            'credits_reserved' => ['credits_reserved'],
            'completed_classes_count' => ['completed_classes_count'],
            'is_experienced' => ['is_experienced'],
            'mentorship_slots_taken' => ['mentorship_slots_taken'],
            'reviewed_at' => ['reviewed_at'],
        ];
    }

    /**
     * Los campos que SÍ tienen un lector real en el frontend (ver el
     * docblock de $hidden en el modelo) deben seguir presentes — este
     * test es lo que hace que el de arriba no sea "ocultar todo por si
     * acaso": prueba explícitamente que $hidden no se convirtió en una
     * lista demasiado agresiva.
     *
     * @dataProvider visibleFieldsProvider
     */
    public function test_field_that_a_real_consumer_needs_stays_visible(string $field): void
    {
        $this->assertArrayHasKey($field, $this->profile->fresh()->toArray());
    }

    public static function visibleFieldsProvider(): array
    {
        return [
            'yape_number — Teacher/Edit.vue precarga el form' => ['yape_number'],
            'plin_number — Teacher/Edit.vue precarga el form' => ['plin_number'],
            'mentorship_slots_total — Teacher/Edit.vue y Setup.vue' => ['mentorship_slots_total'],
            'rejected_at — Admin/PendingTeachers.vue lo muestra' => ['rejected_at'],
            'rejection_reason — Admin/PendingTeachers.vue lo muestra' => ['rejection_reason'],
            'reviewed_by — Admin/PendingTeachers.vue lo muestra (como relación cargada)' => ['reviewed_by'],
            'referral_code — TeacherPublicController lo expone condicionalmente' => ['referral_code'],
            'bio' => ['bio'],
            'hourly_rate' => ['hourly_rate'],
            'is_verified' => ['is_verified'],
        ];
    }

    /**
     * El caso concreto que motivó este archivo: si `reviewedBy` (la
     * relación) está cargada, debe seguir viéndose bajo la clave
     * `reviewed_by` con la forma {id, name} que Admin/PendingTeachers.vue
     * espera — $hidden NO debe interferir con la relación aunque comparta
     * nombre de clave con el FK crudo.
     */
    public function test_the_loaded_reviewed_by_relation_still_serializes_correctly(): void
    {
        $reviewer = User::factory()->create(['name' => 'Admin de Prueba']);
        $this->profile->update(['reviewed_by' => $reviewer->id]);

        $array = $this->profile->fresh()->load('reviewedBy')->toArray();

        $this->assertArrayHasKey('reviewed_by', $array);
        $this->assertSame('Admin de Prueba', $array['reviewed_by']['name'] ?? null);
    }
}
