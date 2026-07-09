<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_profile_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['deposit', 'reservation', 'consumption', 'refund']);
            $table->integer('amount');
            $table->string('description');
            $table->timestamps();

            $table->index(['teacher_profile_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
