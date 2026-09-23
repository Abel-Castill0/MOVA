<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\LegalAcceptance;
use App\Models\User;
use App\Notifications\ComplaintFiledNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use LogicException;
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
