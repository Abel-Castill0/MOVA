<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recharge_requests', function (Blueprint $table) {
            $table->string('package_code')->nullable()->after('teacher_profile_id');
            $table->string('payment_method')->nullable()->after('amount_pen');
            $table->string('operation_number_normalized')->nullable()->after('operation_number');
            $table->timestamp('reviewed_at')->nullable()->after('status');
            $table->foreignId('reviewed_by')->nullable()->after('reviewed_at');
            $table->timestamp('approved_at')->nullable()->after('reviewed_by');
            $table->timestamp('rejected_at')->nullable()->after('approved_at');
            $table->string('rejection_reason', 500)->nullable()->after('rejected_at');
        });

        $seen = [];
        DB::table('recharge_requests')->orderBy('id')->get()->each(function ($recharge) use (&$seen) {
            $base = mb_strtoupper(
                preg_replace('/[^\p{L}\p{N}]+/u', '', trim((string) $recharge->operation_number)) ?? '',
                'UTF-8'
            );
            $base = $base !== '' ? $base : 'LEGACY';
            $normalized = $base;

            if (isset($seen['legacy|' . $normalized])) {
                $normalized .= 'LEGACY' . $recharge->id;
            }

            $seen['legacy|' . $normalized] = true;

            DB::table('recharge_requests')->where('id', $recharge->id)->update([
                'package_code' => match (mb_strtolower((string) $recharge->package_name, 'UTF-8')) {
                    'inicio' => 'inicio',
                    'impulso' => 'impulso',
                    'pro' => 'pro',
                    default => 'legacy',
                },
                'payment_method' => 'legacy',
                'operation_number_normalized' => $normalized,
            ]);
        });

        Schema::table('recharge_requests', function (Blueprint $table) {
            $table->unique(
                ['payment_method', 'operation_number_normalized'],
                'recharge_payment_operation_unique'
            );
        });

        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->string('idempotency_key')->nullable()->after('teacher_profile_id');
            $table->foreignId('lesson_id')->nullable()->after('idempotency_key');
            $table->foreignId('recharge_request_id')->nullable()->after('lesson_id');
            $table->unique('idempotency_key');
            $table->index('lesson_id');
            $table->index('recharge_request_id');
        });

        DB::table('credit_transactions')
            ->where('type', 'deposit')
            ->where('description', 'Bono de bienvenida MOVA')
            ->orderBy('id')
            ->get()
            ->groupBy('teacher_profile_id')
            ->each(function ($transactions, $teacherProfileId) {
                DB::table('credit_transactions')
                    ->where('id', $transactions->first()->id)
                    ->update([
                        'idempotency_key' => "teacher:{$teacherProfileId}:welcome",
                    ]);
            });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('recharge_requests', function (Blueprint $table) {
                $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
            });

            Schema::table('credit_transactions', function (Blueprint $table) {
                $table->foreign('lesson_id')->references('id')->on('classes')->restrictOnDelete();
                $table->foreign('recharge_request_id')->references('id')->on('recharge_requests')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            Schema::table('credit_transactions', function (Blueprint $table) {
                $table->dropForeign(['lesson_id']);
                $table->dropForeign(['recharge_request_id']);
            });

            Schema::table('recharge_requests', function (Blueprint $table) {
                $table->dropForeign(['reviewed_by']);
            });
        }

        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropIndex(['lesson_id']);
            $table->dropIndex(['recharge_request_id']);
            $table->dropColumn(['idempotency_key', 'lesson_id', 'recharge_request_id']);
        });

        Schema::table('recharge_requests', function (Blueprint $table) {
            $table->dropUnique('recharge_payment_operation_unique');
            $table->dropColumn([
                'package_code',
                'payment_method',
                'operation_number_normalized',
                'reviewed_at',
                'reviewed_by',
                'approved_at',
                'rejected_at',
                'rejection_reason',
            ]);
        });
    }
};
