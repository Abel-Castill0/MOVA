<?php

namespace Database\Seeders;

use App\Models\Subject;
use Illuminate\Database\Seeder;

class SubjectSeeder extends Seeder
{
    public function run(): void
    {
        $subjects = [
            ['name' => 'Matemáticas', 'level' => 'todos'],
            ['name' => 'Lengua y Literatura', 'level' => 'todos'],
            ['name' => 'Inglés', 'level' => 'todos'],
            ['name' => 'Francés', 'level' => 'todos'],
            ['name' => 'Ciencias Naturales', 'level' => 'primaria'],
            ['name' => 'Ciencias Sociales', 'level' => 'primaria'],
            ['name' => 'Física', 'level' => 'secundaria'],
            ['name' => 'Química', 'level' => 'secundaria'],
            ['name' => 'Biología', 'level' => 'secundaria'],
            ['name' => 'Historia', 'level' => 'secundaria'],
            ['name' => 'Geografía', 'level' => 'secundaria'],
            ['name' => 'Filosofía', 'level' => 'secundaria'],
            ['name' => 'Economía', 'level' => 'secundaria'],
            ['name' => 'Tecnología', 'level' => 'secundaria'],
            ['name' => 'Programación', 'level' => 'todos'],
            ['name' => 'Cálculo', 'level' => 'universidad'],
            ['name' => 'Álgebra Lineal', 'level' => 'universidad'],
            ['name' => 'Estadística', 'level' => 'universidad'],
            ['name' => 'Derecho', 'level' => 'universidad'],
            ['name' => 'Contabilidad', 'level' => 'universidad'],
            ['name' => 'Medicina', 'level' => 'universidad'],
            ['name' => 'Arquitectura', 'level' => 'universidad'],
            ['name' => 'Música', 'level' => 'todos'],
            ['name' => 'Arte y Dibujo', 'level' => 'todos'],
            ['name' => 'Educación Física', 'level' => 'primaria'],
        ];

        foreach ($subjects as $subject) {
            Subject::firstOrCreate(['name' => $subject['name']], $subject);
        }
    }
}
