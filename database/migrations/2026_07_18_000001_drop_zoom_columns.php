<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->dropColumn(['zoom_link', 'zoom_password']);
        });

        Schema::table('teacher_profiles', function (Blueprint $table) {
            $table->dropColumn('zoom_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->string('zoom_link', 512)->nullable()->after('zoom_meeting_id');
            $table->string('zoom_password')->nullable()->after('zoom_link');
        });

        Schema::table('teacher_profiles', function (Blueprint $table) {
            $table->string('zoom_account_id')->nullable();
        });
    }
};
