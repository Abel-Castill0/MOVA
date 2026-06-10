<?php

namespace Database\Seeders;

use App\Models\ClassOffer;
use App\Models\ClassRequest;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            SubjectSeeder::class,
        ]);

        $subjects = Subject::all();

        // ── Teachers ──────────────────────────────────────────────
        $teacherData = [
            ['name' => 'Carlos Fernández',  'email' => 'carlos@mova.test',  'bio' => 'Matemáticas e ingeniería con 8 años de experiencia.',  'rate' => 25, 'subjs' => ['Matemáticas', 'Física']],
            ['name' => 'Laura García',      'email' => 'laura@mova.test',   'bio' => 'Profesora nativa de inglés. CAE certificada.',          'rate' => 30, 'subjs' => ['Inglés']],
            ['name' => 'Miguel Torres',     'email' => 'miguel@mova.test',  'bio' => 'Biología y química para selectividad.',                 'rate' => 22, 'subjs' => ['Biología', 'Química']],
            ['name' => 'Sofía Romero',      'email' => 'sofia@mova.test',   'bio' => 'Filología hispánica. Literatura y redacción.',         'rate' => 20, 'subjs' => ['Lengua y Literatura', 'Historia']],
            ['name' => 'Alejandro Vega',    'email' => 'alejandro@mova.test','bio' => 'Informática y programación. Full-stack developer.',    'rate' => 35, 'subjs' => ['Informática', 'Matemáticas']],
            ['name' => 'Marta Blanco',      'email' => 'marta@mova.test',   'bio' => 'Francés nativo. DELF B2.',                             'rate' => 28, 'subjs' => ['Francés']],
        ];

        $teachers = [];
        foreach ($teacherData as $td) {
            $user = User::firstOrCreate(['email' => $td['email']], [
                'name'     => $td['name'],
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]);
            $user->assignRole('teacher');

            $profile = TeacherProfile::firstOrCreate(['user_id' => $user->id], [
                'bio'         => $td['bio'],
                'hourly_rate' => $td['rate'],
                'is_verified' => true,
            ]);

            $subjectIds = $subjects->whereIn('name', $td['subjs'])->pluck('id');
            $profile->subjects()->syncWithoutDetaching($subjectIds);

            $teachers[] = ['user' => $user, 'profile' => $profile, 'subjs' => $subjectIds];
        }

        // ── Parents & Students ────────────────────────────────────
        $parentData = [
            ['name' => 'Ana López',      'email' => 'ana@mova.test'],
            ['name' => 'Roberto Díaz',   'email' => 'roberto@mova.test'],
            ['name' => 'Elena Morales',  'email' => 'elena@mova.test'],
            ['name' => 'David Herrera',  'email' => 'david@mova.test'],
            ['name' => 'Patricia Ruiz',  'email' => 'patricia@mova.test'],
        ];

        $studentNames = [
            ['Lucia', 'López', '3º ESO'],
            ['Mario', 'Díaz', '2º Bachillerato'],
            ['Clara', 'Morales', '1º ESO'],
            ['Álvaro', 'Herrera', '4º ESO'],
            ['Noa', 'Ruiz', '5º Primaria'],
            ['Pablo', 'López', '1º Bachillerato'],
            ['Sara', 'Díaz', '4º Primaria'],
            ['Diego', 'Morales', '3º Bachillerato'],
            ['Valeria', 'Herrera', '2º ESO'],
            ['Hugo', 'Ruiz', '6º Primaria'],
        ];

        $parents = [];
        foreach ($parentData as $i => $pd) {
            $parent = User::firstOrCreate(['email' => $pd['email']], [
                'name'     => $pd['name'],
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]);
            $parent->assignRole('parent');

            $sn = $studentNames[$i * 2];
            $s1 = Student::firstOrCreate(
                ['first_name' => $sn[0], 'last_name' => $sn[1], 'parent_user_id' => $parent->id],
                ['grade_level' => 'secundaria']
            );

            $sn2 = $studentNames[$i * 2 + 1];
            $s2 = Student::firstOrCreate(
                ['first_name' => $sn2[0], 'last_name' => $sn2[1], 'parent_user_id' => $parent->id],
                ['grade_level' => 'secundaria']
            );

            $parents[] = ['user' => $parent, 'students' => [$s1, $s2]];
        }

        // ── Class Offers ──────────────────────────────────────────
        foreach (array_slice($teachers, 0, 3) as $t) {
            $subjectId = $t['subjs']->first();
            if ($subjectId) {
                ClassOffer::firstOrCreate([
                    'teacher_profile_id' => $t['profile']->id,
                    'subject_id'         => $subjectId,
                ], [
                    'title'       => 'Clases particulares de ' . $subjects->find($subjectId)?->name,
                    'description' => $t['profile']->bio,
                    'is_active'   => true,
                ]);
            }
        }

        // ── Class Requests ────────────────────────────────────────
        if ($parents && $subjects->count()) {
            $studentA = $parents[0]['students'][0];
            $studentB = $parents[1]['students'][0];
            $mathSubject = $subjects->firstWhere('name', 'Matemáticas');
            $engSubject  = $subjects->firstWhere('name', 'Inglés');

            if ($mathSubject) {
                ClassRequest::firstOrCreate(['student_id' => $studentA->id, 'subject_id' => $mathSubject->id], [
                    'help_needed' => 'Necesito repasar álgebra para el examen de selectividad.',
                    'status'      => 'open',
                ]);
            }

            if ($engSubject) {
                ClassRequest::firstOrCreate(['student_id' => $studentB->id, 'subject_id' => $engSubject->id], [
                    'help_needed' => 'Quiero preparar el speaking para el examen de Cambridge.',
                    'status'      => 'open',
                ]);
            }
        }
    }
}
