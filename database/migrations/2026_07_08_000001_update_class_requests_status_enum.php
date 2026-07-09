<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE class_requests MODIFY status ENUM('pending_parent_approval', 'open', 'accepted', 'rejected', 'teacher_rejected', 'completed') NOT NULL DEFAULT 'open'");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::table('class_requests')
                ->where('status', 'teacher_rejected')
                ->update(['status' => 'rejected']);

            DB::statement("ALTER TABLE class_requests MODIFY status ENUM('pending_parent_approval', 'open', 'accepted', 'rejected', 'completed') NOT NULL DEFAULT 'open'");
        }
    }
};
