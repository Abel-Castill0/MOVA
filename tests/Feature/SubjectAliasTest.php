<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Services\SubjectNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SubjectAliasTest extends TestCase
{
    use RefreshDatabase;

    public function test_singular_and_plural_math_names_reuse_the_same_subject(): void
    {
        $subject = Subject::firstOrCreateByName('Matemática');
        $alias = Subject::firstOrCreateByName('Matemáticas');

        $this->assertSame($subject->id, $alias->id);
        $this->assertSame('Matemática', $subject->name);
        $this->assertSame('matematica', $subject->normalized_name);
        $this->assertSame(1, Subject::count());
    }

    public function test_existing_legacy_plural_is_reused_without_creating_a_second_record(): void
    {
        // Simula datos existentes creados ANTES del alias: nunca se borra ni
        // reescribe una referencia histórica solo para normalizar un texto.
        $id = DB::table('subjects')->insertGetId([
            'name' => 'Matemáticas',
            'normalized_name' => 'matematicas',
            'level' => 'todos',
        ]);

        $subject = Subject::firstOrCreateByName('Matemática');
        $this->assertSame($id, $subject->id);
        $this->assertSame(1, Subject::count());
    }

    public function test_new_algebra_subject_is_created_with_editorial_spelling(): void
    {
        $subject = Subject::firstOrCreateByName('algebra');

        $this->assertSame('Álgebra', $subject->name);
        $this->assertSame('algebra', $subject->normalized_name);
        $this->assertSame(['matematica', 'matematicas'], SubjectNormalizer::storedVariants('Matemáticas'));
    }
}
