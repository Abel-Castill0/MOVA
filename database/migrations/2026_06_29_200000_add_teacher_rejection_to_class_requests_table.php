<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_requests', function (Blueprint $table) {
            $table->timestamp('teacher_rejected_at')->nullable()->after('status');
            $table->string('teacher_rejection_reason', 500)->nullable()->after('teacher_rejected_at');
        });
    }

    public function down(): void
    {
        Schema::table('class_requests', function (Blueprint $table) {
            $table->dropColumn(['teacher_rejected_at', 'teacher_rejection_reason']);
        });
    }
};
