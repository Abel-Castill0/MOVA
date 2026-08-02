<?php

use App\Models\Subject;
use App\Services\SubjectNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->string('normalized_name')->nullable()->after('name');
        });

        Subject::all()->each(function (Subject $subject) {
            $subject->update(['normalized_name' => SubjectNormalizer::normalize($subject->name)]);
        });

        Schema::table('subjects', function (Blueprint $table) {
            $table->string('normalized_name')->nullable(false)->change();
            $table->unique('normalized_name');
        });
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropUnique(['subjects_normalized_name_unique']);
            $table->dropColumn('normalized_name');
        });
    }
};
