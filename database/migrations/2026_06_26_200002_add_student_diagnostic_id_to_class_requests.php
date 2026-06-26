<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_requests', function (Blueprint $table) {
            $table->foreignId('student_diagnostic_id')
                ->nullable()
                ->after('status')
                ->constrained('student_diagnostics')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('class_requests', function (Blueprint $table) {
            $table->dropForeign(['student_diagnostic_id']);
            $table->dropColumn('student_diagnostic_id');
        });
    }
};
