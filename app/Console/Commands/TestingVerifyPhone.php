<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * TESTING ONLY. Marks a user's phone as verified directly via DB, bypassing
 * the WhatsApp code flow entirely (Meta Cloud API — see
 * docs/whatsapp-architecture.md). Useful when no real WhatsApp credentials
 * are configured for a given test number. Same environment-guard pattern as
 * TestingBackdateLesson — CLI/DB access only, no HTTP bypass.
 */
class TestingVerifyPhone extends Command
{
    protected $signature = 'mova:testing-verify-phone {user_id}';

    protected $description = 'TESTING ONLY: mark a user\'s phone as verified without going through WhatsApp';

    public function handle(): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('This command only runs in local/testing environments.');

            return self::FAILURE;
        }

        $user = User::find($this->argument('user_id'));

        if (! $user) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        $user->update([
            'phone_verified_at'             => now(),
            'phone_verification_code_hash'  => null,
            'phone_verification_expires_at' => null,
            'phone_verification_attempts'   => 0,
        ]);

        $this->info("User #{$user->id} ({$user->email}) phone marked as verified.");

        return self::SUCCESS;
    }
}
