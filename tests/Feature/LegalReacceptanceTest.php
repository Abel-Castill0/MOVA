<?php

namespace Tests\Feature;

use App\Models\LegalAcceptance;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * C-P1-LEGAL-REACCEPTANCE: al cambiar la versión vigente de Términos o
 * Privacidad, los usuarios existentes deben aceptarla antes de seguir
 * navegando. La aceptación es append-only; no hay bucle de redirección;
 * los documentos se pueden leer antes de aceptar; los admins no se bloquean;
 * el polling JSON (p. ej. estado de un pago) nunca se corta.
 */
class LegalReacceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['admin', 'parent', 'teacher'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        config(['legal.versions.terms' => 'T-1', 'legal.versions.privacy' => 'P-1']);
    }

    private function parent(): User
    {
        $user = User::factory()->create();
        $user->assignRole('parent');

        return $user;
    }

    public function test_user_with_current_acceptance_passes_through(): void
    {
        $this->actingAs($this->parent())->get(route('dashboard'))->assertOk();
    }

    public function test_stale_acceptance_is_gated_and_intended_url_is_kept(): void
    {
        $user = $this->parent();
        config(['legal.versions.privacy' => 'P-2']);

        $this->actingAs($user)->get(route('students.index'))->assertRedirect(route('legal.accept'));

        $this->actingAs($user)->get(route('legal.accept'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Legal/Accept')
                ->where('documents', ['privacy']));

        $this->actingAs($user)->post(route('legal.accept.store'), ['accepted' => '1'])
            ->assertRedirect(route('students.index'));

        $this->actingAs($user)->get(route('students.index'))->assertOk();
    }

    public function test_missing_acceptance_is_gated(): void
    {
        $user = User::factory()->withoutCurrentLegalAcceptance()->create();
        $user->assignRole('parent');

        $this->assertSame(0, LegalAcceptance::where('user_id', $user->id)->count());
        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('legal.accept'));
    }

    public function test_acceptance_is_append_only_and_only_adds_missing_documents(): void
    {
        $user = $this->parent();
        $before = LegalAcceptance::where('user_id', $user->id)->orderBy('id')->get()->toArray();
        config(['legal.versions.terms' => 'T-2']);

        $this->actingAs($user)->post(route('legal.accept.store'), ['accepted' => '1']);
        $this->actingAs($user)->post(route('legal.accept.store'), ['accepted' => '1']);

        $rows = LegalAcceptance::where('user_id', $user->id)->orderBy('id')->get();
        $this->assertCount(3, $rows, 'Solo se añade Términos T-2, una vez, aunque se envíe dos veces.');
        $this->assertSame($before, $rows->take(2)->map->toArray()->all(), 'Las filas previas no cambian.');
        $this->assertSame(['terms', 'T-2'], [$rows[2]->document, $rows[2]->version]);
        $this->assertSame('127.0.0.1', $rows[2]->ip);
    }

    public function test_acceptance_requires_an_explicit_checkbox(): void
    {
        $user = $this->parent();
        config(['legal.versions.terms' => 'T-2']);

        $this->actingAs($user)->post(route('legal.accept.store'), [])->assertSessionHasErrors('accepted');

        $this->assertFalse(LegalAcceptance::hasAcceptedCurrent($user));
    }

    public function test_documents_are_readable_before_accepting_and_there_is_no_loop(): void
    {
        $user = $this->parent();
        config(['legal.versions.terms' => 'T-2']);

        $this->actingAs($user)->get(route('legal.terms'))->assertOk();
        $this->actingAs($user)->get(route('legal.privacy'))->assertOk();
        $this->actingAs($user)->get(route('legal.accept'))->assertOk();
    }

    public function test_accept_page_redirects_away_when_nothing_is_pending(): void
    {
        $this->actingAs($this->parent())->get(route('legal.accept'))->assertRedirect(route('dashboard'));
    }

    public function test_json_requests_are_never_interrupted(): void
    {
        $user = $this->parent();
        config(['legal.versions.terms' => 'T-2']);

        $response = $this->actingAs($user)->getJson(route('dashboard'));

        $this->assertNotSame(route('legal.accept'), $response->headers->get('Location'));
    }

    public function test_stale_acceptance_cannot_create_a_student_by_posting_directly(): void
    {
        $user = $this->parent();
        config(['legal.versions.privacy' => 'P-2']);

        $this->actingAs($user)->post(route('students.store'), [
            'first_name' => 'Ana',
            'last_name' => 'Prueba',
            'grade_level' => 'primaria',
            'data_consent' => '1',
        ])->assertRedirect(route('legal.accept'));

        $this->assertSame(0, $user->students()->count());
    }

    public function test_stale_acceptance_rejects_json_mutations(): void
    {
        $user = $this->parent();
        config(['legal.versions.privacy' => 'P-2']);

        $this->actingAs($user)->postJson(route('students.store'), [
            'first_name' => 'Ana',
            'last_name' => 'Prueba',
            'grade_level' => 'primaria',
            'data_consent' => '1',
        ])->assertStatus(409);

        $this->assertSame(0, $user->students()->count());
    }

    public function test_admins_are_not_gated(): void
    {
        $admin = User::factory()->withoutCurrentLegalAcceptance()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->get(route('legal.accept'))->assertRedirect(route('dashboard'));
        $response = $this->actingAs($admin)->get(route('dashboard'));
        $this->assertNotSame(route('legal.accept'), $response->headers->get('Location'));
    }

    public function test_registration_records_current_versions_and_is_not_gated(): void
    {
        $this->post(route('register'), [
            'name' => 'Nueva Familia',
            'email' => 'nueva@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'parent',
            'accepted_terms' => true,
        ])->assertSessionHasNoErrors();

        $user = User::where('email', 'nueva@example.com')->first();
        $this->assertNotNull($user, 'El registro debe crear al usuario.');
        $this->assertTrue(LegalAcceptance::hasAcceptedCurrent($user));
    }

    public function test_reseeding_does_not_accept_new_terms_for_an_existing_demo_user(): void
    {
        $this->seed(DatabaseSeeder::class);
        $teacher = User::where('email', 'carlos@mova.test')->firstOrFail();
        $this->assertTrue(LegalAcceptance::hasAcceptedCurrent($teacher));

        config(['legal.versions.terms' => 'T-2']);
        $this->seed(DatabaseSeeder::class);

        $this->assertFalse(LegalAcceptance::hasAcceptedCurrent($teacher));
        $this->assertSame(2, LegalAcceptance::where('user_id', $teacher->id)->count());
    }
}
