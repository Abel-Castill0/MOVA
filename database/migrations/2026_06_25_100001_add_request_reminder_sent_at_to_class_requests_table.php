<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_requests', function (Blueprint $table) {
            $table->timestamp('request_reminder_sent_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('class_requests', function (Blueprint $table) {
            $table->dropColumn('request_reminder_sent_at');
        });
    }
};
