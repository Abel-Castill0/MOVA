<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            // Cancellation
            $table->timestamp('cancelled_at')->nullable()->after('status');
            $table->unsignedBigInteger('cancelled_by')->nullable()->after('cancelled_at');
            $table->string('cancel_reason', 500)->nullable()->after('cancelled_by');

            // Rescheduling
            $table->datetime('original_start_time')->nullable()->after('cancel_reason');
            $table->timestamp('rescheduled_at')->nullable()->after('original_start_time');
            $table->unsignedBigInteger('rescheduled_by')->nullable()->after('rescheduled_at');
            $table->string('reschedule_reason', 500)->nullable()->after('rescheduled_by');
        });
    }

    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->dropColumn([
                'cancelled_at', 'cancelled_by', 'cancel_reason',
                'original_start_time', 'rescheduled_at', 'rescheduled_by', 'reschedule_reason',
            ]);
        });
    }
};
