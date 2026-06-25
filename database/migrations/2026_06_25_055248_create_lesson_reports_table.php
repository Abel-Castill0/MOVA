<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('teacher_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->text('topic_covered');
            $table->text('student_performance');
            $table->text('difficulties_detected')->nullable();
            $table->text('homework_assigned')->nullable();
            $table->text('teacher_recommendation')->nullable();
            $table->text('next_step')->nullable();
            $table->timestamp('sent_to_parent_at')->nullable();
            $table->timestamps();

            $table->unique('lesson_id'); // max 1 report per class
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_reports');
    }
};
