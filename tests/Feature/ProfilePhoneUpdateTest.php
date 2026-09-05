<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * H-12 — Edición segura del teléfono.
 *
 * El hallazgo (docs/MOVA_SYSTEM_MAP.md H-12, C-15): MOVA decía "Número de
 * teléfono inválido. Por favor actualiza tu perfil" y el perfil no aceptaba ese
 * campo. Como `phone` es opcional en el registro, quien lo escribiera mal
 * quedaba encerrado: sin teléfono verificado un profesor no cobra su bono de 5
 * créditos ni recibe avisos.
 *
 * Añadir un input no era suficiente. `phone_verified_at`,
 * `phone_verified_normalized` y el consentimiento de WhatsApp describen un
 * NÚMERO, no al usuario: si el número cambia, todo eso deja de ser cierto.
 */
class ProfilePhoneUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'teacher', 'parent'] as $role) {
            Role::findOrCreate($role);
        }
    }

    private function payload(User $user, array $overrides = []): array
    {
        return array_merge([
            'name' => $user->name,
            'email' => $user->email,
        ], $overrides);
    }

    // ── Caso base ────────────────────────────────────────────────────────

    public function test_a_user_without_a_phone_can_add_one(): void
    {
        $user = User::factory()->create(['phone' => null]);

        $this->actingAs($user)
            ->patch(route('profile.update'), $this->payload($user, ['phone' => '987654321']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertSame('987654321', $user->fresh()->phone);
        $this->assertNull($user->fresh()->phone_verified_at, 'Un teléfono nuevo nunca nace verificado.');
    }

    public function test_a_user_with_a_mistyped_phone_can_finally_correct_it(): void
    {
        // Exactamente el callejón sin salida del hallazgo.
        $user = User::factory()->create(['phone' => '12345']);

        $this->actingAs($user)
            ->patch(route('profile.update'), $this->payload($user, ['phone' => '987654321']))
            ->assertSessionHasNoErrors();

        $this->assertSame('987654321', $user->fresh()->phone);
    }

    // ── Formato ──────────────────────────────────────────────────────────

    /** @dataProvider invalidPhones */
    public function test_an_unnormalizable_phone_is_rejected(string $phone): void
    {
        $user = User::factory()->create(['phone' => '987654321']);

        $this->actingAs($user)
            ->patch(route('profile.update'), $this->payload($user, ['phone' => $phone]))
            ->assertSessionHasErrors('phone');

        $this->assertSame('987654321', $user->fresh()->phone, 'El teléfono anterior no se pisa.');
    }

    public static function invalidPhones(): array
    {
        return [
            'demasiado corto' => ['12345'],
            'no empieza por 9' => ['123456789'],
            'letras' => ['celular'],
            'prefijo internacional incompleto' => ['+51'],
        ];
    }

    /** @dataProvider validPhones */
    public function test_accepted_formats_match_the_normalizer_used_everywhere_else(string $phone): void
    {
        $user = User::factory()->create(['phone' => null]);

        $this->actingAs($user)
            ->patch(route('profile.update'), $this->payload($user, ['phone' => $phone]))
            ->assertSessionHasNoErrors();
    }

    public static function validPhones(): array
    {
        return [
            'móvil peruano' => ['987654321'],
            'con prefijo y +' => ['+51987654321'],
            'con prefijo sin +' => ['51987654321'],
            'con espacios' => ['+51 987 654 321'],
        ];
    }

    public function test_the_phone_can_be_cleared(): void
    {
        $user = User::factory()->create(['phone' => '987654321']);

        $this->actingAs($user)
            ->patch(route('profile.update'), $this->payload($user, ['phone' => null]))
            ->assertSessionHasNoErrors();

        $this->assertNull($user->fresh()->phone);
    }

    // ── Invalidación de la verificación ──────────────────────────────────

    public function test_changing_a_verified_phone_invalidates_the_verification(): void
    {
        $user = User::factory()->create(['phone' => '987654321']);
        $user->forceFill([
            'phone_verified_at' => now(),
            'phone_verified_normalized' => '+51987654321',
            'whatsapp_opt_in_at' => now(),
        ])->save();

        $this->actingAs($user)
            ->patch(route('profile.update'), $this->payload($user, ['phone' => '912345678']))
            ->assertSessionHasNoErrors();

        $fresh = $user->fresh();
        $this->assertSame('912345678', $fresh->phone);
        $this->assertNull($fresh->phone_verified_at, 'Debe exigir nueva verificación.');
        $this->assertNull(
            $fresh->phone_verified_normalized,
            'Liberar la UNIQUE es obligatorio: si no, la cuenta seguiría reservando un número que ya no usa.'
        );
        $this->assertNull($fresh->whatsapp_opt_in_at, 'El consentimiento era para el número anterior.');
        $this->assertFalse($fresh->wantsWhatsAppNotifications());
    }

    public function test_a_pending_verification_code_is_discarded_when_the_phone_changes(): void
    {
        $user = User::factory()->create(['phone' => '987654321']);
        $user->forceFill([
            'phone_verification_code_hash' => Hash::make('123456'),
            'phone_verification_expires_at' => now()->addMinutes(10),
            'phone_verification_attempts' => 3,
        ])->save();

        $this->actingAs($user)->patch(route('profile.update'), $this->payload($user, ['phone' => '912345678']));

        $fresh = $user->fresh();
        $this->assertNull($fresh->phone_verification_code_hash, 'Un código enviado al número antiguo no debe servir para el nuevo.');
        $this->assertNull($fresh->phone_verification_expires_at);
        $this->assertSame(0, $fresh->phone_verification_attempts);
    }

    /**
     * Reescribir el mismo número en otro formato NO es un cambio de teléfono.
     * Cobrar una reverificación por añadir espacios sería un castigo absurdo.
     */
    public function test_rewriting_the_same_number_in_another_format_keeps_the_verification(): void
    {
        $user = User::factory()->create(['phone' => '987654321']);
        $user->forceFill([
            'phone_verified_at' => now(),
            'phone_verified_normalized' => '+51987654321',
            'whatsapp_opt_in_at' => now(),
        ])->save();

        $this->actingAs($user)
            ->patch(route('profile.update'), $this->payload($user, ['phone' => '+51 987 654 321']))
            ->assertSessionHasNoErrors();

        $fresh = $user->fresh();
        $this->assertNotNull($fresh->phone_verified_at, 'Es el mismo teléfono normalizado.');
        $this->assertSame('+51987654321', $fresh->phone_verified_normalized);
        $this->assertTrue($fresh->wantsWhatsAppNotifications());
    }

    public function test_updating_only_the_name_never_touches_the_phone_verification(): void
    {
        $user = User::factory()->create(['phone' => '987654321']);
        $user->forceFill([
            'phone_verified_at' => now(),
            'phone_verified_normalized' => '+51987654321',
        ])->save();

        $this->actingAs($user)
            ->patch(route('profile.update'), $this->payload($user, ['phone' => '987654321', 'name' => 'Nombre Nuevo']))
            ->assertSessionHasNoErrors();

        $fresh = $user->fresh();
        $this->assertSame('Nombre Nuevo', $fresh->name);
        $this->assertNotNull($fresh->phone_verified_at);
    }

    /**
     * Una baja de WhatsApp es una voluntad sobre el canal, no sobre un número.
     * Cambiar de teléfono no puede resucitar la suscripción en silencio.
     */
    public function test_a_whatsapp_opt_out_survives_a_phone_change(): void
    {
        $user = User::factory()->create(['phone' => '987654321']);
        $user->forceFill([
            'phone_verified_at' => now(),
            'phone_verified_normalized' => '+51987654321',
            'whatsapp_opt_out_at' => now(),
        ])->save();

        $this->actingAs($user)->patch(route('profile.update'), $this->payload($user, ['phone' => '912345678']));

        $fresh = $user->fresh();
        $this->assertNotNull($fresh->whatsapp_opt_out_at);
        $this->assertFalse($fresh->wantsWhatsAppNotifications());
    }

    // ── Secuestro de número ──────────────────────────────────────────────

    public function test_a_number_already_verified_by_another_account_cannot_be_taken(): void
    {
        $owner = User::factory()->create(['phone' => '987654321']);
        $owner->forceFill([
            'phone_verified_at' => now(),
            'phone_verified_normalized' => '+51987654321',
        ])->save();

        $attacker = User::factory()->create(['phone' => '911111111']);

        $this->actingAs($attacker)
            ->patch(route('profile.update'), $this->payload($attacker, ['phone' => '987654321']))
            ->assertSessionHasErrors('phone');

        $this->assertSame('911111111', $attacker->fresh()->phone);
    }

    public function test_the_hijack_check_compares_normalized_numbers_not_raw_text(): void
    {
        $owner = User::factory()->create(['phone' => '987654321']);
        $owner->forceFill([
            'phone_verified_at' => now(),
            'phone_verified_normalized' => '+51987654321',
        ])->save();

        $attacker = User::factory()->create(['phone' => '911111111']);

        // Mismo número, otro formato: debe bloquearse igual.
        $this->actingAs($attacker)
            ->patch(route('profile.update'), $this->payload($attacker, ['phone' => '+51 987 654 321']))
            ->assertSessionHasErrors('phone');
    }

    public function test_a_user_can_keep_their_own_verified_number(): void
    {
        $user = User::factory()->create(['phone' => '987654321']);
        $user->forceFill([
            'phone_verified_at' => now(),
            'phone_verified_normalized' => '+51987654321',
        ])->save();

        $this->actingAs($user)
            ->patch(route('profile.update'), $this->payload($user, ['phone' => '987654321']))
            ->assertSessionHasNoErrors();
    }

    // ── El bono no se duplica ────────────────────────────────────────────

    /**
     * Verificar un segundo número NO puede volver a abonar el bono de
     * bienvenida. La garantía es la clave de idempotencia
     * `teacher:{id}:welcome`, no una comprobación en el controller.
     */
    public function test_changing_and_reverifying_the_phone_never_grants_the_welcome_bonus_twice(): void
    {
        $teacher = User::factory()->create(['phone' => '987654321']);
        $teacher->assignRole('teacher');
        $profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            'hourly_rate' => 20,
            'credits_available' => 0,
        ]);

        // Primera verificación: cobra el bono.
        $this->verifyPhone($teacher, '987654321');
        $this->assertSame(5, $profile->fresh()->credits_available);

        // Cambia de número y vuelve a verificar.
        $this->actingAs($teacher)
            ->patch(route('profile.update'), $this->payload($teacher, ['phone' => '912345678']))
            ->assertSessionHasNoErrors();
        $this->assertNull($teacher->fresh()->phone_verified_at);

        $this->verifyPhone($teacher->fresh(), '912345678');

        $this->assertNotNull($teacher->fresh()->phone_verified_at, 'El segundo número sí queda verificado.');
        $this->assertSame(5, $profile->fresh()->credits_available, 'Pero el bono NO se abona dos veces.');
        $this->assertSame(
            1,
            CreditTransaction::where('teacher_profile_id', $profile->id)
                ->where('idempotency_key', "teacher:{$profile->id}:welcome")
                ->count()
        );
    }

    /**
     * Recorre el flujo real de verificación por OTP, sin WhatsApp: se fija el
     * hash del código directamente y se envía el código correcto.
     */
    private function verifyPhone(User $user, string $phone): void
    {
        $user->forceFill([
            'phone' => $phone,
            'phone_verification_code_hash' => Hash::make('123456'),
            'phone_verification_expires_at' => now()->addMinutes(10),
            'phone_verification_attempts' => 0,
        ])->save();

        $this->actingAs($user->fresh())
            ->post(route('phone.verification.verify'), ['code' => '123456'])
            ->assertSessionHasNoErrors();
    }
}
