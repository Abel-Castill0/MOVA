<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SensitiveInputFlashingTest extends TestCase
{
    public function test_validation_redirect_never_flashes_mfa_or_payment_tokens(): void
    {
        Route::middleware('web')->post('/__qa-sensitive-input-flash', function (Request $request) {
            $request->validate(['mandatory_field' => ['required']]);
        });

        $this->from('/__qa-flash-origin')
            ->post('/__qa-sensitive-input-flash', [
                'safe_name' => 'Visible',
                'code' => '123456',
                'otp' => '654321',
                'verification_code' => 'verification-test-only',
                'recovery_code' => 'recovery-test-only',
                'two_factor_code' => 'twofactor-test-only',
                'two_factor_recovery_code' => 'twofactor-recovery-test-only',
                'two_factor_secret' => 'twofactor-secret-test-only',
                'token' => 'token-test-only',
                'card_token' => 'card-test-only',
                'payment_token' => 'payment-test-only',
                'parameters3DS' => ['eci' => '05', 'xid' => '3ds-test-only', 'cavv' => 'cryptogram-test-only'],
                'device_fingerprint_id' => 'device-test-only',
                'access_token' => 'access-test-only',
                'refresh_token' => 'refresh-test-only',
                'client_secret' => 'client-secret-test-only',
                'api_key' => 'api-test-only',
                'cvv' => '123',
                'cvc' => '123',
                'current_password' => 'Current-pass-test-only',
                'password' => 'Pass-test-only',
                'password_confirmation' => 'Pass-test-only',
            ])
            ->assertRedirect('/__qa-flash-origin')
            ->assertSessionHasErrors('mandatory_field')
            ->assertSessionHas('_old_input.safe_name', 'Visible');

        foreach ([
            'code', 'otp', 'verification_code', 'recovery_code', 'two_factor_code',
            'two_factor_recovery_code', 'two_factor_secret', 'token', 'card_token',
            'payment_token', 'parameters3DS', 'device_fingerprint_id', 'access_token',
            'refresh_token', 'client_secret',
            'api_key', 'cvv', 'cvc', 'current_password', 'password',
            'password_confirmation',
        ] as $sensitiveKey) {
            $this->assertArrayNotHasKey($sensitiveKey, session('_old_input', []));
        }
    }
}
