<?php

namespace Tests\Feature;

use App\Models\OperationalAlert;
use App\Models\User;
use App\Notifications\OperationalAlertNotification;
use App\Services\OperationalAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * §7 — Alertas operativas.
 *
 * MOVA ya detectaba sus propias anomalías y las dejaba en una columna o en un
 * log que nadie leía (docs/MOVA_SYSTEM_MAP.md R-04). Estos tests fijan las dos
 * propiedades que hacen que el aviso sea útil en vez de ruido:
 *
 *   1. La incidencia LLEGA a un administrador.
 *   2. La misma incidencia NO vuelve a llegar mientras siga abierta.
 */
class OperationalAlertTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::findOrCreate('admin');

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function service(): OperationalAlertService
    {
        return app(OperationalAlertService::class);
    }

    public function test_raising_an_alert_notifies_administrators(): void
    {
        Notification::fake();
        $admin = $this->admin();

        $alert = $this->service()->raise(
            key: 'payment_order:1:review',
            type: OperationalAlert::TYPE_PAYMENT_REVIEW,
            title: 'Pago en revisión',
            message: 'Requiere decisión humana.',
        );

        $this->assertNotNull($alert);
        $this->assertDatabaseHas('operational_alerts', [
            'alert_key' => 'payment_order:1:review',
            'occurrences' => 1,
            'resolved_at' => null,
        ]);
        $this->assertNotNull($alert->notified_at);

        Notification::assertSentTo($admin, OperationalAlertNotification::class);
    }

    /**
     * LA GARANTÍA ANTI-SPAM. `mercadopago:reconcile` corre cada 5 minutos y
     * puede reencontrar la misma anomalía indefinidamente: eso son 288
     * detecciones al día del mismo problema.
     */
    public function test_the_same_incident_is_never_notified_twice(): void
    {
        Notification::fake();
        $admin = $this->admin();

        foreach (range(1, 20) as $ignored) {
            $this->service()->raise(
                key: 'payment_order:1:review',
                type: OperationalAlert::TYPE_PAYMENT_REVIEW,
                title: 'Pago en revisión',
                message: 'Requiere decisión humana.',
            );
        }

        $this->assertSame(1, OperationalAlert::count(), 'Una incidencia es UNA fila, no N.');
        $this->assertSame(20, OperationalAlert::first()->occurrences);

        Notification::assertSentToTimes($admin, OperationalAlertNotification::class, 1);
    }

    public function test_two_stale_claimants_cannot_notify_the_same_incident_twice(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $alert = OperationalAlert::create([
            'alert_key' => 'concurrent:review',
            'type' => OperationalAlert::TYPE_PAYMENT_REVIEW,
            'severity' => 'warning',
            'title' => 'Concurrent review',
            'message' => 'Test fixture',
            'first_detected_at' => now(),
            'last_detected_at' => now(),
        ]);
        $otherClaimant = $alert->fresh();
        $notify = new \ReflectionMethod(OperationalAlertService::class, 'notifyAdmins');
        $notify->invoke($this->service(), $alert);
        $notify->invoke($this->service(), $otherClaimant);

        Notification::assertSentToTimes($admin, OperationalAlertNotification::class, 1);
        $this->assertNotNull($alert->fresh()->notified_at);
    }

    public function test_json_health_check_publishes_and_resolves_alerts(): void
    {
        Notification::fake();
        $this->admin();
        config(['payments.provider' => 'invalid-provider']);
        $this->artisan('mova:health-check --json --alert')->assertFailed();
        $this->assertGreaterThan(0, OperationalAlert::open()->count());
        config(['payments.provider' => 'fake']);
        $this->artisan('mova:health-check --json --alert')->assertSuccessful();
        $this->assertSame(0, OperationalAlert::open()->count());
    }

    public function test_an_incident_that_returns_after_being_resolved_notifies_again(): void
    {
        Notification::fake();
        $admin = $this->admin();

        $this->service()->raise(
            key: 'ledger:anomaly',
            type: OperationalAlert::TYPE_LEDGER_ANOMALY,
            title: 'Ledger descuadrado',
            message: 'No cuadra.',
        );

        $this->service()->resolve('ledger:anomaly');

        $this->assertNotNull(OperationalAlert::first()->resolved_at);

        // Vuelve a pasar: es información NUEVA, no ruido.
        $this->service()->raise(
            key: 'ledger:anomaly',
            type: OperationalAlert::TYPE_LEDGER_ANOMALY,
            title: 'Ledger descuadrado',
            message: 'No cuadra otra vez.',
        );

        $reopened = OperationalAlert::first();
        $this->assertNull($reopened->resolved_at, 'Debe reabrirse.');
        $this->assertSame(1, $reopened->occurrences, 'El contador arranca de nuevo en el episodio nuevo.');

        Notification::assertSentToTimes($admin, OperationalAlertNotification::class, 2);
    }

    public function test_resolving_an_unknown_or_already_resolved_key_is_a_no_op(): void
    {
        Notification::fake();
        $this->admin();

        // Puede llamarse incondicionalmente desde el camino feliz.
        $this->service()->resolve('no:existe');
        $this->service()->resolve('no:existe');

        $this->assertSame(0, OperationalAlert::count());
    }

    /**
     * raise() se invoca desde DENTRO de transacciones financieras
     * (markReview()). Si alertar lanzara, tumbaría una operación de dinero
     * legítima por un fallo de observabilidad.
     */
    public function test_raising_never_throws_into_the_caller(): void
    {
        Notification::fake();
        $this->admin();

        // NAN no es serializable a JSON, así que el cast `array` de la columna
        // `context` lanza JsonEncodingException DENTRO de la transacción de
        // raise(). Es el fallo más limpio que se puede provocar aquí:
        //
        //   - Idéntico en SQLite y en MySQL (no depende del motor).
        //   - NO toca el esquema. La versión anterior de este test hacía
        //     `Schema::drop('operational_alerts')`, que sobre SQLite en memoria
        //     es inocuo pero sobre MySQL provoca un COMMIT IMPLÍCITO que
        //     destruye el savepoint de RefreshDatabase ("SAVEPOINT trans2 does
        //     not exist") y deja en rojo a los tests siguientes. Es exactamente
        //     el defecto que ya arrastraban SchedulerConfigurationTest y
        //     WhatsAppConsentTest en el perfil MySQL (7 errores preexistentes
        //     al iniciar la Fase 2A). No se replica aquí.
        $result = $this->service()->raise(
            key: 'payment_order:1:review',
            type: OperationalAlert::TYPE_PAYMENT_REVIEW,
            title: 'Título',
            message: 'Mensaje',
            context: ['valor' => NAN],
        );

        $this->assertNull($result, 'Devuelve null en vez de propagar la excepción.');
        $this->assertSame(0, OperationalAlert::count(), 'La transacción se revierte entera.');
    }

    public function test_an_alert_without_any_administrator_does_not_explode(): void
    {
        Notification::fake();
        Role::findOrCreate('admin');
        // Deliberadamente sin ningún usuario admin.

        $alert = $this->service()->raise(
            key: 'health:SOMETHING',
            type: OperationalAlert::TYPE_HEALTH_CHECK,
            title: 'Algo',
            message: 'Algo pasa.',
        );

        $this->assertNotNull($alert);
        Notification::assertNothingSent();
    }

    public function test_critical_alerts_are_distinguishable_from_warnings(): void
    {
        Notification::fake();
        $this->admin();

        $this->service()->raise(
            key: 'a:1', type: OperationalAlert::TYPE_LESSON_NEEDS_REVIEW,
            title: 'Aviso', message: 'm',
            severity: OperationalAlert::SEVERITY_WARNING,
        );
        $this->service()->raise(
            key: 'b:1', type: OperationalAlert::TYPE_PAYMENT_REVIEW,
            title: 'Crítico', message: 'm',
            severity: OperationalAlert::SEVERITY_CRITICAL,
        );

        $this->assertSame(1, OperationalAlert::query()->open()->critical()->count());
        $this->assertSame(2, OperationalAlert::query()->open()->count());
    }

    /**
     * El contexto viaja al correo. Debe poder consultarse, pero no debe
     * arrastrar estructuras anidadas ni payloads completos del proveedor.
     */
    public function test_context_is_persisted_for_diagnosis(): void
    {
        Notification::fake();
        $this->admin();

        $alert = $this->service()->raise(
            key: 'payment_order:9:review',
            type: OperationalAlert::TYPE_PAYMENT_REVIEW,
            title: 'Pago en revisión',
            message: 'm',
            context: ['PaymentOrder' => 9, 'Motivo' => 'monto no coincide'],
        );

        $this->assertSame(9, $alert->context['PaymentOrder']);
        $this->assertSame('monto no coincide', $alert->context['Motivo']);
    }
}
