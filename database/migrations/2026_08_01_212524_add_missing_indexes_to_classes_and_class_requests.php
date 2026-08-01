<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->index('status');
            $table->index('start_time');
        });

        Schema::table('class_requests', function (Blueprint $table) {
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['start_time']);
        });

        Schema::table('class_requests', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });
    }
};
