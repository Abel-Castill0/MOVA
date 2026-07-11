<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $this->replaceDeleteRule('credit_transactions', 'credit_transactions_teacher_profile_id_foreign', 'teacher_profile_id', 'teacher_profiles', 'RESTRICT');
        $this->replaceDeleteRule('recharge_requests', 'recharge_requests_teacher_profile_id_foreign', 'teacher_profile_id', 'teacher_profiles', 'RESTRICT');
        $this->replaceDeleteRule('classes', 'classes_teacher_profile_id_foreign', 'teacher_profile_id', 'teacher_profiles', 'RESTRICT');
        $this->replaceDeleteRule('classes', 'classes_student_id_foreign', 'student_id', 'students', 'RESTRICT');
        $this->replaceDeleteRule('class_requests', 'class_requests_student_id_foreign', 'student_id', 'students', 'RESTRICT');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $this->replaceDeleteRule('credit_transactions', 'credit_transactions_teacher_profile_id_foreign', 'teacher_profile_id', 'teacher_profiles', 'CASCADE');
        $this->replaceDeleteRule('recharge_requests', 'recharge_requests_teacher_profile_id_foreign', 'teacher_profile_id', 'teacher_profiles', 'CASCADE');
        $this->replaceDeleteRule('classes', 'classes_teacher_profile_id_foreign', 'teacher_profile_id', 'teacher_profiles', 'CASCADE');
        $this->replaceDeleteRule('classes', 'classes_student_id_foreign', 'student_id', 'students', 'CASCADE');
        $this->replaceDeleteRule('class_requests', 'class_requests_student_id_foreign', 'student_id', 'students', 'CASCADE');
    }

    private function replaceDeleteRule(
        string $table,
        string $constraint,
        string $column,
        string $references,
        string $deleteRule
    ): void {
        DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraint}`");
        DB::statement(
            "ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` FOREIGN KEY (`{$column}`) " .
            "REFERENCES `{$references}` (`id`) ON DELETE {$deleteRule}"
        );
    }
};
