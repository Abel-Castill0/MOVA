<?php

namespace Tests\Feature;

use App\Models\ClassOffer;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Encontrado durante el cierre de la familia P0 de exposición pública,
 * investigando exactamente lo que se pidió verificar (los consumidores
 * reales de `specific_rate` antes de clasificarlo) — no fue una búsqueda
 * nueva: ClassRequests/Create.vue::9 lee `offer.specific_rate`, y seguir
 * ese hilo llevó a ClassRequestController::create().
 *
 * A diferencia de /marketplace y /, esta ruta exige `role:parent` — no es
 * pública. Pero cualquier padre autenticado puede pasar `?offer_id=N`
 * (IDs secuenciales) para OFERTAS DE OTROS PROFESORES sin ninguna
 * relación previa, y antes recibía el `ClassOffer` completo con
 * `teacherProfile.user` sin proyección: yape_number, plin_number,
 * referral_code del profesor, y el `User` completo (email, phone,
 * phone_verification_code_hash, suspension_reason, etc.). Verificado con
 * json_encode() antes de corregir.
 */
class ClassRequestCreateOfferExposureTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unrelated_parent_viewing_an_offer_never_receives_teacher_internal_fields(): void
    {
        foreach (['teacher', 'parent'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $teacherUser = User::factory()->create(['email' => 'teacher@mova.pe', 'phone' => '999888777']);
        $teacherUser->assignRole('teacher');
        $profile = TeacherProfile::create([
            'user_id' => $teacherUser->id,
            'is_verified' => true,
            'hourly_rate' => 30,
            'yape_number' => '999888777',
            'plin_number' => '111222333',
        ]);
        $subject = Subject::create(['name' => 'Matemáticas', 'level' => 'secundaria']);
        $offer = ClassOffer::create([
            'teacher_profile_id' => $profile->id,
            'subject_id' => $subject->id,
            'title' => 'Clases de Matemáticas',
            'specific_rate' => 45,
            'is_active' => true,
        ]);

        // El padre NO tiene ninguna relación previa con este profesor —
        // solo conoce/adivina el offer_id.
        $parentUser = User::factory()->create();
        $parentUser->assignRole('parent');

        $response = $this->actingAs($parentUser)
            ->get(route('class-requests.create', ['offer_id' => $offer->id]));

        $response->assertOk();
        $offerPayload = $response->inertiaPage()['props']['offer'];

        // Negative contract: nada sensible del profesor.
        foreach (['yape_number', 'plin_number', 'referral_code', 'rejection_reason', 'reviewed_by', 'credits_available', 'credits_reserved'] as $field) {
            $this->assertArrayNotHasKey($field, $offerPayload['teacher_profile'], "offer.teacher_profile no debe exponer '{$field}'.");
        }
        foreach (['email', 'phone', 'phone_verification_code_hash', 'suspension_reason', 'whatsapp_opt_in_at', 'whatsapp_opt_out_at'] as $field) {
            $this->assertArrayNotHasKey($field, $offerPayload['teacher_profile']['user'], "offer.teacher_profile.user no debe exponer '{$field}'.");
        }

        // Positive contract: lo que Create.vue realmente necesita (verificado leyendo la plantilla).
        $this->assertSame($offer->id, $offerPayload['id']);
        $this->assertSame($subject->id, $offerPayload['subject_id']);
        $this->assertSame('45.00', $offerPayload['specific_rate']);
        $this->assertSame('Matemáticas', $offerPayload['subject']['name']);
        $this->assertSame('30.00', $offerPayload['teacher_profile']['hourly_rate']);
        $this->assertSame($teacherUser->name, $offerPayload['teacher_profile']['user']['name']);
    }
}
