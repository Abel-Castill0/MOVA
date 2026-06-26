<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_diagnostics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained('subjects')->nullOnDelete();
            $table->string('level')->nullable();
            $table->text('difficulty_text');
            $table->text('school_feedback')->nullable();
            $table->enum('goal', [
                'reinforce_topic',
                'prepare_exam',
                'recover_grades',
                'solve_homework',
                'continuous_support',
            ]);
            $table->enum('urgency', [
                'today_or_tomorrow',
                'this_week',
                'flexible',
            ]);
            $table->enum('status', ['draft', 'completed', 'converted'])->default('draft');
            $table->timestamps();

            $table->index(['parent_user_id', 'status']);
            $table->index('student_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_diagnostics');
    }
};
