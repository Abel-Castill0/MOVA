<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\WelcomeEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regresión dirigida: la copia de bienvenida al profesor decía "Cree sus
 * ofertas de clase y responda solicitudes" — business truth obsoleta, los
 * profesores no crean ofertas en el flujo vigente (responden solicitudes
 * que ya existen). Confirma el texto real y evita que la frase vieja
 * vuelva a colarse.
 *
 * El reemplazo inicial ("Revisa y responde...") tuteaba, mientras el
 * resto del mismo correo usa "usted" ("Complete...", "Espere..."). Ahora
 * es "Revise y responda..." — consistente con el trato formal del correo.
 */
class WelcomeEmailNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['teacher', 'parent'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_teacher_welcome_email_does_not_mention_creating_class_offers(): void
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('teacher');

        $mail = (new WelcomeEmailNotification)->toMail($teacher);
        $lines = implode(' ', $mail->introLines);

        $this->assertStringNotContainsString('Cree sus ofertas', $lines);
        $this->assertStringNotContainsString('Revisa y responde', $lines, 'la forma tuteante no debe volver — el resto del correo usa "usted".');
        $this->assertStringContainsString('Revise y responda las solicitudes de clase disponibles.', $lines);
    }

    public function test_parent_welcome_email_is_unaffected(): void
    {
        $parent = User::factory()->create();
        $parent->assignRole('parent');

        $mail = (new WelcomeEmailNotification)->toMail($parent);
        $lines = implode(' ', $mail->introLines);

        $this->assertStringContainsString('Solicite una clase', $lines);
    }
}
