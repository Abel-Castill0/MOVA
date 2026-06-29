<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('student_diagnostic_id')->nullable()->index();
            $table->string('provider', 30);
            $table->string('model', 80);
            $table->string('status', 20)->index(); // success|fallback|error|skipped
            $table->unsignedSmallInteger('prompt_tokens')->nullable();
            $table->unsignedSmallInteger('completion_tokens')->nullable();
            $table->unsignedSmallInteger('total_tokens')->nullable();
            $table->string('error_type', 50)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};
