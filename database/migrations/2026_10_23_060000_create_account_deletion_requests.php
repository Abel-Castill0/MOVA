<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('account_closed_at')->nullable()->index();
        });
        Schema::create('account_deletion_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('status', 32)->default('pending_review');
            $table->string('identity_method', 24);
            $table->json('snapshot');
            $table->json('events');
            $table->text('response')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Application rollback keeps the request/audit records. Destructive
        // schema rollback is deliberately unsupported for privacy requests.
    }
};
