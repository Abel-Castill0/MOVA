<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * La portada pinta un Hero estático (resources/views/partials/welcome-shell.blade.php) en la primera respuesta
 * HTML, para que en un móvil lento el título, el subtítulo y los botones se vean antes de que llegue el JS.
 * resources/js/app.js lo retira al montar. Solo aplica a invitados en la portada: con sesión los botones del Hero
 * dependen del rol y el esqueleto mostraría acciones equivocadas.
 */
class WelcomeStaticHeroShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_home_ships_the_static_hero_with_working_links(): void
    {
        $html = $this->get(route('welcome'))->assertOk()->getContent();

        $this->assertStringContainsString('id="mova-shell"', $html);
        $this->assertStringContainsString('Aprende con', $html);
        $this->assertStringContainsString('Conectamos estudiantes con profesores particulares expertos', $html);
        $this->assertStringContainsString('href="'.route('marketplace').'"', $html);
        $this->assertStringContainsString('href="/register?role=teacher"', $html);
        // El esqueleto va fuera del contenedor de Inertia: Vue nunca lo toma como parte de la página.
        $this->assertGreaterThan(strpos($html, 'id="app"'), strpos($html, 'id="mova-shell"'));
    }

    public function test_signed_in_users_do_not_get_the_guest_hero(): void
    {
        Role::findOrCreate('parent');
        $user = User::factory()->create();
        $user->assignRole('parent');

        $html = $this->actingAs($user)->get(route('welcome'))->assertOk()->getContent();

        $this->assertStringNotContainsString('id="mova-shell"', $html);
    }

    public function test_other_public_pages_do_not_get_the_hero(): void
    {
        $html = $this->get(route('marketplace'))->assertOk()->getContent();

        $this->assertStringNotContainsString('id="mova-shell"', $html);
    }

    public function test_stats_in_the_static_hero_follow_the_same_threshold_as_the_page(): void
    {
        // Sin volumen (menos de 10 clases y 5 profesores) la página no muestra cifras; el esqueleto tampoco.
        $html = $this->get(route('welcome'))->assertOk()->getContent();

        $this->assertStringNotContainsString('clases dictadas', $html);
    }
}
