<?php

namespace Tests;

use App\Services\AdminMfaService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * P0-C: los tests que no son de MFA actúan como un admin enrolado que ya
     * pasó el challenge en esta sesión. El camino real (sin atajo) lo cubre
     * AdminMfaTest, que pone $bypassAdminMfa = false.
     */
    protected bool $bypassAdminMfa = true;

    public function actingAs(Authenticatable $user, $guard = null)
    {
        parent::actingAs($user, $guard);

        if ($this->bypassAdminMfa && method_exists($user, 'hasRole') && $user->hasRole('admin')) {
            if ($user->two_factor_confirmed_at === null) {
                $user->forceFill([
                    'two_factor_secret'       => 'TESTSECRETTESTSECRETTESTSECRET12',
                    'two_factor_confirmed_at' => now(),
                ])->save();
            }
            $this->withSession([AdminMfaService::SESSION_KEY => ['user_id' => $user->id, 'at' => now()->getTimestamp()]]);
        }

        return $this;
    }
}
