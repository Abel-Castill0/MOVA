<?php

namespace Tests\Feature;

use App\Models\TeacherAvailabilitySlot;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Disponibilidad semanal central del profesor: autorización, ownership,
 * validación de días/horas/solapes/límites e idempotencia del guardado.
 */
class TeacherAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'teacher', 'parent'] as $role) {
            Role::findOrCreate($role);
        }
    }

    private function teacher(): array
    {
        $user = User::factory()->create();
        $user->assignRole('teacher');
        $profile = TeacherProfile::create([
            'user_id' => $user->id,
            'hourly_rate' => 25,
            'is_verified' => true,
            'bio' => 'Profesor de prueba',
        ]);

        return [$user, $profile];
    }

    private function saveSlots(User $user, array $slots)
    {
        return $this->actingAs($user)->put(route('teacher.availability.update'), ['slots' => $slots]);
    }

    private function slot(int $day, string $start, string $end): array
    {
        return ['day_of_week' => $day, 'start_time' => $start, 'end_time' => $end];
    }

    // ── Autorización ─────────────────────────────────────────────────────

    public function test_guests_are_redirected_to_login(): void
    {
        $this->put(route('teacher.availability.update'), ['slots' => []])->assertRedirect(route('login'));
    }

    public function test_a_parent_cannot_set_teacher_availability(): void
    {
        $parent = User::factory()->create();
        $parent->assignRole('parent');

        $this->saveSlots($parent, [$this->slot(1, '08:00', '10:00')])->assertForbidden();
        $this->assertSame(0, TeacherAvailabilitySlot::count());
    }

    public function test_a_suspended_teacher_cannot_change_availability(): void
    {
        [$user] = $this->teacher();
        $user->update(['suspended_at' => now()]);

        $this->saveSlots($user, [$this->slot(1, '08:00', '10:00')])->assertRedirect(route('suspended'));
        $this->assertSame(0, TeacherAvailabilitySlot::count());
    }

    public function test_a_teacher_edits_only_their_own_slots_and_a_client_id_is_ignored(): void
    {
        [$userA, $profileA] = $this->teacher();
        [, $profileB] = $this->teacher();
        TeacherAvailabilitySlot::create([
            'teacher_profile_id' => $profileB->id, 'day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '11:00',
        ]);

        // Un teacher_profile_id inyectado en el payload no cambia el dueño.
        $this->actingAs($userA)->put(route('teacher.availability.update'), [
            'teacher_profile_id' => $profileB->id,
            'slots' => [$this->slot(3, '10:00', '12:00')],
        ])->assertSessionHasNoErrors();

        $this->assertSame([3], $profileA->availabilitySlots()->pluck('day_of_week')->all());
        $this->assertSame([2], $profileB->availabilitySlots()->pluck('day_of_week')->all());
    }

    // ── Guardado correcto ────────────────────────────────────────────────

    public function test_valid_slots_are_saved_normalised_and_sorted(): void
    {
        [$user, $profile] = $this->teacher();

        $this->saveSlots($user, [
            $this->slot(3, '15:00', '18:00'),
            $this->slot(1, '08:00', '10:30'),
            $this->slot(1, '14:00', '16:00'),
        ])->assertRedirect(route('teacher.profile'))->assertSessionHasNoErrors();

        $saved = $profile->availabilitySlots()->get()->map(fn ($s) => [
            $s->day_of_week, substr($s->start_time, 0, 5), substr($s->end_time, 0, 5),
        ])->all();

        $this->assertSame([[1, '08:00', '10:30'], [1, '14:00', '16:00'], [3, '15:00', '18:00']], $saved);
    }

    public function test_saving_is_idempotent_and_replaces_previous_slots(): void
    {
        [$user, $profile] = $this->teacher();
        $payload = [$this->slot(1, '08:00', '10:00'), $this->slot(2, '08:00', '10:00')];

        $this->saveSlots($user, $payload)->assertSessionHasNoErrors();
        $this->saveSlots($user, $payload)->assertSessionHasNoErrors();
        $this->assertSame(2, $profile->availabilitySlots()->count());

        $this->saveSlots($user, [$this->slot(5, '09:00', '11:00')])->assertSessionHasNoErrors();
        $this->assertSame(1, $profile->availabilitySlots()->count());
    }

    public function test_an_empty_list_clears_availability(): void
    {
        [$user, $profile] = $this->teacher();
        $this->saveSlots($user, [$this->slot(1, '08:00', '10:00')]);

        $this->saveSlots($user, [])->assertSessionHasNoErrors();

        $this->assertSame(0, $profile->availabilitySlots()->count());
    }

    public function test_adjacent_slots_that_only_touch_are_valid(): void
    {
        [$user, $profile] = $this->teacher();

        $this->saveSlots($user, [$this->slot(1, '08:00', '10:00'), $this->slot(1, '10:00', '12:00')])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $profile->availabilitySlots()->count());
    }

    // ── Validación ───────────────────────────────────────────────────────

    public function test_invalid_day_and_time_formats_are_rejected(): void
    {
        [$user, $profile] = $this->teacher();

        foreach ([
            [$this->slot(7, '08:00', '10:00'), 'slots.0.day_of_week'],
            [$this->slot(-1, '08:00', '10:00'), 'slots.0.day_of_week'],
            [$this->slot(1, '8am', '10:00'), 'slots.0.start_time'],
            [$this->slot(1, '08:00', '25:00'), 'slots.0.end_time'],
            [$this->slot(1, '24:00', '23:59'), 'slots.0.start_time'],
        ] as [$slot, $errorKey]) {
            $this->saveSlots($user, [$slot])->assertSessionHasErrors($errorKey);
        }

        $this->assertSame(0, $profile->availabilitySlots()->count());
    }

    public function test_end_before_or_equal_to_start_and_too_short_slots_are_rejected(): void
    {
        [$user, $profile] = $this->teacher();

        $this->saveSlots($user, [$this->slot(1, '10:00', '08:00')])->assertSessionHasErrors('slots.0.end_time');
        $this->saveSlots($user, [$this->slot(1, '10:00', '10:00')])->assertSessionHasErrors('slots.0.end_time');
        // Cruzar medianoche no se admite en una sola franja.
        $this->saveSlots($user, [$this->slot(1, '22:00', '00:00')])->assertSessionHasErrors('slots.0.end_time');
        $this->saveSlots($user, [$this->slot(1, '10:00', '10:15')])->assertSessionHasErrors('slots.0.end_time');

        $this->assertSame(0, $profile->availabilitySlots()->count());
    }

    public function test_overlapping_slots_on_the_same_day_are_rejected_but_other_days_are_fine(): void
    {
        [$user, $profile] = $this->teacher();

        $this->saveSlots($user, [$this->slot(1, '08:00', '10:00'), $this->slot(1, '09:30', '11:00')])
            ->assertSessionHasErrors('slots.1.start_time');
        $this->saveSlots($user, [$this->slot(1, '08:00', '12:00'), $this->slot(1, '09:00', '10:00')])
            ->assertSessionHasErrors(); // contenida
        $this->saveSlots($user, [$this->slot(1, '08:00', '10:00'), $this->slot(1, '08:00', '10:00')])
            ->assertSessionHasErrors(); // duplicada

        $this->assertSame(0, $profile->availabilitySlots()->count());

        $this->saveSlots($user, [$this->slot(1, '08:00', '10:00'), $this->slot(2, '08:00', '10:00')])
            ->assertSessionHasNoErrors();
    }

    public function test_a_failed_save_does_not_wipe_existing_availability(): void
    {
        [$user, $profile] = $this->teacher();
        $this->saveSlots($user, [$this->slot(1, '08:00', '10:00')])->assertSessionHasNoErrors();

        $this->saveSlots($user, [$this->slot(2, '10:00', '09:00')])->assertSessionHasErrors();

        $this->assertSame([1], $profile->availabilitySlots()->pluck('day_of_week')->all());
    }

    public function test_the_slot_count_is_capped(): void
    {
        [$user, $profile] = $this->teacher();
        $max = TeacherAvailabilitySlot::MAX_SLOTS;

        // max + 1 franjas, todas válidas y sin solape (7 días x 5 bloques = 35).
        $slots = [];
        for ($i = 0; $i <= $max; $i++) {
            $hour = 6 + intdiv($i, 7) * 3;
            $slots[] = $this->slot($i % 7, sprintf('%02d:00', $hour), sprintf('%02d:30', $hour));
        }

        $this->saveSlots($user, $slots)->assertSessionHasErrors('slots');
        $this->assertSame(0, $profile->availabilitySlots()->count());

        $this->saveSlots($user, array_slice($slots, 0, $max))->assertSessionHasNoErrors();
        $this->assertSame($max, $profile->availabilitySlots()->count());
    }

    public function test_the_slots_key_is_required_to_avoid_accidental_wipes(): void
    {
        [$user, $profile] = $this->teacher();
        $this->saveSlots($user, [$this->slot(1, '08:00', '10:00')]);

        $this->actingAs($user)->put(route('teacher.availability.update'), [])->assertSessionHasErrors('slots');

        $this->assertSame(1, $profile->availabilitySlots()->count());
    }

    // ── Pantalla ─────────────────────────────────────────────────────────

    public function test_the_profile_page_receives_the_declared_slots_in_hhmm(): void
    {
        [$user] = $this->teacher();
        $this->saveSlots($user, [$this->slot(4, '09:00', '11:30')]);

        $this->actingAs($user)->get(route('teacher.profile'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Teacher/Edit')
                ->where('availability.0.day_of_week', 4)
                ->where('availability.0.start_time', '09:00')
                ->where('availability.0.end_time', '11:30')
                ->where('availabilityMaxSlots', TeacherAvailabilitySlot::MAX_SLOTS)
            );
    }

    public function test_deleting_the_teacher_profile_removes_their_slots(): void
    {
        [$user, $profile] = $this->teacher();
        $this->saveSlots($user, [$this->slot(1, '08:00', '10:00')]);

        $profile->delete();

        $this->assertSame(0, TeacherAvailabilitySlot::count());
    }
}
