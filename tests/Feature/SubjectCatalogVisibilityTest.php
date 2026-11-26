<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Support\SubjectCatalog;
use App\Services\SubjectNormalizer;
use Database\Seeders\SubjectSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Catálogo maestro cerrado + visibilidad dinámica: una materia del catálogo solo aparece en «Materias disponibles»
 * (portada, catálogo público, Movi) mientras la tenga marcada al menos un profesor verificado con la cuenta activa.
 * Los profesores solo eligen ids del catálogo; el texto libre se rechaza sin crear nada.
 */
class SubjectCatalogVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Role::findOrCreate('teacher');
        Role::findOrCreate('parent');
        Role::findOrCreate('admin');
        $this->seed(SubjectSeeder::class);
    }

    public function test_the_seeder_loads_the_full_catalog_without_duplicates_and_is_idempotent(): void
    {
        $expected = count(SubjectCatalog::entries());
        $this->assertSame($expected, Subject::count());

        $this->seed(SubjectSeeder::class);
        $this->assertSame($expected, Subject::count(), 'Ejecutarlo dos veces no debe duplicar.');

        $this->assertDatabaseHas('subjects', ['slug' => 'algebra', 'name' => 'Álgebra', 'category' => 'matematicas']);
        $this->assertDatabaseHas('subjects', ['slug' => 'matematica', 'name' => 'Matemática']);
        $this->assertDatabaseMissing('subjects', ['name' => 'Matemáticas']);

        // Ningún par del catálogo es «equivalente» (misma clave sin tildes/plural): no hay casi-duplicados.
        $keys = collect(SubjectCatalog::entries())->map(fn ($e) => SubjectNormalizer::matchKey($e['name']));
        $this->assertSame($keys->count(), $keys->unique()->count());
    }

    public function test_the_whole_catalog_is_hidden_until_a_verified_teacher_marks_a_subject(): void
    {
        $this->assertSame([], $this->publicSubjectNames());
    }

    public function test_unverified_teacher_subjects_stay_hidden_and_verification_publishes_exactly_them(): void
    {
        [$teacher, $profile] = $this->registerTeacher(['Álgebra', 'Física']);
        $this->assertFalse((bool) $profile->is_verified);
        $this->assertSame([], $this->publicSubjectNames(), 'Un profesor sin verificar no publica materias.');

        $this->actingAsAdmin()->post(route('admin.teachers.verify', $profile))->assertRedirect();
        $this->assertTrue((bool) $profile->fresh()->is_verified);

        $this->assertSame(['Álgebra', 'Física'], $this->publicSubjectNames());
        $this->assertEqualsCanonicalizing(['Álgebra', 'Física'], collect($this->get(route('catalog.index'))->viewData('page')['props']['subjects'])->pluck('name')->all());
    }

    public function test_unmarking_suspending_closing_or_rejecting_hides_the_subject_unless_another_verified_teacher_has_it(): void
    {
        [$teacher, $profile] = $this->verifiedTeacher(['Álgebra', 'Física']);
        [, $other] = $this->verifiedTeacher(['Física']);
        $this->assertSame(['Álgebra', 'Física'], $this->publicSubjectNames());

        // Desmarca Álgebra (nadie más la tiene) y Física sigue por el otro profesor.
        $this->actingAs($teacher)->patch(route('teacher.profile.update'), [
            'mentorship_slots_total' => 0,
            'subject_ids' => [$this->subjectId('Física')],
        ])->assertSessionHasNoErrors();
        $this->assertSame(['Física'], $this->publicSubjectNames());

        // El otro profesor suspendido: Física sigue visible por el primero.
        $other->user->forceFill(['suspended_at' => now()])->save();
        $this->assertSame(['Física'], $this->publicSubjectNames());

        // Suspender también al primero la oculta; reactivarlo la devuelve.
        $teacher->forceFill(['suspended_at' => now()])->save();
        $this->assertSame([], $this->publicSubjectNames());
        $teacher->forceFill(['suspended_at' => null])->save();
        $this->assertSame(['Física'], $this->publicSubjectNames());

        // Cuenta cerrada/eliminada: deja de contar.
        $teacher->forceFill(['account_closed_at' => now()])->save();
        $this->assertSame([], $this->publicSubjectNames());

        // Rechazado por el admin (deja de estar verificado): tampoco cuenta.
        $other->user->forceFill(['suspended_at' => null])->save();
        $this->assertSame(['Física'], $this->publicSubjectNames());
        $this->actingAsAdmin()->post(route('admin.teachers.reject', $other), ['reason' => 'Documentación incompleta'])->assertRedirect();
        $this->assertSame([], $this->publicSubjectNames());
    }

    public function test_admin_suspension_through_the_panel_hides_the_subject(): void
    {
        [$teacher] = $this->verifiedTeacher(['Química']);
        $this->assertSame(['Química'], $this->publicSubjectNames());

        $this->actingAsAdmin()->post(route('admin.users.suspend', $teacher), ['reason' => 'Prueba'])->assertRedirect();
        $this->assertSame([], $this->publicSubjectNames());

        $this->actingAsAdmin()->post(route('admin.users.unsuspend', $teacher))->assertRedirect();
        $this->assertSame(['Química'], $this->publicSubjectNames());
    }

    public function test_free_text_or_unknown_subjects_are_rejected_with_422_and_create_nothing(): void
    {
        [$teacher] = $this->verifiedTeacher(['Inglés']);
        $before = Subject::count();
        $payload = ['mentorship_slots_total' => 0];

        foreach ([
            ['subject_ids' => ['Robótica avanzada']],                    // texto libre en vez de id
            ['subject_ids' => [999999]],                                  // id inexistente
            ['subject_ids' => []],                                        // ninguna
            ['subject_ids' => [$this->subjectId('Inglés')], 'subject_names' => ['Neuromarketing']], // campo antiguo
        ] as $attempt) {
            $this->actingAs($teacher)->patchJson(route('teacher.profile.update'), $payload + $attempt)->assertStatus(422);
            $this->actingAs($teacher)->postJson(route('teacher.setup.store'), $payload + $attempt)->assertStatus(422);
        }

        $this->assertSame($before, Subject::count(), 'Ningún intento crea materias.');
        $this->assertSame(['Inglés'], $teacher->teacherProfile->subjects()->pluck('name')->all(), 'Sus materias no cambian.');
    }

    public function test_a_legacy_subject_outside_the_catalog_is_not_offered_nor_selectable(): void
    {
        [$teacher] = $this->verifiedTeacher(['Inglés']);
        $legacy = Subject::create(['name' => 'Neuromarketing', 'level' => 'todos']);
        $this->assertNull($legacy->category);

        $this->actingAs($teacher)->get(route('teacher.profile'))->assertInertia(fn (Assert $page) => $page
            ->where('subjects', fn ($subjects) => ! collect($subjects)->contains('id', $legacy->id)));

        $this->actingAs($teacher)->patchJson(route('teacher.profile.update'), ['mentorship_slots_total' => 0, 'subject_ids' => [$legacy->id]])
            ->assertStatus(422)->assertJsonValidationErrors(['subject_ids.0']);
    }

    public function test_a_parent_registration_ignores_any_subject_ids_sent(): void
    {
        Event::fake();
        $this->post('/register', array_merge($this->registrationPayload('padre@example.test'), ['role' => 'parent', 'subject_ids' => ['basura', 999999]]))
            ->assertSessionHasNoErrors();

        $this->assertTrue(User::where('email', 'padre@example.test')->firstOrFail()->hasRole('parent'));
        $this->assertDatabaseCount('teacher_profiles', 0);
    }

    public function test_the_public_subject_payload_exposes_only_display_fields(): void
    {
        $this->verifiedTeacher(['Historia']);

        $this->get(route('welcome'))->assertInertia(fn (Assert $page) => $page
            ->has('subjects', 1)
            ->where('subjects.0', fn ($subject) => array_keys(collect($subject)->all()) === ['id', 'name', 'level', 'icon']));
    }

    public function test_registration_accepts_catalog_ids_only(): void
    {
        Event::fake();
        $before = Subject::count();

        $this->postJson('/register', $this->registrationPayload('libre@example.test') + ['teacher_subject_names' => ['Robótica avanzada']])
            ->assertStatus(422)->assertJsonValidationErrors(['teacher_subject_names', 'subject_ids']);
        $this->postJson('/register', $this->registrationPayload('texto@example.test') + ['subject_ids' => ['Neuromarketing']])
            ->assertStatus(422)->assertJsonValidationErrors(['subject_ids.0']);

        $this->assertSame($before, Subject::count());
        $this->assertDatabaseCount('users', 0);

        $this->get(route('register'))->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Register')
            ->has('subjects', $before)
            ->where('subjects.0.category_label', 'Matemáticas'));
    }

    public function test_a_subject_link_to_a_hidden_subject_still_opens_the_request_form_without_error(): void
    {
        $parent = User::factory()->create();
        $parent->assignRole('parent');

        $this->actingAs($parent)->get(route('class-requests.create', ['subject_id' => $this->subjectId('Quechua')]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('prefillSubjectId', $this->subjectId('Quechua')));
        $this->actingAs($parent)->get(route('class-requests.create', ['subject_id' => 999999]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('prefillSubjectId', null));
    }

    /** @return list<string> */
    private function publicSubjectNames(): array
    {
        $names = null;
        $this->app['auth']->forgetGuards(); // la portada se mira como visitante
        $this->get(route('welcome'))->assertOk()->assertInertia(function (Assert $page) use (&$names) {
            $names = collect($page->toArray()['props']['subjects'])->pluck('name')->sort(fn ($a, $b) => strcmp(SubjectNormalizer::normalize($a), SubjectNormalizer::normalize($b)))->values()->all();
        });

        return $names;
    }

    private function subjectId(string $name): int
    {
        return Subject::where('name', $name)->value('id');
    }

    /** @return array{0: User, 1: TeacherProfile} */
    private function registerTeacher(array $subjects): array
    {
        Event::fake();
        $email = 'profe'.uniqid().'@example.test';
        $this->post('/register', $this->registrationPayload($email) + [
            'subject_ids' => array_map(fn ($n) => $this->subjectId($n), $subjects),
        ])->assertSessionHasNoErrors();
        auth()->logout();

        $user = User::where('email', $email)->firstOrFail();

        return [$user, $user->teacherProfile];
    }

    /** @return array{0: User, 1: TeacherProfile} */
    private function verifiedTeacher(array $subjects): array
    {
        $user = User::factory()->create();
        $user->assignRole('teacher');
        $profile = TeacherProfile::create(['user_id' => $user->id, 'is_verified' => true]);
        $profile->subjects()->sync(array_map(fn ($n) => $this->subjectId($n), $subjects));

        return [$user, $profile];
    }

    private function actingAsAdmin(): static
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $this->actingAs($admin);
    }

    private function registrationPayload(string $email): array
    {
        return [
            'name' => 'Profesor Catálogo',
            'email' => $email,
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'teacher',
            'accepted_terms' => true,
        ];
    }
}
