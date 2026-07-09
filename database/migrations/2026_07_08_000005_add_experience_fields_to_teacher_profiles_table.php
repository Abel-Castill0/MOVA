<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table) {
            $table->unsignedInteger('completed_classes_count')->default(0)->after('credits_reserved');
            $table->boolean('is_experienced')->default(false)->after('completed_classes_count');
        });
    }

    public function down(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table) {
            $table->dropColumn(['completed_classes_count', 'is_experienced']);
        });
    }
};
