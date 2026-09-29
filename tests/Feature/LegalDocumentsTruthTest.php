<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * C-P0-LEGAL-TRUTH: Términos y Privacidad deben describir el runtime real.
 * Guardas baratas contra las contradicciones encontradas en C1 y contra que
 * la IA se active sin actualizar la política.
 */
class LegalDocumentsTruthTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_show_the_current_version_and_configured_provider_data(): void
    {
        config([
            'legal.versions.terms' => 'T-X', 'legal.versions.privacy' => 'P-X',
            'legal.provider.business_name' => 'Razón Ejemplo', 'legal.provider.ruc' => null, 'legal.provider.address' => null,
        ]);

        $this->get(route('legal.terms'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('version', 'T-X'));

        $this->get(route('legal.privacy'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('version', 'P-X')
                ->where('provider.business_name', 'Razón Ejemplo')
                ->where('provider.ruc', null));
    }

    public function test_documents_do_not_reintroduce_statements_contradicted_by_the_runtime(): void
    {
        $terms   = file_get_contents(resource_path('js/Pages/Legal/Terms.vue'));
        $privacy = file_get_contents(resource_path('js/Pages/Legal/Privacy.vue'));

        $forbidden = [
            'ofertas de clase'              => 'los profesores ya no crean ofertas',
            'tarifa'                        => 'no existe tarifa editable por el profesor',
            'archivos adjuntos'             => 'los reportes no admiten adjuntos',
            'al verificar el perfil'        => 'el bono se otorga al verificar el celular',
            'uso continuado'                => 'los cambios relevantes exigen re-aceptación',
            'notificados por email'         => 'no existe aviso previo por email de cambios legales',
            'Railway'                       => 'el alojamiento de producción es Azure',
            'no procesa ni almacena datos de pago' => 'MOVA registra operaciones de créditos',
            'MOVA utiliza'                  => 'la IA está desactivada',
            'después de aceptar una solicitud' => 'la minimización pre-aceptación se describe explícitamente',
        ];

        foreach ($forbidden as $phrase => $why) {
            $this->assertStringNotContainsStringIgnoringCase($phrase, $terms.$privacy, "{$phrase}: {$why}");
        }

        $this->assertStringContainsString('nombre de pila', $privacy);
        $this->assertStringContainsString('autorización específica', $privacy);
        $this->assertStringContainsString('no envía datos a servicios de inteligencia artificial', $privacy);
    }

    public function test_health_check_flags_ai_enabled_while_privacy_says_it_is_off(): void
    {
        $this->app['env'] = 'production';

        config(['chatbot.enabled' => true, 'diagnostic.ai_enabled' => false]);
        $this->artisan('mova:health-check')->expectsOutputToContain('LEGAL_PRIVACY_AI_MISMATCH');

        config(['chatbot.enabled' => false, 'diagnostic.ai_enabled' => true]);
        $this->artisan('mova:health-check')->expectsOutputToContain('LEGAL_PRIVACY_AI_MISMATCH');
    }

    public function test_health_check_is_quiet_about_ai_when_it_is_off(): void
    {
        $this->app['env'] = 'production';
        config(['chatbot.enabled' => false, 'diagnostic.ai_enabled' => false]);

        $this->artisan('mova:health-check')->doesntExpectOutputToContain('LEGAL_PRIVACY_AI_MISMATCH');
    }
}
