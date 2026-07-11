<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const FOREIGN_KEYS = [
        ['credit_transactions', 'credit_transactions_teacher_profile_id_foreign', 'teacher_profile_id', 'teacher_profiles', 'CASCADE'],
        ['recharge_requests', 'recharge_requests_teacher_profile_id_foreign', 'teacher_profile_id', 'teacher_profiles', 'CASCADE'],
        ['classes', 'classes_teacher_profile_id_foreign', 'teacher_profile_id', 'teacher_profiles', 'CASCADE'],
        ['classes', 'classes_student_id_foreign', 'student_id', 'students', 'CASCADE'],
        ['classes', 'classes_class_request_id_foreign', 'class_request_id', 'class_requests', 'SET NULL'],
        ['class_requests', 'class_requests_student_id_foreign', 'student_id', 'students', 'CASCADE'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $this->assertForeignKeysMatchBaseline();

        foreach (self::FOREIGN_KEYS as [$table, $constraint, $column, $references]) {
            $this->replaceDeleteRule($table, $constraint, $column, $references, 'RESTRICT');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $this->assertRollbackDoesNotExposeHistory();
        $this->assertForeignKeysUseRestrict();

        foreach (self::FOREIGN_KEYS as [$table, $constraint, $column, $references, $originalRule]) {
            $this->replaceDeleteRule($table, $constraint, $column, $references, $originalRule);
        }
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
            "ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` FOREIGN KEY (`{$column}`) ".
            "REFERENCES `{$references}` (`id`) ON DELETE {$deleteRule}"
        );
    }

    private function assertForeignKeysMatchBaseline(): void
    {
        foreach (self::FOREIGN_KEYS as [$table, $constraint, $column, $references, $deleteRule]) {
            $this->assertForeignKey($table, $constraint, $column, $references, $deleteRule);
        }
    }

    private function assertForeignKeysUseRestrict(): void
    {
        foreach (self::FOREIGN_KEYS as [$table, $constraint, $column, $references]) {
            $this->assertForeignKey($table, $constraint, $column, $references, 'RESTRICT');
        }
    }

    private function assertRollbackDoesNotExposeHistory(): void
    {
        foreach (['credit_transactions', 'recharge_requests', 'classes', 'class_requests'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException(
                    'Monetization rollback aborted: restoring cascading deletes would expose audit history.'
                );
            }
        }
    }

    private function assertForeignKey(
        string $table,
        string $constraint,
        string $column,
        string $references,
        string $deleteRule
    ): void {
        $foreignKey = DB::table('information_schema.REFERENTIAL_CONSTRAINTS as constraints')
            ->join('information_schema.KEY_COLUMN_USAGE as columns', function ($join) {
                $join->on('constraints.CONSTRAINT_SCHEMA', '=', 'columns.CONSTRAINT_SCHEMA')
                    ->on('constraints.CONSTRAINT_NAME', '=', 'columns.CONSTRAINT_NAME')
                    ->on('constraints.TABLE_NAME', '=', 'columns.TABLE_NAME');
            })
            ->where('constraints.CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('constraints.TABLE_NAME', $table)
            ->where('constraints.CONSTRAINT_NAME', $constraint)
            ->select([
                'constraints.DELETE_RULE as delete_rule',
                'columns.COLUMN_NAME as column_name',
                'columns.REFERENCED_TABLE_NAME as referenced_table_name',
            ])
            ->first();

        if (
            ! $foreignKey
            || $foreignKey->column_name !== $column
            || $foreignKey->referenced_table_name !== $references
            || strtoupper($foreignKey->delete_rule) !== $deleteRule
        ) {
            throw new RuntimeException(
                "Monetization migration aborted: foreign key preflight failed for {$constraint}."
            );
        }
    }
};
