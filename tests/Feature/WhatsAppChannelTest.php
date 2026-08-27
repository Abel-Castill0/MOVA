<?php

namespace Tests\Feature;

use App\Channels\WhatsAppChannel;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Notifications\WelcomeTeacherNotification;
use App\WhatsApp\Contracts\WhatsAppProviderContract;
use App\WhatsApp\FakeWhatsAppProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Cubre la migración de WhatsAppChannel de Twilio a Meta Cloud API directo
 * (ver docs/whatsapp-architecture.md). No prueba MetaCloudApiProvider contra
 * la red real — eso se cubre por separado con Http::fake(). Aquí se
 * verifica que el canal respeta exactamente las mismas reglas de antes
 * (kill switch global, teléfono verificado, sin número) y que delega en el
 * WhatsAppProviderContract en vez de instanciar un SDK de proveedor
 * directamente. WelcomeTeacherNotification se usa en las 4 pruebas porque
 * SÍ implementa toWhatsApp() — a diferencia de RechargeApprovedNotification,
 * que no lo tiene y haría que el canal se salga antes de llegar a probar
 * nada de lo que realmente se quiere cubrir aquí.
 */
class WhatsAppChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('teacher', 'web');

        config([
            'services.whatsapp.enabled' => true,
            'services.whatsapp.require_verified' => true,
        ]);
    }

    public function test_sends_via_provider_wrapping_towhatsapp_text_in_generic_template(): void
    {
        $teacher = $this->verifiedTeacher();
        $notification = new WelcomeTeacherNotification();

        (new WhatsAppChannel())->send($teacher, $notification);

        $provider = app(WhatsAppProviderContract::class);
        $this->assertInstanceOf(FakeWhatsAppProvider::class, $provider);
        $this->assertCount(1, $provider->sent);
        $this->assertSame($teacher->phone, $provider->sent[0]['to']);
        $this->assertSame('generic_notification', $provider->sent[0]['template']);
        $this->assertSame([$notification->toWhatsApp($teacher)], $provider->sent[0]['params']);
        $this->assertSame('WelcomeTeacherNotification#'.$teacher->id, $provider->sent[0]['client_reference']);
    }

    /**
     * Un job de Notification en cola (ShouldQueue) solo se reintenta si
     * lanza una excepción. Si WhatsAppChannel dejara propagar un fallo del
     * proveedor como excepción, Laravel reintentaría el job — y ESE reintento
     * volvería a llamar a Meta, con riesgo real de duplicar el envío (Meta no
     * da una idempotency key propia). Esta prueba confirma que un envío
     * fallido en el proveedor NUNCA se convierte en una excepción del canal,
     * sin importar el motivo del fallo.
     */
    public function test_a_failed_provider_send_never_throws_so_the_queue_job_never_retries(): void
    {
        $teacher = $this->verifiedTeacher();
        app()->instance(WhatsAppProviderContract::class, new class implements WhatsAppProviderContract {
            public function sendTemplate(string $to, string $templateKey, array $params, ?string $clientReference = null): bool
            {
                return false; // simula que Meta rechazó/no se pudo enviar
            }
        });

        (new WhatsAppChannel())->send($teacher, new WelcomeTeacherNotification());

        $this->addToAssertionCount(1); // llegar aquí sin excepción ES la prueba
    }

    public function test_skipped_when_disabled_globally(): void
    {
        config(['services.whatsapp.enabled' => false]);
        $teacher = $this->verifiedTeacher();

        (new WhatsAppChannel())->send($teacher, new WelcomeTeacherNotification());

        $this->assertCount(0, app(WhatsAppProviderContract::class)->sent);
    }

    public function test_skipped_when_phone_not_verified(): void
    {
        $teacher = User::factory()->create(['phone' => '+51987654321', 'phone_verified_at' => null]);
        $teacher->assignRole('teacher');
        TeacherProfile::create(['user_id' => $teacher->id, 'is_verified' => true]);

        (new WhatsAppChannel())->send($teacher, new WelcomeTeacherNotification());

        $this->assertCount(0, app(WhatsAppProviderContract::class)->sent);
    }

    public function test_skipped_when_no_phone_number(): void
    {
        $teacher = User::factory()->create(['phone' => null, 'phone_verified_at' => now(), 'whatsapp_opt_in_at' => now()]);
        $teacher->assignRole('teacher');
        TeacherProfile::create(['user_id' => $teacher->id, 'is_verified' => true]);

        (new WhatsAppChannel())->send($teacher, new WelcomeTeacherNotification());

        $this->assertCount(0, app(WhatsAppProviderContract::class)->sent);
    }

    private function verifiedTeacher(): User
    {
        $teacher = User::factory()->create(['phone' => '+51987654321', 'phone_verified_at' => now(), 'whatsapp_opt_in_at' => now()]);
        $teacher->assignRole('teacher');
        TeacherProfile::create(['user_id' => $teacher->id, 'is_verified' => true]);

        return $teacher->fresh();
    }
}
