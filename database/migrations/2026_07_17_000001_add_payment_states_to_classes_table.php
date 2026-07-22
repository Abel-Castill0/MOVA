<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE classes MODIFY status ENUM("
                ."'scheduled', 'in_progress', 'paid', 'pending_parent_confirmation', 'completed', 'cancelled'"
                .") NOT NULL DEFAULT 'scheduled'"
            );

            return;
        }

        // SQLite enforces the enum via a CHECK constraint; rebuild the column to widen it.
        Schema::table('classes', function (Blueprint $table) {
            $table->string('status')->default('scheduled')->change();
        });
    }

    public function down(): void
    {
        DB::table('classes')
            ->whereIn('status', ['paid', 'pending_parent_confirmation'])
            ->update(['status' => 'scheduled']);

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE classes MODIFY status ENUM("
                ."'scheduled', 'in_progress', 'completed', 'cancelled'"
                .") NOT NULL DEFAULT 'scheduled'"
            );

            return;
        }

        Schema::table('classes', function (Blueprint $table) {
            $table->enum('status', ['scheduled', 'in_progress', 'completed', 'cancelled'])
                ->default('scheduled')
                ->change();
        });
    }
};
