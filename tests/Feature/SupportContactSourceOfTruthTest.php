<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * C-P0-LEGAL-TRUTH: el correo de soporte/legal estaba repetido como literal
 * en 9 componentes Vue y 2 clases PHP. Una sola fuente: config/legal.php
 * (LEGAL_SUPPORT_EMAIL), compartida a Inertia como `support.email`.
 */
class SupportContactSourceOfTruthTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_receive_the_configured_support_email(): void
    {
        config(['legal.support_email' => 'soporte@example.test']);

        foreach (['legal.privacy', 'legal.terms', 'login'] as $route) {
            $this->get(route($route))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('support.email', 'soporte@example.test'));
        }
    }

    public function test_chatbot_unavailable_message_uses_the_configured_support_email(): void
    {
        config(['legal.support_email' => 'soporte@example.test', 'chatbot.enabled' => false]);

        $response = $this->postJson(route('chatbot.message'), ['message' => 'Hola']);

        $response->assertStatus(503);
        $this->assertStringContainsString('soporte@example.test', $response->json('message'));
    }

    public function test_no_frontend_file_hardcodes_a_support_email(): void
    {
        $offenders = [];
        foreach ((new Finder)->files()->in(resource_path('js'))->name(['*.vue', '*.js']) as $file) {
            if (str_contains($file->getContents(), 'm0v4class@gmail.com')) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, 'Usar $page.props.support.email en vez de literales.');
    }
}
