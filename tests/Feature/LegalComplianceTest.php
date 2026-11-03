<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\LegalAcceptance;
use App\Models\User;
use App\Notifications\ComplaintFiledNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use LogicException;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LegalComplianceTest extends TestCase
{
    use RefreshDatabase;

    private function validComplaint(array $overrides = []): array
    {
        return array_merge([
            'type' => 'reclamo',
            'consumer_name' => 'María Quispe',
            'document_type' => 'DNI',
            'document_number' => '12345678',
            'address' => 'Av. Siempre Viva 123, Lima',
            'email' => 'maria@example.com',
            'phone' => '987654321',
            'is_minor' => false,
            'good_type' => 'servicio',
            'amount' => '40.00',
            'good_description' => 'Clase de matemáticas',
            'detail' => 'El profesor no se conectó a la clase programada.',
            'consumer_request' => 'Devolución de créditos.',
            'accepted' => true,
        ], $overrides);
    }

    public function test_registration_records_versioned_acceptance(): void
    {
        foreach (['parent', 'teacher'] as $r) {
            Role::findOrCreate($r, 'web');
        }
        config(['legal.versions.terms' => 'T-2026-09', 'legal.versions.privacy' => 'P-2026-09']);

        $this->post('/register', [
            'name' => 'Padre Test',
            'email' => 'padre@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'parent',
            'accepted_terms' => true,
        ])->assertSessionHasNoErrors();

        $user = User::where('email', 'padre@example.com')->firstOrFail();
        $rows = LegalAcceptance::where('user_id', $user->id)->pluck('version', 'document')->all();
        $this->assertEquals(['terms' => 'T-2026-09', 'privacy' => 'P-2026-09'], $rows);
        $this->assertNotNull(LegalAcceptance::where('user_id', $user->id)->value('accepted_at'));
    }

    public function test_acceptances_are_append_only(): void
    {
        $user = User::factory()->create();
        $row = LegalAcceptance::create(['user_id' => $user->id, 'document' => 'terms', 'version' => 'v1', 'accepted_at' => now()]);

        $this->expectException(LogicException::class);
        $row->update(['version' => 'v2']);
    }

    public function test_guest_can_file_complaint_with_sequential_code_and_receipt(): void
    {
        Notification::fake();

        $this->get(route('complaints.create'))->assertOk();

        $this->post(route('complaints.store'), $this->validComplaint())->assertRedirect(route('complaints.create'));
        $this->post(route('complaints.store'), $this->validComplaint(['type' => 'queja']))->assertSessionHas('complaint_code');

        $codes = Complaint::orderBy('id')->pluck('code')->all();
        $year = now()->year;
        $this->assertSame(["MOVA-{$year}-000001", "MOVA-{$year}-000002"], $codes);
        $this->assertSame('open', Complaint::first()->status);
        Notification::assertSentOnDemand(ComplaintFiledNotification::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'maria@example.com');
    }

    // P1-01: el correlativo sale de complaint_sequences (una fila por año).
    public function test_sequence_row_drives_numbering_and_continues_existing_year(): void
    {
        $data = collect($this->validComplaint())->except('accepted')->all();
        $year = now()->year;

        $first = Complaint::file($data);
        $this->assertSame("MOVA-{$year}-000001", $first->code);
        $this->assertSame(1, (int) \DB::table('complaint_sequences')->where('year', $year)->value('last_number'));

        // Año ya poblado (p. ej. secuencia migrada desde hojas previas).
        \DB::table('complaint_sequences')->where('year', $year)->update(['last_number' => 41]);
        $this->assertSame("MOVA-{$year}-000042", Complaint::file($data)->code);
    }

    public function test_database_failure_while_filing_is_not_a_500(): void
    {
        Notification::fake();
        Log::spy();
        // Simula un 40001 persistente (sin DDL: no alterar el esquema compartido de la suite MySQL).
        Complaint::creating(fn () => throw new \Illuminate\Database\QueryException(
            'mysql', 'insert into complaints', [], new \PDOException('SQLSTATE[40001]: Serialization failure')
        ));

        $this->from(route('complaints.create'))
            ->post(route('complaints.store'), $this->validComplaint())
            ->assertRedirect(route('complaints.create'))
            ->assertSessionHasErrors('detail')
            ->assertSessionHas('_old_input.type', 'reclamo')
            ->assertSessionMissing('_old_input.document_number')
            ->assertSessionMissing('_old_input.address')
            ->assertSessionMissing('_old_input.email')
            ->assertSessionMissing('_old_input.detail')
            ->assertSessionMissing('_old_input.consumer_request');
        $this->assertSame(0, Complaint::count());

        Log::shouldHaveReceived('warning')->once()->with(
            'complaint.file.database_failure',
            Mockery::on(fn ($context) => is_array($context)
                && array_keys($context) === ['error_type', 'sql_state']
                && $context['error_type'] === 'QueryException'
                && is_string($context['sql_state']))
        );
    }

    public function test_invalid_complaint_retains_only_nonsensitive_input_in_session(): void
    {
        $this->from(route('complaints.create'))
            ->post(route('complaints.store'), $this->validComplaint([
                'detail' => 'Breve',
                'is_minor' => true,
                'guardian_name' => 'Tutor de prueba',
            ]))
            ->assertRedirect(route('complaints.create'))
            ->assertSessionHasErrors('detail')
            ->assertSessionHas('_old_input.type', 'reclamo')
            ->assertSessionHas('_old_input.document_type', 'DNI')
            ->assertSessionHas('_old_input.good_type', 'servicio')
            ->assertSessionMissing('_old_input.consumer_name')
            ->assertSessionMissing('_old_input.document_number')
            ->assertSessionMissing('_old_input.address')
            ->assertSessionMissing('_old_input.email')
            ->assertSessionMissing('_old_input.phone')
            ->assertSessionMissing('_old_input.guardian_name')
            ->assertSessionMissing('_old_input.detail')
            ->assertSessionMissing('_old_input.consumer_request');

        $this->assertSame(0, Complaint::count());
    }

    public function test_database_failure_responds_with_safe_503_to_json_clients(): void
    {
        Notification::fake();
        Complaint::creating(fn () => throw new \Illuminate\Database\QueryException(
            'mysql', 'insert into complaints (document_number, detail) values (?, ?)',
            ['document-number-test-only', 'private-complaint-text-test-only'],
            new \PDOException('SQLSTATE[40001]: Serialization failure')
        ));

        $response = $this->postJson(route('complaints.store'), $this->validComplaint());
        $response->assertStatus(503)
            ->assertJsonStructure(['message'])
            ->assertDontSee('document-number-test-only')
            ->assertDontSee('private-complaint-text-test-only');

        $this->assertSame(0, Complaint::count());
    }

    public function test_invalid_complaint_json_api_keeps_422_validation_contract(): void
    {
        $this->postJson(route('complaints.store'), $this->validComplaint(['detail' => 'Breve']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('detail');

        $this->assertSame(0, Complaint::count());
    }

    public function test_malformed_selectors_do_not_flash_arbitrary_nested_values(): void
    {
        $this->from(route('complaints.create'))
            ->post(route('complaints.store'), $this->validComplaint([
                'type' => ['injected' => 'valor privado no permitido'],
                'document_type' => 'Documento no permitido',
            ]))
            ->assertRedirect(route('complaints.create'))
            ->assertSessionHasErrors(['type', 'document_type'])
            ->assertSessionHas('_old_input.good_type', 'servicio')
            ->assertSessionMissing('_old_input.type')
            ->assertSessionMissing('_old_input.document_type');
    }

    public function test_minor_requires_guardian_and_client_cannot_set_internal_fields(): void
    {
        Notification::fake();

        $this->post(route('complaints.store'), $this->validComplaint(['is_minor' => true]))
            ->assertSessionHasErrors('guardian_name');

        $this->post(route('complaints.store'), $this->validComplaint([
            'status' => 'answered', 'code' => 'HACK-1', 'response' => 'x', 'user_id' => 999,
        ]))->assertSessionHasNoErrors();

        $c = Complaint::firstOrFail();
        $this->assertSame('open', $c->status);
        $this->assertStringStartsWith('MOVA-', $c->code);
        $this->assertNull($c->response);
        $this->assertNull($c->user_id);
    }

    public function test_only_admin_can_manage_and_respond_once(): void
    {
        Notification::fake();
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('parent', 'web');
        $complaint = Complaint::file(collect($this->validComplaint())->except('accepted')->all());

        $parent = User::factory()->create();
        $parent->assignRole('parent');
        $this->actingAs($parent)->get(route('admin.complaints'))->assertForbidden();
        $this->actingAs($parent)->post(route('admin.complaints.respond', $complaint), ['response' => 'Respuesta suficiente'])->assertForbidden();

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->get(route('admin.complaints'))->assertOk();
        $this->actingAs($admin)->post(route('admin.complaints.respond', $complaint), ['response' => 'Se devolvieron los créditos.'])
            ->assertSessionHasNoErrors();

        $complaint->refresh();
        $this->assertSame('answered', $complaint->status);
        $this->assertSame($admin->id, $complaint->responded_by);
        $this->actingAs($admin)->post(route('admin.complaints.respond', $complaint), ['response' => 'Otra respuesta más'])->assertStatus(409);
    }

    public function test_health_check_flags_missing_provider_data_in_production(): void
    {
        config(['legal.provider.business_name' => null, 'legal.provider.ruc' => null, 'legal.provider.address' => null]);
        $this->app['env'] = 'production';

        $this->artisan('mova:health-check')->expectsOutputToContain('LEGAL_PROVIDER_DATA_MISSING');
    }
}
