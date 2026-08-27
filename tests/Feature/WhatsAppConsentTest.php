<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ClassReminderNotification;
use App\WhatsApp\Contracts\WhatsAppProviderContract;
use App\WhatsApp\FakeWhatsAppProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Consentimiento de WhatsApp — gap abierto desde la Fase 4.
 *
 * WhatsAppChannel solo comprobaba `phone_verified_at`. Verificar que alguien
 * controla un número no es lo mismo que aceptar recibir mensajes en él: son
 * dos hechos distintos, y MOVA debía poder justificar por qué escribe a una
 * persona concreta.
 *
 * La distinción crítica que estos tests protegen: el OTP de verificación NO
 * debe quedar bloqueado por el consentimiento, porque es precisamente el paso
 * donde se obtiene. Si se rompiera, nadie podría verificar su teléfono.
 */
class WhatsAppConsentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'teacher', 'parent'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        config([
            'services.whatsapp.enabled' => true,
            'services.whatsapp.provider' => 'fake',
            'services.whatsapp.require_verified' => true,
            'services.meta_whatsapp.templates.generic_notification.name' => 'mova_generic',
            'services.meta_whatsapp.templates.phone_verification_code.name' => 'mova_otp',
        ]);
    }

    private function fakeProvider(): FakeWhatsAppProvider
    {
        return app(WhatsAppProviderContract::class);
    }

    private function verifiedUser(array $attributes = []): User
    {
        // 'phone_verified_normalized' extraído ANTES del merge para poder
        // pasarlo como override — UNIQUE en BD, así que dos llamadas en el
        // mismo test (p. ej. comparando dos usuarios distintos) necesitan
        // números distintos; por defecto sigue siendo el de siempre.
        $normalized = $attributes['phone_verified_normalized'] ?? '+51987654321';
        unset($attributes['phone_verified_normalized']);

        $user = User::factory()->create(array_merge([
            'password' => 'password',
            'phone' => '987654321',
            'phone_verified_at' => now(),
            'whatsapp_opt_in_at' => now(),
        ], $attributes));
        $user->assignRole('parent');
        $user->phone_verified_normalized = $normalized;
        $user->save();

        return $user->fresh();
    }

    // ── El modelo de consentimiento ──────────────────────────────────────

    public function test_a_verified_phone_alone_is_not_consent(): void
    {
        // El corazón del hallazgo: antes, esto bastaba para recibir mensajes.
        $user = $this->verifiedUser(['whatsapp_opt_in_at' => null]);

        $this->assertFalse($user->wantsWhatsAppNotifications());
    }

    public function test_opting_in_grants_consent(): void
    {
        $user = $this->verifiedUser(['whatsapp_opt_in_at' => null]);

        $user->optInToWhatsApp();

        $this->assertTrue($user->fresh()->wantsWhatsAppNotifications());
        $this->assertNotNull($user->fresh()->whatsapp_opt_in_at);
    }

    public function test_opting_out_revokes_consent(): void
    {
        $user = $this->verifiedUser();

        $user->optOutOfWhatsApp();

        $this->assertFalse($user->fresh()->wantsWhatsAppNotifications());
    }

    public function test_an_opt_out_wins_over_an_earlier_opt_in(): void
    {
        // La última voluntad expresada manda: un opt_in antiguo no debe
        // resucitar el consentimiento tras una baja.
        $user = $this->verifiedUser([
            'whatsapp_opt_in_at' => now()->subDays(30),
            'whatsapp_opt_out_at' => now()->subDay(),
        ]);

        $this->assertFalse($user->wantsWhatsAppNotifications());
    }

    // ── El canal respeta el consentimiento ───────────────────────────────

    public function test_a_notification_is_not_sent_without_consent(): void
    {
        $user = $this->verifiedUser(['whatsapp_opt_in_at' => null]);

        $user->notify(new ClassReminderNotification($this->stubLesson(), '24h'));

        $this->assertCount(0, $this->fakeProvider()->sent);
    }

    public function test_a_notification_is_sent_with_consent(): void
    {
        $user = $this->verifiedUser();

        $user->notify(new ClassReminderNotification($this->stubLesson(), '24h'));

        $this->assertCount(1, $this->fakeProvider()->sent);
    }

    // ── Cuenta suspendida: gate de cuenta, no de preferencia ──────────────
    //
    // EnsureNotSuspended solo protege las rutas HTTP que ESE usuario visita
    // — no protege los jobs en segundo plano que le escriben A él. Antes de
    // esta ronda, un usuario suspendido con opt-in activo seguía recibiendo
    // recordatorios de WhatsApp con normalidad, sin ningún gate que lo
    // impidiera.

    public function test_a_suspended_user_does_not_receive_whatsapp_notifications_even_with_consent(): void
    {
        $user = $this->verifiedUser(['suspended_at' => now()]);

        $user->notify(new ClassReminderNotification($this->stubLesson(), '24h'));

        $this->assertCount(0, $this->fakeProvider()->sent);
    }

    public function test_a_suspended_users_skip_is_audited_with_its_own_structured_reason(): void
    {
        // `error` queda reservado para fallos REALES del proveedor — un skip
        // nunca llega a Meta, así que aquí siempre debe ser null. El motivo
        // vive en `skip_reason`, estructurado (App\WhatsApp\WhatsAppSkipReason),
        // no en texto libre.
        $user = $this->verifiedUser(['suspended_at' => now()]);

        $user->notify(new ClassReminderNotification($this->stubLesson(), '24h'));

        $this->assertDatabaseHas('whatsapp_messages', [
            'status' => 'skipped',
            'skip_reason' => \App\WhatsApp\WhatsAppSkipReason::Suspended->value,
            'error' => null,
            'provider_message_id' => null,
        ]);
    }

    public function test_skip_reason_distinguishes_opt_out_from_suspended(): void
    {
        // El punto central de esta corrección: antes de F-22 solo existía un
        // motivo de skip, y reutilizar `error` como texto libre era una
        // decisión válida para ESE caso. F-22 introdujo un segundo motivo
        // real y semánticamente distinto — esta prueba fija que ambos casos
        // quedan diferenciados de forma estructurada, no adivinable por
        // substring sobre texto libre.
        $optedOutUser = $this->verifiedUser(['phone_verified_normalized' => '+51900000010']);
        $optedOutUser->optOutOfWhatsApp();
        $optedOutUser->fresh()->notify(new ClassReminderNotification($this->stubLesson(), '24h'));

        $suspendedUser = $this->verifiedUser(['suspended_at' => now(), 'phone_verified_normalized' => '+51900000011']);
        $suspendedUser->notify(new ClassReminderNotification($this->stubLesson(), '24h'));

        $this->assertDatabaseHas('whatsapp_messages', [
            'client_reference' => 'ClassReminderNotification#'.$optedOutUser->id,
            'skip_reason' => \App\WhatsApp\WhatsAppSkipReason::OptOut->value,
        ]);
        $this->assertDatabaseHas('whatsapp_messages', [
            'client_reference' => 'ClassReminderNotification#'.$suspendedUser->id,
            'skip_reason' => \App\WhatsApp\WhatsAppSkipReason::Suspended->value,
        ]);
    }

    // ── Race de cola para el gate de suspensión (mismo patrón que opt-in/opt-out) ──

    public function test_a_suspension_between_queueing_and_processing_prevents_the_send(): void
    {
        // Escenario real: 20:00 activo + consentimiento, se encola el aviso;
        // 20:01 un admin lo suspende; 20:02 el worker procesa el job. Debe
        // quedar en skipped(suspended) — el gate se evalúa contra el estado
        // ACTUAL en BD al ejecutarse, no el que tenía al encolarse. Mismo
        // ciclo de serialización real (QUEUE_CONNECTION=database) que ya
        // prueban los tests de opt-in/opt-out.
        config(['queue.default' => 'database']);
        $user = $this->verifiedUser();

        $user->notify(new ClassReminderNotification($this->stubLesson(), '24h'));
        $user->update(['suspended_at' => now()]); // 20:01 — suspendido DESPUÉS de encolar.

        $this->runOneQueuedJob();

        $this->assertCount(0, $this->fakeProvider()->sent);
        $this->assertDatabaseHas('whatsapp_messages', [
            'status' => 'skipped',
            'skip_reason' => \App\WhatsApp\WhatsAppSkipReason::Suspended->value,
        ]);
    }

    public function test_an_unsuspension_between_queueing_and_processing_is_honored(): void
    {
        // Caso simétrico, pedido explícitamente: 20:00 suspendido, se encola
        // igual (via() solo mira el teléfono verificado, no la suspensión);
        // 20:01 un admin lo reactiva; 20:02 el worker procesa. Debe enviarse
        // — el estado en el momento de la ejecución es activo + con
        // consentimiento, misma filosofía que ya se aplica al opt-in tardío.
        config(['queue.default' => 'database']);
        $user = $this->verifiedUser(['suspended_at' => now()]);

        $user->notify(new ClassReminderNotification($this->stubLesson(), '24h'));
        $user->update(['suspended_at' => null]); // 20:01 — reactivado antes de que el worker lo tome.

        $this->runOneQueuedJob();

        $this->assertCount(1, $this->fakeProvider()->sent);
        $this->assertDatabaseMissing('whatsapp_messages', ['status' => 'skipped']);
    }

    public function test_a_notification_stops_after_opting_out(): void
    {
        $user = $this->verifiedUser();
        $user->optOutOfWhatsApp();

        $user->fresh()->notify(new ClassReminderNotification($this->stubLesson(), '24h'));

        $this->assertCount(0, $this->fakeProvider()->sent);
    }

    // ── El opt-out deja rastro de auditoría — nunca como "failed" ────────

    public function test_an_opt_out_skip_is_recorded_with_its_own_status(): void
    {
        // Antes de este cambio, este caso no dejaba NINGUNA fila en
        // whatsapp_messages — solo un log de debug. Alguien investigando
        // "¿por qué este padre no recibió su recordatorio?" no tenía ningún
        // rastro consultable en la tabla de auditoría.
        $user = $this->verifiedUser();
        $user->optOutOfWhatsApp();

        $user->fresh()->notify(new ClassReminderNotification($this->stubLesson(), '24h'));

        $this->assertDatabaseHas('whatsapp_messages', [
            'status' => \App\WhatsApp\WhatsAppMessageStatus::Skipped->value,
        ]);

        $row = \App\Models\WhatsAppMessage::where('status', 'skipped')->firstOrFail();
        $this->assertSame(\App\WhatsApp\WhatsAppSkipReason::OptOut, $row->skip_reason);
        $this->assertNull($row->error, '`error` queda reservado para fallos reales del proveedor, no para el motivo de un skip.');
    }

    public function test_a_skip_is_never_recorded_as_a_provider_failure(): void
    {
        // El punto central del hallazgo: un opt-out NO es un fallo de Meta.
        // Confundirlos en las métricas de salud haría parecer un problema
        // técnico lo que en realidad es una tasa de adopción del opt-in.
        $status = \App\WhatsApp\WhatsAppMessageStatus::Skipped;

        $this->assertFalse($status->isProviderFailure());
        $this->assertTrue(\App\WhatsApp\WhatsAppMessageStatus::Failed->isProviderFailure());
    }

    /**
     * Las 4 invariantes pedidas explícitamente, verificadas en una sola pasada
     * sobre el flujo real (no solo leyendo el código):
     *
     *   1. provider_message_id queda NULL — Meta nunca se enteró del mensaje.
     *   2. isProviderFailure() es false — no es un fallo del proveedor.
     *   3. El proveedor Fake nunca fue invocado — cero intentos, cero reintentos
     *      posibles (un reintento solo puede originarse de una excepción del
     *      job encolado; aquí el método retorna normalmente, no lanza nada).
     *   4. mova:reconcile-whatsapp sigue "healthy" — no lo trata como anomalía.
     */
    public function test_the_four_skip_invariants_hold_together(): void
    {
        $user = $this->verifiedUser();
        $user->optOutOfWhatsApp();

        $user->fresh()->notify(new ClassReminderNotification($this->stubLesson(), '24h'));

        $row = \App\Models\WhatsAppMessage::where('status', 'skipped')->firstOrFail();

        $this->assertNull($row->provider_message_id, '1. Meta nunca recibió el mensaje: no puede haber un id suyo.');
        $this->assertFalse($row->status->isProviderFailure(), '2. Un skip no es un fallo del proveedor.');
        $this->assertCount(0, $this->fakeProvider()->sent, '3. El proveedor jamás debió ser invocado.');
        $this->assertTrue(
            app(\App\Support\WhatsAppReconciliation::class)->run()['healthy'],
            '4. El reconciliador no debe marcar el skip como anomalía.'
        );
    }

    public function test_a_prior_opt_out_does_not_prevent_a_later_opt_in_from_being_honored(): void
    {
        // Escenario simétrico pedido explícitamente: 20:00 el usuario está de
        // baja, 20:01 se encola un aviso (via() no mira el consentimiento, solo
        // el teléfono verificado — por eso el job SÍ se encola), 20:02 el
        // usuario reactiva antes de que el worker lo procese. Debe enviarse:
        // el estado se evalúa en el momento de la ejecución, sin importar cuál
        // fuera la historia previa de opt-out/opt-in.
        config(['queue.default' => 'database']);
        $user = $this->verifiedUser(['whatsapp_opt_in_at' => null]);
        $user->optOutOfWhatsApp(); // 20:00 — de baja desde antes.

        $user->fresh()->notify(new ClassReminderNotification($this->stubLesson(), '24h')); // 20:01 — se encola.
        $user->fresh()->optInToWhatsApp(); // 20:02 — reactiva antes de que el worker lo tome.

        $this->runOneQueuedJob();

        $this->assertCount(1, $this->fakeProvider()->sent);
        $this->assertDatabaseMissing('whatsapp_messages', ['status' => 'skipped']);
    }

    public function test_skipped_messages_do_not_trigger_reconciliation_alerts(): void
    {
        // mova:reconcile-whatsapp no debe marcar 'skipped' como algo que
        // necesita atención humana: es la decisión correcta funcionando, no
        // una entrega atascada.
        $user = $this->verifiedUser();
        $user->optOutOfWhatsApp();
        $user->fresh()->notify(new ClassReminderNotification($this->stubLesson(), '24h'));

        $report = app(\App\Support\WhatsAppReconciliation::class)->run();

        $this->assertTrue($report['healthy']);
        $this->assertArrayHasKey('skipped', $report['counts']);
    }

    public function test_missing_phone_does_not_write_an_audit_row(): void
    {
        // A diferencia del opt-out, este caso no tiene un `to` válido que
        // registrar (whatsapp_messages.to no es nullable) y no es la
        // ambigüedad que este cambio resuelve — sigue siendo solo un log.
        $user = User::factory()->create(['phone' => null, 'phone_verified_at' => null]);
        $user->assignRole('parent');

        $countBefore = \App\Models\WhatsAppMessage::count();
        $user->notify(new ClassReminderNotification($this->stubLesson(), '24h'));

        $this->assertSame($countBefore, \App\Models\WhatsAppMessage::count());
    }

    // ── El OTP NUNCA queda bloqueado por el consentimiento ───────────────

    public function test_the_verification_otp_is_sent_even_without_consent(): void
    {
        // CRÍTICO: si el gate de consentimiento alcanzara al OTP, nadie podría
        // verificar su teléfono, porque el consentimiento se obtiene
        // justamente al completar esa verificación.
        $user = User::factory()->create([
            'password' => 'password',
            'phone' => '987111222',
            'phone_verified_at' => null,
            'whatsapp_opt_in_at' => null,
        ]);
        $user->assignRole('parent');

        $this->actingAs($user)
            ->post(route('phone.verification.send'), ['phone' => '987111222'])
            ->assertRedirect();

        $this->assertCount(1, $this->fakeProvider()->sent, 'El OTP debe salir sin consentimiento previo.');
    }

    public function test_the_otp_is_sent_even_after_opting_out(): void
    {
        // Alguien que se dio de baja de las notificaciones debe poder seguir
        // verificando su teléfono: es seguridad, no marketing.
        $user = User::factory()->create([
            'password' => 'password',
            'phone' => '987333444',
            'phone_verified_at' => null,
            'whatsapp_opt_out_at' => now(),
        ]);
        $user->assignRole('parent');

        $this->actingAs($user)->post(route('phone.verification.send'), ['phone' => '987333444']);

        $this->assertCount(1, $this->fakeProvider()->sent);
    }

    // ── Verificar el teléfono registra el consentimiento ─────────────────

    public function test_verifying_the_phone_alone_does_not_grant_consent(): void
    {
        // EL PUNTO CENTRAL de este bloque: verificar el teléfono es
        // autenticación (demuestra que controla el número). NO es lo mismo
        // que aceptar recibir notificaciones. Antes verify() fijaba
        // whatsapp_opt_in_at=now() automáticamente; ahora exige la casilla.
        $user = User::factory()->create([
            'password' => 'password',
            'phone' => '987555666',
            'phone_verified_at' => null,
            'whatsapp_opt_in_at' => null,
            'phone_verification_code_hash' => Hash::make('123456'),
            'phone_verification_expires_at' => now()->addMinutes(10),
        ]);
        $user->assignRole('parent');

        $this->actingAs($user)->post(route('phone.verification.verify'), ['code' => '123456']);

        $user->refresh();
        $this->assertNotNull($user->phone_verified_at, 'El teléfono sí debe quedar verificado.');
        $this->assertNull($user->whatsapp_opt_in_at, 'Pero NO debe haber consentimiento sin la casilla explícita.');
        $this->assertFalse($user->wantsWhatsAppNotifications());
    }

    public function test_checking_the_box_during_verification_records_explicit_consent(): void
    {
        $user = User::factory()->create([
            'password' => 'password',
            'phone' => '987555999',
            'phone_verified_at' => null,
            'whatsapp_opt_in_at' => null,
            'phone_verification_code_hash' => Hash::make('123456'),
            'phone_verification_expires_at' => now()->addMinutes(10),
        ]);
        $user->assignRole('parent');

        $this->actingAs($user)->post(route('phone.verification.verify'), [
            'code' => '123456',
            'whatsapp_notifications' => true,
        ]);

        $user->refresh();
        $this->assertNotNull($user->whatsapp_opt_in_at);
        $this->assertTrue($user->wantsWhatsAppNotifications());
    }

    public function test_reverifying_does_not_resurrect_consent_after_an_opt_out(): void
    {
        // Volver a verificar el teléfono no debe reactivar el consentimiento
        // por la puerta de atrás, ni siquiera si la casilla llega marcada.
        $user = User::factory()->create([
            'password' => 'password',
            'phone' => '987777888',
            'phone_verified_at' => null,
            'whatsapp_opt_out_at' => now()->subDay(),
            'phone_verification_code_hash' => Hash::make('123456'),
            'phone_verification_expires_at' => now()->addMinutes(10),
        ]);
        $user->assignRole('parent');

        $this->actingAs($user)->post(route('phone.verification.verify'), [
            'code' => '123456',
            'whatsapp_notifications' => true,
        ]);

        $this->assertFalse($user->fresh()->wantsWhatsAppNotifications());
    }

    public function test_existing_verified_users_are_not_silently_opted_in(): void
    {
        // GAP real detectado y corregido: una versión anterior de la migración
        // hacía whatsapp_opt_in_at = phone_verified_at para todos los usuarios
        // ya verificados. Eso equiparaba "verificó su número" con "aceptó
        // marketing/notificaciones" sin que el usuario hiciera nada — es
        // exactamente el error que este modelo de consentimiento existe para
        // evitar. Los usuarios preexistentes deben quedar SIN consentimiento.
        $legacyUser = User::factory()->create([
            'phone_verified_at' => now()->subMonths(3),
            'whatsapp_opt_in_at' => null,
        ]);

        $this->assertFalse($legacyUser->wantsWhatsAppNotifications());
    }

    // ── Consentimiento evaluado en el momento del ENVÍO, no de la creación ─

    public function test_an_opt_out_between_queueing_and_processing_prevents_the_send(): void
    {
        // Escenario real: 20:00 el usuario tiene consentimiento y se encola
        // el aviso; 20:01 se da de baja; 20:02 el worker procesa el job.
        //
        // Con QUEUE_CONNECTION=database el job persiste al notifiable como una
        // referencia (ModelIdentifier), no como un snapshot — al ejecutarse, el
        // worker RELEE el modelo desde la base de datos. El consentimiento se
        // evalúa entonces con el estado más reciente, no con el que tenía al
        // encolarse. Se fuerza el driver de cola real (no el "sync" de los
        // tests) para probar exactamente ese ciclo de serialización.
        config(['queue.default' => 'database']);
        $user = $this->verifiedUser();

        $user->notify(new ClassReminderNotification($this->stubLesson(), '24h'));
        $this->assertGreaterThan(0, \Illuminate\Support\Facades\DB::table('jobs')->count(), 'Debe haber quedado encolado.');

        // 20:01 — se da de baja DESPUÉS de encolar, ANTES de procesar.
        $user->optOutOfWhatsApp();

        $this->runOneQueuedJob();

        $this->assertCount(
            0,
            $this->fakeProvider()->sent,
            'El opt-out debe respetarse aunque el job ya estuviera en cola: se evalúa al ejecutarse, no al crearse.'
        );
    }

    public function test_an_opt_in_between_queueing_and_processing_is_honored(): void
    {
        // Caso simétrico: si el usuario acepta DESPUÉS de que algo se encolara
        // (ej. quedó pendiente sin consentimiento y lo activa antes de que el
        // worker lo tome), el envío sí debe salir — la misma relectura fresca
        // que protege el opt-out también permite este caso.
        config(['queue.default' => 'database']);
        $user = $this->verifiedUser(['whatsapp_opt_in_at' => null]);

        $user->notify(new ClassReminderNotification($this->stubLesson(), '24h'));
        $user->optInToWhatsApp();

        $this->runOneQueuedJob();

        $this->assertCount(1, $this->fakeProvider()->sent);
    }

    /**
     * Drena y ejecuta TODOS los jobs pendientes de la cola real, sin arrancar
     * el bucle completo de `queue:work`. Ese comando es un worker de larga
     * duración pensado para producción — invocarlo vía Artisan::call() en un
     * test es lento (30s+ observado) y puede contaminar el estado de tests
     * posteriores en el mismo proceso de PHPUnit.
     *
     * DRENAR TODO, no solo uno: `via()` encola un job POR CANAL (database,
     * mail, whatsapp — comprobado; es el mismo hallazgo de fases anteriores de
     * esta sesión sobre NotificationSender::queueNotification()). Procesar
     * solo el primero de la cola habría sido processar el job de 'database' y
     * dar por bueno el test sin haber tocado siquiera el de WhatsApp — un test
     * que pasa por casualidad, no por verificar lo que dice verificar.
     *
     * `Queue::pop()` + `$job->fire()` pasa por el mismo ciclo de
     * serialización/deserialización que un worker real: el `notifiable` se
     * reconstruye desde su `ModelIdentifier`, es decir, se RELEE de la base de
     * datos — que es precisamente el comportamiento que estos tests verifican.
     */
    private function runOneQueuedJob(): void
    {
        $connection = app('queue')->connection('database');
        $processed = 0;

        while ($job = $connection->pop()) {
            $job->fire();
            $processed++;
        }

        $this->assertGreaterThan(0, $processed, 'No había ningún job en la cola para procesar.');
    }

    // ── El endpoint de preferencias ──────────────────────────────────────

    public function test_a_user_can_opt_out_through_the_endpoint(): void
    {
        $user = $this->verifiedUser();

        $this->actingAs($user)
            ->patch(route('profile.notifications.update'), ['whatsapp' => false])
            ->assertRedirect();

        $this->assertFalse($user->fresh()->wantsWhatsAppNotifications());
    }

    public function test_a_user_can_opt_back_in_through_the_endpoint(): void
    {
        $user = $this->verifiedUser();
        $user->optOutOfWhatsApp();

        $this->actingAs($user)->patch(route('profile.notifications.update'), ['whatsapp' => true]);

        $this->assertTrue($user->fresh()->wantsWhatsAppNotifications());
    }

    public function test_a_guest_cannot_change_notification_preferences(): void
    {
        $this->patch(route('profile.notifications.update'), ['whatsapp' => false])
            ->assertRedirect(route('login'));
    }

    // ── El toggle sobrevive a un refresh de página real ───────────────────

    public function test_the_opt_in_state_survives_a_full_page_reload(): void
    {
        // No basta que el PATCH devuelva éxito: hay que confirmar que una
        // carga NUEVA de /profile (no la misma sesión de request) refleja el
        // estado guardado, vía la prop compartida que lee la UI
        // (auth.user.whatsapp_opt_in en HandleInertiaRequests).
        $user = $this->verifiedUser(['whatsapp_opt_in_at' => null]);

        $this->actingAs($user)->patch(route('profile.notifications.update'), ['whatsapp' => true]);

        $response = $this->actingAs($user)->get(route('profile.edit'));

        $response->assertInertia(fn ($page) => $page->where('auth.user.whatsapp_opt_in', true));
    }

    public function test_the_opt_out_state_survives_a_full_page_reload(): void
    {
        $user = $this->verifiedUser();

        $this->actingAs($user)->patch(route('profile.notifications.update'), ['whatsapp' => false]);

        $response = $this->actingAs($user)->get(route('profile.edit'));

        $response->assertInertia(fn ($page) => $page->where('auth.user.whatsapp_opt_in', false));
    }

    // ── Un usuario verificado ANTES de este cambio también puede activarlo ─

    public function test_an_account_verified_long_before_this_feature_existed_can_still_opt_in(): void
    {
        // Simula exactamente el caso que preocupa: una cuenta que verificó su
        // teléfono hace meses, cuando la casilla de consentimiento ni existía,
        // y que —por el fix de esta ronda— quedó sin whatsapp_opt_in_at. Debe
        // poder encontrar la sección de preferencias en su perfil y activarla
        // ahí, sin depender de volver a pasar por la verificación de teléfono.
        $legacyUser = User::factory()->create([
            'phone' => '987000111',
            'phone_verified_at' => now()->subMonths(6),
            'phone_verified_normalized' => '+51987000111',
            'whatsapp_opt_in_at' => null,
        ]);
        $legacyUser->assignRole('parent');

        $response = $this->actingAs($legacyUser)->get(route('profile.edit'));

        // La prop que la UI necesita para decidir si mostrar el toggle
        // activado o no, y que confirma que la sección es alcanzable sin pasar
        // de nuevo por /verify-phone.
        $response->assertInertia(fn ($page) => $page
            ->where('auth.user.phone_verified', true)
            ->where('auth.user.whatsapp_opt_in', false));

        $this->actingAs($legacyUser)
            ->patch(route('profile.notifications.update'), ['whatsapp' => true])
            ->assertRedirect();

        $this->assertTrue($legacyUser->fresh()->wantsWhatsAppNotifications());
    }

    // ── Guardián: nadie más puede evadir el gate de consentimiento ────────

    public function test_only_whatsapp_channel_and_the_otp_call_the_provider_directly(): void
    {
        // Búsqueda global fijada como test: si un admin controller, un job, un
        // comando o un futuro caso de uso llamara a sendTemplate() sin pasar
        // por WhatsAppChannel, evadiría el consentimiento sin que nadie lo
        // notara. Hoy solo hay DOS llamadores en todo `app/`, y el segundo es
        // el OTP, deliberadamente exento (ver el docblock de WhatsAppChannel).
        // Si este test falla, alguien añadió un tercer camino: revisar si
        // también necesita respetar wantsWhatsAppNotifications().
        $callers = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            if (str_contains($source, '->sendTemplate(')) {
                $callers[] = str_replace(app_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }

        $allowed = [
            'Channels'.DIRECTORY_SEPARATOR.'WhatsAppChannel.php',
            'Http'.DIRECTORY_SEPARATOR.'Controllers'.DIRECTORY_SEPARATOR.'Auth'.DIRECTORY_SEPARATOR.'PhoneVerificationController.php',
        ];

        $unexpected = array_diff($callers, $allowed);

        $this->assertSame(
            [],
            $unexpected,
            'Nuevo(s) llamador(es) de sendTemplate() fuera de los dos esperados: '.implode(', ', $unexpected)
            .'. Si es intencional, confirma si también debe respetar wantsWhatsAppNotifications().'
        );
    }

    // ── Helper ───────────────────────────────────────────────────────────

    private function stubLesson(): \App\Models\Lesson
    {
        $subject = \App\Models\Subject::create([
            'name' => 'Materia '.fake()->unique()->numerify('####'),
            'level' => 'secundaria',
        ]);
        $teacher = User::factory()->create();
        $profile = \App\Models\TeacherProfile::create([
            'user_id' => $teacher->id,
            'is_verified' => true,
            'credits_available' => 0,
            'credits_reserved' => 0,
        ]);
        $profile->subjects()->attach($subject->id);

        $parent = User::factory()->create();
        $student = \App\Models\Student::create([
            'parent_user_id' => $parent->id,
            'first_name' => 'Alumno',
            'last_name' => 'Prueba',
            'grade_level' => 'secundaria',
        ]);

        return \App\Models\Lesson::create([
            'teacher_profile_id' => $profile->id,
            'student_id' => $student->id,
            'start_time' => now()->addHours(24),
            'duration_minutes' => 60,
            'status' => 'scheduled',
        ]);
    }
}
