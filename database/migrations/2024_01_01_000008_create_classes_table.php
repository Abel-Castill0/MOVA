<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('class_offer_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('start_time');
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->string('zoom_meeting_id')->nullable();
            $table->string('zoom_link', 512)->nullable();
            $table->string('zoom_password')->nullable();
            $table->enum('status', ['scheduled', 'in_progress', 'completed', 'cancelled'])->default('scheduled');
            $table->boolean('reminder_sent')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classes');
    }
};
