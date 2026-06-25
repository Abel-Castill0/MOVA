<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// One-time backfill: all users registered before email verification was enforced
// are considered verified since they successfully used the platform.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        // Intentionally not reversible — we can't know which users were originally unverified.
    }
};
