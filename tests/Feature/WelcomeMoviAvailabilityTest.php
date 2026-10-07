<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * C-P1-MOVI: la home no debe ofrecer un asistente que solo respondería
 * "no disponible". Welcome recibe chatbotEnabled = flag Y clave presentes;
 * Welcome.vue monta ChatbotWidget solo con ese prop (guardado además por
 * scripts/check-movi-availability-status.mjs).
 */
class WelcomeMoviAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_movi_is_not_offered_when_disabled(): void
    {
        config(['chatbot.enabled' => false, 'chatbot.provider' => 'gemini', 'chatbot.gemini.api_key' => 'k']);

        $this->get(route('welcome'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('chatbotEnabled', false));
    }

    public function test_movi_is_not_offered_when_enabled_without_a_key(): void
    {
        config(['chatbot.enabled' => true, 'chatbot.provider' => 'gemini', 'chatbot.gemini.api_key' => null]);

        $this->get(route('welcome'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('chatbotEnabled', false));
    }

    public function test_movi_is_offered_only_when_it_can_answer(): void
    {
        config(['chatbot.enabled' => true, 'chatbot.provider' => 'gemini', 'chatbot.gemini.api_key' => 'k']);

        $this->get(route('welcome'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('chatbotEnabled', true));
    }

    public function test_local_movi_needs_no_key_and_is_offered_when_enabled(): void
    {
        config(['chatbot.enabled' => true, 'chatbot.provider' => 'local', 'chatbot.gemini.api_key' => null]);

        $this->get(route('welcome'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('chatbotEnabled', true));
    }
}
