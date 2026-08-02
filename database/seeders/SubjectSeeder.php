<?php

namespace Database\Seeders;

use App\Models\Subject;
use App\Services\SubjectNormalizer;
use Illuminate\Database\Seeder;

/**
 * Materias ya no son un catálogo fijo: los profesores crean las suyas al
 * registrarse (ver SubjectNormalizer). Este seeder solo deja unos ejemplos
 * iniciales para que el marketplace no arranque vacío en un entorno nuevo.
 */
class SubjectSeeder extends Seeder
{
    public function run(): void
    {
        $examples = [
            ['name' => 'Matemáticas', 'level' => 'todos'],
            ['name' => 'Lengua y Literatura', 'level' => 'todos'],
            ['name' => 'Inglés', 'level' => 'todos'],
            ['name' => 'Ciencias Naturales', 'level' => 'primaria'],
            ['name' => 'Física', 'level' => 'secundaria'],
            ['name' => 'Programación', 'level' => 'todos'],
        ];

        foreach ($examples as $subject) {
            Subject::firstOrCreate(
                ['normalized_name' => SubjectNormalizer::normalize($subject['name'])],
                $subject
            );
        }
    }
}
