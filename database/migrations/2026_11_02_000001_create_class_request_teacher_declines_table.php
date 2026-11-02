<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_request_teacher_declines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_profile_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 500);
            $table->timestamp('declined_at');
            $table->unique(['class_request_id', 'teacher_profile_id'], 'class_request_teacher_declines_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_request_teacher_declines');
    }
};
