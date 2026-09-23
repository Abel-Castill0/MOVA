<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** GET /api/user devuelve el User serializado: nada de estado MFA interno. */
class ApiUserSerializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_user_never_serializes_internal_mfa_fields(): void
    {
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret'             => 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP',
            'two_factor_recovery_codes'     => ['hash-a', 'hash-b'],
            'two_factor_confirmed_at'       => now(),
            'two_factor_last_used_timestep' => 59_000_000,
        ])->save();

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/user')->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('email', $user->email)
            ->assertJsonPath('name', $user->name);

        foreach (['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_last_used_timestep', 'password', 'remember_token'] as $field) {
            $response->assertJsonMissingPath($field);
        }
        $this->assertStringNotContainsString('59000000', $response->getContent());
    }
}
