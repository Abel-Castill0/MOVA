<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertLegacyRechargeOperationsAreSafe();

        Schema::table('recharge_requests', function (Blueprint $table) {
            $table->string('package_code', 64)->nullable()->after('teacher_profile_id');
            $table->string('payment_method', 32)->nullable()->after('amount_pen');
            $table->string('operation_number_normalized', 191)->nullable()->after('operation_number');
            $table->timestamp('reviewed_at')->nullable()->after('status');
            $table->foreignId('reviewed_by')->nullable()->after('reviewed_at');
            $table->timestamp('approved_at')->nullable()->after('reviewed_by');
            $table->timestamp('rejected_at')->nullable()->after('approved_at');
            $table->string('rejection_reason', 500)->nullable()->after('rejected_at');
        });

        DB::table('recharge_requests')->orderBy('id')->get()->each(function ($recharge) {
            DB::table('recharge_requests')->where('id', $recharge->id)->update([
                'package_code' => match (mb_strtolower((string) $recharge->package_name, 'UTF-8')) {
                    'inicio' => 'inicio',
                    'impulso' => 'impulso',
                    'pro' => 'pro',
                    default => 'legacy',
                },
                'payment_method' => 'legacy',
                'operation_number_normalized' => $this->normalizeOperation($recharge->operation_number),
            ]);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE `recharge_requests` '
                .'MODIFY `package_code` VARCHAR(64) NOT NULL, '
                .'MODIFY `payment_method` VARCHAR(32) NOT NULL, '
                .'MODIFY `operation_number_normalized` VARCHAR(191) NOT NULL'
            );
        }

        Schema::table('recharge_requests', function (Blueprint $table) {
            $table->unique(
                ['payment_method', 'operation_number_normalized'],
                'recharge_payment_operation_unique'
            );
        });

        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->string('idempotency_key', 128)->nullable()->after('teacher_profile_id');
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
        $this->assertRollbackDoesNotDiscardFinancialHistory();

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

    private function assertLegacyRechargeOperationsAreSafe(): void
    {
        $seen = [];
        $emptyOperations = 0;
        $duplicateOperations = 0;

        DB::table('recharge_requests')
            ->select(['id', 'operation_number'])
            ->orderBy('id')
            ->cursor()
            ->each(function ($recharge) use (&$seen, &$emptyOperations, &$duplicateOperations) {
                $normalized = $this->normalizeOperation($recharge->operation_number);

                if ($normalized === '') {
                    $emptyOperations++;

                    return;
                }

                if (isset($seen[$normalized])) {
                    $duplicateOperations++;

                    return;
                }

                $seen[$normalized] = true;
            });

        if ($emptyOperations > 0 || $duplicateOperations > 0) {
            throw new RuntimeException(
                'Monetization migration aborted: legacy recharge operations require approved remediation. '
                ."Empty operations: {$emptyOperations}; normalized duplicates: {$duplicateOperations}."
            );
        }
    }

    private function assertRollbackDoesNotDiscardFinancialHistory(): void
    {
        $hasRechargeHistory = DB::table('recharge_requests')->exists();
        $hasContextualLedger = DB::table('credit_transactions')
            ->whereNotNull('idempotency_key')
            ->orWhereNotNull('lesson_id')
            ->orWhereNotNull('recharge_request_id')
            ->exists();

        if ($hasRechargeHistory || $hasContextualLedger) {
            throw new RuntimeException(
                'Monetization rollback aborted: removing these columns would discard financial audit history.'
            );
        }
    }

    private function normalizeOperation(mixed $value): string
    {
        $normalized = trim((string) $value);
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', '', $normalized) ?? '';

        return mb_strtoupper($normalized, 'UTF-8');
    }
};
