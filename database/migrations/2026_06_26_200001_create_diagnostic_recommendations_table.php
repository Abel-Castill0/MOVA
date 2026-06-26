<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnostic_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_diagnostic_id')->constrained('student_diagnostics')->cascadeOnDelete();
            $table->foreignId('class_offer_id')->constrained('class_offers')->cascadeOnDelete();
            $table->foreignId('teacher_profile_id')->constrained('teacher_profiles')->cascadeOnDelete();
            $table->unsignedTinyInteger('rank');
            $table->unsignedSmallInteger('score')->default(0);
            $table->json('reasons');
            $table->timestamps();

            $table->unique(['student_diagnostic_id', 'class_offer_id']);
            $table->index(['student_diagnostic_id', 'rank']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnostic_recommendations');
    }
};
