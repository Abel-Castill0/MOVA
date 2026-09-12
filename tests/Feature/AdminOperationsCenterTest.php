<?php

namespace Tests\Feature;

use App\Models\OperationalAlert;
use App\Models\User;
use App\Services\OperationalAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Contrato del Centro de Operaciones (Fase 3A).
 *
 * Lo que se protege aquí no es el HTML: es que la pantalla no se convierta en
 * una segunda fuente de verdad, que no filtre payloads y que "resolver" no sea
 * un botón de esconder.
 */
class AdminOperationsCenterTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::findOrCreate('admin', 'web');
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function userWithRole(string $role): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function alert(array $overrides = []): OperationalAlert
    {
        return OperationalAlert::create(array_merge([
            'alert_key' => 'test:'.uniqid(),
            'type' => OperationalAlert::TYPE_PAYMENT_REVIEW,
            'severity' => OperationalAlert::SEVERITY_CRITICAL,
            'title' => 'Pago en revisión',
            'message' => 'Requiere decisión manual.',
            'context' => ['PaymentOrder' => 12],
            'first_detected_at' => now(),
            'last_detected_at' => now(),
            'occurrences' => 1,
        ], $overrides));
    }

    /** Incidencia de un solo disparo: nadie la cierra automáticamente. */
    private function closableAlert(array $overrides = []): OperationalAlert
    {
        return $this->alert(array_merge([
            'alert_key' => 'payment_webhook:'.random_int(1000, 99999).':failed',
            'type' => OperationalAlert::TYPE_RECONCILIATION_FAILURE,
            'context' => ['PaymentWebhook' => 99],
        ], $overrides));
    }

    // ── Autorización ─────────────────────────────────────────────────────────

    public function test_an_admin_can_open_the_operations_center(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/operations')
            ->assertOk();
    }

    public function test_a_parent_cannot_open_the_operations_center(): void
    {
        $this->actingAs($this->userWithRole('parent'))
            ->get('/admin/operations')
            ->assertForbidden();
    }

    public function test_a_teacher_cannot_open_the_operations_center(): void
    {
        $this->actingAs($this->userWithRole('teacher'))
            ->get('/admin/operations')
            ->assertForbidden();
    }

    public function test_a_guest_cannot_open_the_operations_center(): void
    {
        $this->get('/admin/operations')->assertRedirect('/login');
    }

    public function test_a_non_admin_cannot_close_an_alert(): void
    {
        $alert = $this->closableAlert();

        $this->actingAs($this->userWithRole('teacher'))
            ->post("/admin/operations/{$alert->id}/close", ['reason' => 'Intento no autorizado.'])
            ->assertForbidden();

        $this->assertNull($alert->fresh()->resolved_at);
    }

    // ── Resumen y listado ────────────────────────────────────────────────────

    public function test_the_summary_counts_only_open_alerts(): void
    {
        $this->alert(['severity' => OperationalAlert::SEVERITY_CRITICAL]);
        $this->alert(['severity' => OperationalAlert::SEVERITY_WARNING]);
        $this->alert(['severity' => OperationalAlert::SEVERITY_CRITICAL, 'resolved_at' => now()]);

        $this->actingAs($this->admin())
            ->get('/admin/operations')
            ->assertInertia(fn ($page) => $page
                ->where('summary.critical_open', 1)
                ->where('summary.warning_open', 1)
                ->where('summary.total_open', 2));
    }

    public function test_the_default_listing_hides_resolved_alerts(): void
    {
        $open = $this->alert(['title' => 'Sigue abierta']);
        $this->alert(['title' => 'Ya resuelta', 'resolved_at' => now()]);

        $this->actingAs($this->admin())
            ->get('/admin/operations')
            ->assertInertia(fn ($page) => $page
                ->has('alerts.data', 1)
                ->where('alerts.data.0.id', $open->id));
    }

    public function test_alerts_can_be_filtered_by_severity_and_category(): void
    {
        $this->alert([
            'type' => OperationalAlert::TYPE_LESSON_NEEDS_REVIEW,
            'severity' => OperationalAlert::SEVERITY_WARNING,
        ]);
        $finance = $this->alert([
            'type' => OperationalAlert::TYPE_LEDGER_ANOMALY,
            'severity' => OperationalAlert::SEVERITY_CRITICAL,
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/operations?category=finance&severity=critical')
            ->assertInertia(fn ($page) => $page
                ->has('alerts.data', 1)
                ->where('alerts.data.0.id', $finance->id));
    }

    public function test_an_unknown_category_filter_returns_everything_rather_than_leaking(): void
    {
        // Un filtro inválido se descarta y se comporta como "sin filtro"; lo que
        // NO debe pasar es que llegue a la consulta y devuelva un conjunto
        // arbitrario.
        $this->alert();

        $this->actingAs($this->admin())
            ->get('/admin/operations?category=../../etc')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('filters.category', null)->has('alerts.data', 1));
    }

    public function test_the_listing_is_paginated(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->alert();
        }

        $this->actingAs($this->admin())
            ->get('/admin/operations')
            ->assertInertia(fn ($page) => $page
                ->has('alerts.data', 20)
                ->where('alerts.total', 25));
    }

    // ── Seguridad del contexto ───────────────────────────────────────────────

    public function test_sensitive_context_keys_are_never_sent_to_the_browser(): void
    {
        $this->alert(['context' => [
            'PaymentOrder' => 7,
            'access_token' => 'APP_USR-super-secreto',
            'Webhook payload' => 'x-signature: abc',
            'Stack trace' => '#0 /app/foo.php(1)',
            'Datos de tarjeta' => '4111111111111111',
        ]]);

        $response = $this->actingAs($this->admin())->get('/admin/operations');

        $response->assertInertia(fn ($page) => $page
            ->where('alerts.data.0.context', ['PaymentOrder' => 7]));

        $response->assertDontSee('super-secreto');
        $response->assertDontSee('4111111111111111');
    }

    public function test_non_scalar_context_values_are_dropped(): void
    {
        $this->alert(['context' => [
            'Motivo' => 'status_detail desconocido',
            'Respuesta' => ['id' => 1, 'nested' => ['deep' => 'value']],
        ]]);

        $this->actingAs($this->admin())
            ->get('/admin/operations')
            ->assertInertia(fn ($page) => $page
                ->where('alerts.data.0.context', ['Motivo' => 'status_detail desconocido']));
    }

    /**
     * El caso concreto que motivó pasar de blacklist a allowlist.
     *
     * ProcessMercadoPagoWebhook mete
     * `'Error' => substr($exception->getMessage(), 0, 300)` en el contexto. La
     * palabra "Error" no coincide con ningún patrón que una blacklist razonable
     * incluiría, así que un mensaje de excepción crudo —con la URL del
     * proveedor y su query string dentro— llegaba entero al navegador.
     */
    public function test_the_raw_exception_message_of_a_failed_webhook_is_not_exposed(): void
    {
        $this->alert([
            'type' => OperationalAlert::TYPE_RECONCILIATION_FAILURE,
            'alert_key' => 'payment_webhook:31:failed',
            'context' => [
                'PaymentWebhook' => 31,
                'Error' => 'cURL error 28 for https://api.mercadopago.com/v1/payments/9?access_token=APP_USR-leak',
            ],
        ]);

        $response = $this->actingAs($this->admin())->get('/admin/operations');

        $response->assertInertia(fn ($page) => $page
            ->where('alerts.data.0.context', ['PaymentWebhook' => 31]));

        $response->assertDontSee('APP_USR-leak');
        $response->assertDontSee('api.mercadopago.com');
    }

    /**
     * Denegación por defecto: una clave que nadie declaró segura no sale, por
     * inocente que parezca su nombre.
     */
    public function test_a_context_key_that_is_not_allowlisted_is_invisible(): void
    {
        $this->alert([
            'type' => OperationalAlert::TYPE_LESSON_NEEDS_REVIEW,
            'context' => [
                'Clase' => 44,
                'Notas internas' => 'texto que nadie declaró seguro',
            ],
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/operations')
            ->assertInertia(fn ($page) => $page
                ->where('alerts.data.0.context', ['Clase' => 44]));
    }

    /**
     * `Estado en el proveedor` y `Detalle del proveedor` son `status` y
     * `status_detail` tal cual los devuelve Mercado Pago: los únicos valores
     * permitidos que MOVA no escribe. Se truncan por si alguna vez llega algo
     * que no sea el enum corto y documentado que se espera.
     */
    public function test_provider_controlled_values_are_length_capped(): void
    {
        $this->alert([
            'type' => OperationalAlert::TYPE_PAYMENT_REVIEW,
            'context' => [
                'PaymentOrder' => 3,
                'Detalle del proveedor' => str_repeat('x', 900),
            ],
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/operations')
            ->assertInertia(function ($page) {
                $context = $page->toArray()['props']['alerts']['data'][0]['context'];

                $this->assertSame(3, $context['PaymentOrder']);
                $this->assertSame(201, mb_strlen($context['Detalle del proveedor']));
                $this->assertStringEndsWith('…', $context['Detalle del proveedor']);
            });
    }

    // ── Deep links ───────────────────────────────────────────────────────────

    public function test_each_category_points_at_the_screen_that_owns_the_resource(): void
    {
        $lesson = $this->alert(['type' => OperationalAlert::TYPE_LESSON_NEEDS_REVIEW]);
        $finance = $this->alert(['type' => OperationalAlert::TYPE_PAYMENT_REVIEW]);
        $system = $this->alert(['type' => OperationalAlert::TYPE_HEALTH_CHECK]);

        $this->assertSame(route('admin.lessons'), $lesson->actionRoute()['name'] ? route($lesson->actionRoute()['name']) : null);
        $this->assertSame(route('admin.recharges.index'), route($finance->actionRoute()['name']));

        // Una incidencia de configuración no se arregla desde ninguna pantalla:
        // ofrecer un botón sería mentir sobre lo que puede hacer el admin.
        $this->assertNull($system->actionRoute());
    }

    // ── Resolución manual ────────────────────────────────────────────────────

    public function test_closing_requires_a_substantive_reason(): void
    {
        $alert = $this->closableAlert();

        $this->actingAs($this->admin())
            ->from('/admin/operations')
            ->post("/admin/operations/{$alert->id}/close", ['reason' => 'ok'])
            ->assertSessionHasErrors('reason');

        $this->assertNull($alert->fresh()->resolved_at);
    }

    public function test_closing_records_who_when_and_why(): void
    {
        $admin = $this->admin();
        $alert = $this->closableAlert();

        $this->actingAs($admin)
            ->post("/admin/operations/{$alert->id}/close", ['reason' => 'Verificado con el proveedor, pago acreditado.'])
            ->assertRedirect();

        $fresh = $alert->fresh();
        $this->assertNotNull($fresh->resolved_at);
        $this->assertSame($admin->id, $fresh->resolved_by);
        $this->assertSame('Verificado con el proveedor, pago acreditado.', $fresh->resolution_note);
    }

    public function test_closing_an_already_closed_alert_does_not_overwrite_the_original_author(): void
    {
        $first = $this->admin();
        $second = $this->admin();
        $alert = $this->closableAlert();

        $this->actingAs($first)
            ->post("/admin/operations/{$alert->id}/close", ['reason' => 'Primer cierre, el correcto.']);

        $this->actingAs($second)
            ->post("/admin/operations/{$alert->id}/close", ['reason' => 'Segundo cierre, llega tarde.']);

        $fresh = $alert->fresh();
        $this->assertSame($first->id, $fresh->resolved_by);
        $this->assertSame('Primer cierre, el correcto.', $fresh->resolution_note);
    }

    /**
     * La garantía central: cerrar a mano no esconde un problema que sigue vivo.
     */
    public function test_a_manually_resolved_alert_reopens_when_the_problem_is_detected_again(): void
    {
        Notification::fake();

        $admin = $this->admin();
        $service = app(OperationalAlertService::class);

        $key = 'payment_webhook:4242:failed';
        $raise = fn () => $service->raise(
            key: $key,
            type: OperationalAlert::TYPE_RECONCILIATION_FAILURE,
            title: 'Webhook agotó reintentos',
            message: 'No se pudo procesar.',
            severity: OperationalAlert::SEVERITY_CRITICAL,
        );

        $raise();
        $alert = OperationalAlert::where('alert_key', $key)->firstOrFail();

        $this->actingAs($admin)
            ->post("/admin/operations/{$alert->id}/close", ['reason' => 'Se cierra sin arreglar la causa.'])
            ->assertRedirect();

        $this->assertNotNull($alert->fresh()->resolved_at);

        // El barrido vuelve a detectarlo.
        $raise();

        $this->assertNull($alert->fresh()->resolved_at, 'Una incidencia cerrada a mano debe reabrirse si la causa persiste.');
    }

    /**
     * Al reabrirse no debe quedar metadata de cierre: una incidencia ABIERTA que
     * dice "cerrada por X" es una contradicción en pantalla y una pista falsa en
     * una auditoría.
     */
    public function test_reopening_clears_the_previous_closure_metadata(): void
    {
        Notification::fake();

        $service = app(OperationalAlertService::class);
        $key = 'payment_webhook:777:failed';
        $raise = fn () => $service->raise(
            key: $key,
            type: OperationalAlert::TYPE_RECONCILIATION_FAILURE,
            title: 'Webhook agotó reintentos',
            message: 'No se pudo procesar.',
            severity: OperationalAlert::SEVERITY_CRITICAL,
        );

        $raise();
        $alert = OperationalAlert::where('alert_key', $key)->firstOrFail();

        $this->actingAs($this->admin())
            ->post("/admin/operations/{$alert->id}/close", ['reason' => 'Cierre que luego se invalida.']);

        $raise();

        $fresh = $alert->fresh();
        $this->assertNull($fresh->resolved_at);
        $this->assertNull($fresh->resolved_by, 'Una incidencia reabierta no debe conservar el autor del cierre.');
        $this->assertNull($fresh->resolved_by_name);
        $this->assertNull($fresh->resolution_note);
    }

    // ── Allowlist de cierre manual ───────────────────────────────────────────

    /**
     * Si la fuente de verdad puede cerrar la incidencia sola, un admin no debe
     * poder silenciarla a mano.
     */
    public function test_an_automatically_resolvable_alert_cannot_be_closed_by_hand(): void
    {
        $autoResolvable = [
            'ledger:anomaly' => OperationalAlert::TYPE_LEDGER_ANOMALY,
            'lesson:12:needs_admin_review' => OperationalAlert::TYPE_LESSON_NEEDS_REVIEW,
            'payment_order:5:review' => OperationalAlert::TYPE_PAYMENT_REVIEW,
            'health:QUEUE_FAILED_JOBS' => OperationalAlert::TYPE_HEALTH_CHECK,
        ];

        foreach ($autoResolvable as $key => $type) {
            $alert = $this->alert(['alert_key' => $key, 'type' => $type]);

            $this->actingAs($this->admin())
                ->post("/admin/operations/{$alert->id}/close", ['reason' => 'Intento de silenciar algo autoresoluble.'])
                ->assertStatus(422);

            $this->assertNull($alert->fresh()->resolved_at, "«{$key}» no debería poder cerrarse a mano.");
        }
    }

    public function test_only_one_shot_alerts_are_manually_closable(): void
    {
        foreach ([
            'payment_webhook:9:review',
            'payment_webhook:9:failed',
            'recharge:9:manual_reversal_blocked',
        ] as $key) {
            $this->assertTrue(
                $this->alert(['alert_key' => $key])->isManuallyClosable(),
                "«{$key}» no tiene resolución automática, debe poder cerrarse."
            );
        }

        $this->assertFalse($this->alert(['alert_key' => 'ledger:anomaly'])->isManuallyClosable());
        $this->assertFalse($this->alert(['alert_key' => 'algo:nuevo:desconocido'])->isManuallyClosable());
    }

    public function test_the_ui_is_told_which_alerts_can_be_closed(): void
    {
        $this->closableAlert();

        $this->actingAs($this->admin())
            ->get('/admin/operations')
            ->assertInertia(fn ($page) => $page->where('alerts.data.0.can_close', true));
    }

    // ── Trazabilidad tras borrar al actor ────────────────────────────────────

    public function test_the_closing_admin_name_survives_the_account_being_deleted(): void
    {
        $admin = $this->admin();
        $alert = $this->closableAlert();

        $this->actingAs($admin)
            ->post("/admin/operations/{$alert->id}/close", ['reason' => 'Revisado y descartado manualmente.']);

        $this->assertSame($admin->name, $alert->fresh()->resolved_by_name);

        $admin->delete();

        $fresh = $alert->fresh();

        // `resolved_by` queda huérfano o a null según el motor, y esa diferencia
        // es real: en MySQL la FK con nullOnDelete existe (verificado en
        // information_schema), pero en SQLite `$table->foreign()` sobre una
        // tabla YA CREADA es un no-op silencioso — SQLite no soporta
        // ALTER TABLE ADD CONSTRAINT. Producción es MySQL, así que la FK sí
        // protege ahí; el test no puede afirmarlo en los dos motores.
        //
        // Lo que SÍ se garantiza en ambos, y es lo que importa para la
        // auditoría, es que el nombre sobrevive y la pantalla lo sigue
        // mostrando: la relación devuelve null (el usuario no existe) y el
        // controller cae al snapshot.
        $this->assertSame($admin->name, $fresh->resolved_by_name, 'El nombre debe sobrevivir para la auditoría.');
        $this->assertNull($fresh->resolvedBy, 'La relación no debe resolver a un usuario borrado.');

        $this->actingAs($this->admin())
            ->get('/admin/operations?status=all')
            ->assertInertia(fn ($page) => $page->where('alerts.data.0.resolved_by', $admin->name));
    }

    // ── Tipos desconocidos ───────────────────────────────────────────────────

    public function test_an_unmapped_alert_type_is_visible_and_does_not_break_the_page(): void
    {
        $unknown = $this->alert(['type' => 'brand_new_producer', 'alert_key' => 'nuevo:1']);

        $this->actingAs($this->admin())
            ->get('/admin/operations?status=all')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('alerts.data.0.id', $unknown->id));

        // Y también bajo la categoría que ella misma declara (`system`), en vez
        // de desaparecer por no estar mapeada.
        $this->assertSame(OperationalAlert::CATEGORY_SYSTEM, $unknown->category());

        $this->actingAs($this->admin())
            ->get('/admin/operations?category=system')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('alerts.data', 1));
    }

    public function test_an_unmapped_type_exposes_no_context_at_all(): void
    {
        $this->alert([
            'type' => 'brand_new_producer',
            'context' => ['Lo que sea' => 'valor', 'access_token' => 'secreto'],
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/operations')
            ->assertInertia(fn ($page) => $page->where('alerts.data.0.context', []));
    }

    /**
     * Los campos de auditoría no son elegibles por el cliente.
     *
     * `resolved_by`, `resolved_by_name` y `resolution_note` están en `$fillable`
     * (para que un seeder no los descarte en silencio), así que conviene fijar
     * que ESO NO los expone: el endpoint valida un único campo y el servicio
     * los escribe él mismo.
     */
    public function test_audit_fields_cannot_be_chosen_by_the_request(): void
    {
        $admin = $this->admin();
        $impostor = $this->admin();
        $alert = $this->closableAlert();

        $this->actingAs($admin)->post("/admin/operations/{$alert->id}/close", [
            'reason' => 'Motivo legítimo del cierre manual.',
            // Todo esto debe ignorarse.
            'resolved_by' => $impostor->id,
            'resolved_by_name' => 'Alguien Que No Fue',
            'resolution_note' => 'Motivo inyectado por el cliente.',
            'resolved_at' => now()->subYear()->toDateTimeString(),
            'severity' => OperationalAlert::SEVERITY_WARNING,
            'title' => 'Título inyectado',
        ])->assertRedirect();

        $fresh = $alert->fresh();
        $this->assertSame($admin->id, $fresh->resolved_by);
        $this->assertSame($admin->name, $fresh->resolved_by_name);
        $this->assertSame('Motivo legítimo del cierre manual.', $fresh->resolution_note);
        $this->assertTrue($fresh->resolved_at->isToday());
        $this->assertSame(OperationalAlert::SEVERITY_CRITICAL, $fresh->severity);
        $this->assertNotSame('Título inyectado', $fresh->title);
    }

    // ── Copy humano (Fase 3B) ────────────────────────────────────────────────

    public function test_internal_type_keys_never_reach_the_browser(): void
    {
        foreach ([
            OperationalAlert::TYPE_PAYMENT_REVIEW,
            OperationalAlert::TYPE_LEDGER_ANOMALY,
            OperationalAlert::TYPE_LESSON_NEEDS_REVIEW,
            OperationalAlert::TYPE_HEALTH_CHECK,
        ] as $type) {
            $this->alert(['type' => $type, 'alert_key' => 'copy:'.$type]);
        }

        $response = $this->actingAs($this->admin())->get('/admin/operations');

        // La clave interna ni siquiera se envía en las props: la pantalla no
        // debe tener la posibilidad de pintarla por descuido.
        $response->assertInertia(fn ($page) => $page->missing('alerts.data.0.type'));

        foreach (['payment_review', 'ledger_anomaly', 'lesson_needs_review', 'health_check'] as $internal) {
            $response->assertDontSee($internal);
        }
    }

    public function test_every_known_type_has_a_human_label(): void
    {
        foreach ([
            OperationalAlert::TYPE_PAYMENT_REVIEW,
            OperationalAlert::TYPE_WEBHOOK_REVIEW,
            OperationalAlert::TYPE_RECONCILIATION_FAILURE,
            OperationalAlert::TYPE_LEDGER_ANOMALY,
            OperationalAlert::TYPE_LESSON_NEEDS_REVIEW,
            OperationalAlert::TYPE_HEALTH_CHECK,
        ] as $type) {
            $label = $this->alert(['type' => $type, 'alert_key' => 'label:'.$type])->typeLabel();

            $this->assertNotSame($type, $label, "«{$type}» debe tener una etiqueta legible.");
            $this->assertStringNotContainsString('_', $label);
        }

        // Un productor nuevo no rompe la pantalla ni afirma una causa.
        $this->assertSame(
            'Incidencia operativa',
            $this->alert(['type' => 'productor_nuevo', 'alert_key' => 'label:nuevo'])->typeLabel()
        );
    }

    // ── Salud de capacidades ─────────────────────────────────────────────────

    /**
     * El detalle debe explicar el estado que acompaña. Antes decía "Pagos
     * deshabilitados por configuración" incluso con el estado en `attention`:
     * dos frases contradictorias en la misma línea.
     */
    public function test_the_capability_detail_matches_its_own_status(): void
    {
        $this->alert(['type' => OperationalAlert::TYPE_PAYMENT_REVIEW, 'alert_key' => 'payment_order:1:review']);

        $this->actingAs($this->admin())
            ->get('/admin/operations')
            ->assertInertia(function ($page) {
                $caps = collect($page->toArray()['props']['capabilities']);

                $payments = $caps->firstWhere('key', 'payments');
                $this->assertSame('attention', $payments['status']);
                $this->assertStringNotContainsString('deshabilitad', mb_strtolower($payments['detail']));

                // Y una capacidad sin señal no debe describirse como si la tuviera.
                $lessons = $caps->firstWhere('key', 'lessons');
                $this->assertSame('unknown', $lessons['status']);
            });
    }


    /**
     * El requisito más importante del panel: la ausencia de alertas NO es
     * prueba de salud.
     */
    public function test_capabilities_without_a_probe_report_unknown_not_healthy(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/operations')
            ->assertInertia(function ($page) {
                $caps = collect($page->toArray()['props']['capabilities']);

                foreach (['payments', 'ledger', 'lessons', 'system'] as $key) {
                    $cap = $caps->firstWhere('key', $key);
                    $this->assertSame('unknown', $cap['status'], "«{$key}» sin señal debe ser unknown, nunca healthy.");
                }

                // La cola sí tiene señal positiva real: failed_jobs se cuenta.
                $this->assertSame('healthy', $caps->firstWhere('key', 'queue')['status']);
            });
    }

    public function test_an_open_alert_moves_its_capability_to_attention(): void
    {
        $this->alert(['type' => OperationalAlert::TYPE_LEDGER_ANOMALY]);

        $this->actingAs($this->admin())
            ->get('/admin/operations')
            ->assertInertia(function ($page) {
                $caps = collect($page->toArray()['props']['capabilities']);
                $this->assertSame('attention', $caps->firstWhere('key', 'ledger')['status']);
            });
    }

    public function test_a_resolved_alert_does_not_keep_its_capability_in_attention(): void
    {
        $this->alert(['type' => OperationalAlert::TYPE_LEDGER_ANOMALY, 'resolved_at' => now()]);

        $this->actingAs($this->admin())
            ->get('/admin/operations')
            ->assertInertia(function ($page) {
                $caps = collect($page->toArray()['props']['capabilities']);
                $this->assertSame('unknown', $caps->firstWhere('key', 'ledger')['status']);
            });
    }
}
