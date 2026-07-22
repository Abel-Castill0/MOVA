<?php

namespace Database\Seeders;

use App\Models\ClassRequest;
use App\Models\CreditTransaction;
use App\Models\Lesson;
use App\Models\LessonReport;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\TeacherReview;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class LocalTestDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([RoleSeeder::class, SubjectSeeder::class]);

        $subjects = Subject::all();
        abort_if($subjects->isEmpty(), 500, 'No hay materias sembradas.');

        $password = Hash::make('password123');
        $mathSubject = $subjects->firstWhere('name', 'Matemáticas') ?? $subjects->first();
        $engSubject  = $subjects->firstWhere('name', 'Inglés') ?? $subjects->skip(1)->first() ?? $mathSubject;

        // ── Profesor principal ───────────────────────────────────────────
        $teacherUser = User::updateOrCreate(
            ['email' => 'profesor@mova.test'],
            [
                'name' => 'Profesor de Prueba',
                'password' => $password,
                'phone' => '+51999111222',
                'email_verified_at' => now(),
                'phone_verified_at' => now(),
            ]
        );
        if (! $teacherUser->hasRole('teacher')) {
            $teacherUser->assignRole('teacher');
        }

        $teacherProfile = TeacherProfile::updateOrCreate(
            ['user_id' => $teacherUser->id],
            [
                'bio' => 'Profesor de prueba generado para pruebas E2E locales.',
                'hourly_rate' => 20,
                'yape_number' => '999111222',
                'plin_number' => '999111222',
                'is_verified' => true,
                'credits_available' => 5,
                'credits_reserved' => 4, // refleja las 2 clases "scheduled" ya sembradas (2 créditos c/u)
            ]
        );
        $teacherProfile->subjects()->syncWithoutDetaching(
            collect([$mathSubject, $engSubject])->filter()->pluck('id')
        );

        // ── Padre principal + 2 hijos ─────────────────────────────────────
        $parentUser = User::updateOrCreate(
            ['email' => 'padre@mova.test'],
            [
                'name' => 'Padre de Prueba',
                'password' => $password,
                'phone' => '+51999333444',
                'email_verified_at' => now(),
            ]
        );
        if (! $parentUser->hasRole('parent')) {
            $parentUser->assignRole('parent');
        }

        $student1 = Student::firstOrCreate(
            ['parent_user_id' => $parentUser->id, 'first_name' => 'Mateo', 'last_name' => 'Prueba'],
            ['grade_level' => 'secundaria']
        );
        $student2 = Student::firstOrCreate(
            ['parent_user_id' => $parentUser->id, 'first_name' => 'Valentina', 'last_name' => 'Prueba'],
            ['grade_level' => 'primaria']
        );

        // ── 3 solicitudes de clase pendientes (estado "open") ─────────────
        $pendingRequests = [
            [$student1, $mathSubject, 'Necesita reforzar ecuaciones de segundo grado.'],
            [$student2, $engSubject, 'Practicar vocabulario básico de inglés.'],
            [$student1, $engSubject, 'Preparación para examen de speaking.'],
        ];
        foreach ($pendingRequests as [$student, $subject, $help]) {
            if (! $subject) {
                continue;
            }
            ClassRequest::firstOrCreate(
                ['student_id' => $student->id, 'subject_id' => $subject->id, 'help_needed' => $help],
                ['status' => 'open']
            );
        }

        // ── 2 clases programadas/aceptadas ─────────────────────────────────
        for ($i = 1; $i <= 2; $i++) {
            $request = ClassRequest::create([
                'student_id' => $student1->id,
                'subject_id' => $mathSubject->id,
                'help_needed' => "Clase programada de prueba #{$i}",
                'status' => 'accepted',
            ]);

            $lesson = Lesson::create([
                'teacher_profile_id' => $teacherProfile->id,
                'student_id' => $student1->id,
                'class_request_id' => $request->id,
                'start_time' => now()->addDays($i),
                'duration_minutes' => 60,
                'jitsi_room' => 'mova-lesson-test-'.uniqid(),
                'status' => 'scheduled',
            ]);

            CreditTransaction::firstOrCreate(
                ['idempotency_key' => "lesson:{$lesson->id}:reservation"],
                [
                    'teacher_profile_id' => $teacherProfile->id,
                    'lesson_id' => $lesson->id,
                    'type' => 'reservation',
                    'amount' => Lesson::CLASS_CREDIT_COST,
                    'description' => 'Reserva por aceptación de clase (dato de prueba)',
                ]
            );
        }

        // ── 1 clase completada, con reporte y reseña ───────────────────────
        $pastRequest = ClassRequest::create([
            'student_id' => $student2->id,
            'subject_id' => $engSubject->id,
            'help_needed' => 'Clase histórica completada de prueba',
            'status' => 'accepted',
        ]);

        $pastLesson = Lesson::create([
            'teacher_profile_id' => $teacherProfile->id,
            'student_id' => $student2->id,
            'class_request_id' => $pastRequest->id,
            'start_time' => now()->subDays(3),
            'duration_minutes' => 60,
            'jitsi_room' => 'mova-lesson-test-'.uniqid(),
            'status' => 'completed',
        ]);

        CreditTransaction::firstOrCreate(
            ['idempotency_key' => "lesson:{$pastLesson->id}:consumption"],
            [
                'teacher_profile_id' => $teacherProfile->id,
                'lesson_id' => $pastLesson->id,
                'type' => 'consumption',
                'amount' => Lesson::CLASS_CREDIT_COST,
                'description' => 'Consumo por clase completada (dato de prueba)',
            ]
        );

        LessonReport::firstOrCreate(
            ['lesson_id' => $pastLesson->id],
            [
                'teacher_profile_id' => $teacherProfile->id,
                'student_id' => $student2->id,
                'topic_covered' => 'Vocabulario básico y presente simple.',
                'student_performance' => 'Buen progreso, participa activamente en clase.',
                'homework_assigned' => 'Completar ficha de ejercicios 3 y 4.',
                'next_step' => 'Reforzar pronunciación en la próxima clase.',
                'sent_to_parent_at' => now(),
            ]
        );

        TeacherReview::firstOrCreate(
            ['lesson_id' => $pastLesson->id],
            [
                'teacher_profile_id' => $teacherProfile->id,
                'parent_id' => $parentUser->id,
                'student_id' => $student2->id,
                'rating' => 5,
                'comment' => 'Excelente profesor, muy paciente con mi hija.',
                'is_visible' => true,
            ]
        );

        // ── Relleno: profesores y padres extra aleatorios ─────────────────
        for ($i = 0; $i < 2; $i++) {
            $extraTeacherUser = User::factory()->create();
            $extraTeacherUser->assignRole('teacher');

            $extraProfile = TeacherProfile::create([
                'user_id' => $extraTeacherUser->id,
                'bio' => 'Profesor generado aleatoriamente para pruebas.',
                'hourly_rate' => fake()->numberBetween(15, 20),
                'is_verified' => true,
                'credits_available' => 5,
            ]);
            $extraProfile->subjects()->attach(
                $subjects->random(min(2, $subjects->count()))->pluck('id')
            );
        }

        for ($i = 0; $i < 2; $i++) {
            $extraParentUser = User::factory()->create();
            $extraParentUser->assignRole('parent');

            Student::create([
                'parent_user_id' => $extraParentUser->id,
                'first_name' => fake()->firstName(),
                'last_name' => fake()->lastName(),
                'grade_level' => fake()->randomElement(['primaria', 'secundaria', 'universidad']),
            ]);
        }

        $this->command->info('LocalTestDataSeeder completado.');
        $this->command->table(
            ['Rol', 'Email', 'Password'],
            [
                ['Padre', 'padre@mova.test', 'password123'],
                ['Profesor', 'profesor@mova.test', 'password123'],
            ]
        );
        $this->command->line('Datos relacionales: 2 hijos, 3 solicitudes pendientes, 2 clases programadas, 1 clase completada con reporte + reseña, 2 profesores y 2 padres extra aleatorios.');
    }
}
