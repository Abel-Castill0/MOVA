<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table) {
            $table->unsignedInteger('mentorship_slots_total')->default(0)->after('is_experienced');
            $table->unsignedInteger('mentorship_slots_taken')->default(0)->after('mentorship_slots_total');
        });

        Schema::table('class_requests', function (Blueprint $table) {
            $table->boolean('is_mentorship')->default(false)->after('class_offer_id');
        });
    }

    public function down(): void
    {
        Schema::table('class_requests', function (Blueprint $table) {
            $table->dropColumn('is_mentorship');
        });

        Schema::table('teacher_profiles', function (Blueprint $table) {
            $table->dropColumn(['mentorship_slots_total', 'mentorship_slots_taken']);
        });
    }
};
