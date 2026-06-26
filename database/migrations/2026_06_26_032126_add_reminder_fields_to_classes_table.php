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
            $table->timestamp('reminder_24h_sent_at')->nullable()->after('reminder_sent');
            $table->timestamp('reminder_2h_sent_at')->nullable()->after('reminder_24h_sent_at');
            $table->timestamp('report_reminder_sent_at')->nullable()->after('reminder_2h_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->dropColumn(['reminder_24h_sent_at', 'reminder_2h_sent_at', 'report_reminder_sent_at']);
        });
    }
};
