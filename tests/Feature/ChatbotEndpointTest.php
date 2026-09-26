<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatbotEndpointTest extends TestCase
{
    public function test_message_is_required_for_chatbot_endpoint(): void
    {
        $response = $this->postJson(route('chatbot.message'), []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['message']);
    }

    public function test_chatbot_returns_setup_prompt_when_gemini_key_is_missing(): void
    {
        Config::set('chatbot.gemini.api_key', null);

        $response = $this->postJson(route('chatbot.message'), [
            'message' => '¿Cómo busco un profesor?',
        ]);

        $response->assertOk()
            ->assertJson([
                'success'    => true,
                'configured' => false,
            ]);

        $this->assertStringContainsString('GEMINI_API_KEY', $response->json('reply'));
    }

    public function test_chatbot_calls_gemini_api_when_key_is_configured(): void
    {
        Config::set('chatbot.gemini.api_key', 'test-fake-key-12345');
        Config::set('chatbot.gemini.model', 'gemini-1.5-flash');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => '¡Hola! Para encontrar a tu profesor ideal en MOVA, puedes filtrar por materia y nivel educativo.'],
                            ],
                            'role' => 'model',
                        ],
                        'finishReason' => 'STOP',
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson(route('chatbot.message'), [
            'message' => '¿Cómo encuentro profesor?',
            'history' => [
                ['sender' => 'user', 'text' => 'Hola'],
                ['sender' => 'bot', 'text' => '¡Hola! ¿En qué te ayudo?'],
            ],
        ]);

        $response->assertOk()
            ->assertJson([
                'success'    => true,
                'configured' => true,
                'reply'      => '¡Hola! Para encontrar a tu profesor ideal en MOVA, puedes filtrar por materia y nivel educativo.',
            ]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'generativelanguage.googleapis.com')
                && $request->hasHeader('x-goog-api-key', 'test-fake-key-12345')
                && count($request['contents']) > 0;
        });
    }

    public function test_chatbot_handles_gemini_api_error_gracefully(): void
    {
        Config::set('chatbot.gemini.api_key', 'test-fake-key-12345');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 500,
                    'message' => 'Internal server error from AI provider',
                ],
            ], 500),
        ]);

        $response = $this->postJson(route('chatbot.message'), [
            'message' => 'Hola Movi',
        ]);

        $response->assertOk()
            ->assertJson([
                'success'    => false,
                'configured' => true,
            ]);

        $this->assertStringContainsString('dificultad', $response->json('reply'));
    }
}
