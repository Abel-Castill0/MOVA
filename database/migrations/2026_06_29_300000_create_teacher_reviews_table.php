<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lesson_id')->unique()->index();
            $table->unsignedBigInteger('teacher_profile_id')->index();
            $table->unsignedBigInteger('parent_id')->index();
            $table->unsignedBigInteger('student_id')->nullable()->index();
            $table->tinyInteger('rating')->unsigned(); // 1-5, validated in app
            $table->text('comment')->nullable();
            $table->boolean('is_visible')->default(true)->index();
            $table->timestamp('moderated_at')->nullable();
            $table->unsignedBigInteger('moderated_by')->nullable();
            $table->string('moderation_reason', 500)->nullable();
            $table->timestamps();

            $table->foreign('lesson_id')->references('id')->on('classes')->onDelete('restrict');
            $table->foreign('teacher_profile_id')->references('id')->on('teacher_profiles')->onDelete('restrict');
            $table->foreign('parent_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('student_id')->references('id')->on('students')->onDelete('set null');
            $table->foreign('moderated_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_reviews');
    }
};
