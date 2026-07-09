<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recharge_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_profile_id')->constrained()->cascadeOnDelete();
            $table->string('package_name');
            $table->integer('credits');
            $table->decimal('amount_pen', 8, 2);
            $table->string('operation_number');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->timestamps();

            $table->index(['teacher_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recharge_requests');
    }
};
