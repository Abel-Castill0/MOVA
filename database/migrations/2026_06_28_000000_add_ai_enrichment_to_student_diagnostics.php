<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_diagnostics', function (Blueprint $table) {
            $table->json('ai_keywords')->nullable()->after('status');
            $table->string('ai_detected_level', 20)->nullable()->after('ai_keywords');
            $table->text('ai_summary')->nullable()->after('ai_detected_level');
            $table->string('ai_suggested_goal', 50)->nullable()->after('ai_summary');
            $table->json('ai_risk_flags')->nullable()->after('ai_suggested_goal');
            $table->unsignedTinyInteger('ai_confidence')->nullable()->after('ai_risk_flags');
            $table->boolean('ai_used_fallback')->default(false)->after('ai_confidence');
            $table->timestamp('ai_enriched_at')->nullable()->after('ai_used_fallback');

            $table->index('ai_used_fallback');
        });
    }

    public function down(): void
    {
        Schema::table('student_diagnostics', function (Blueprint $table) {
            $table->dropIndex(['ai_used_fallback']);
            $table->dropColumn([
                'ai_keywords', 'ai_detected_level', 'ai_summary',
                'ai_suggested_goal', 'ai_risk_flags', 'ai_confidence',
                'ai_used_fallback', 'ai_enriched_at',
            ]);
        });
    }
};
