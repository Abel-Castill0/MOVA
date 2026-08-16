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
                'credits_reserved' => 2, // refleja las 2 clases "scheduled" ya sembradas (1 crédito c/u)
            ]
        );
        $teacherProfile->subjects()->syncWithoutDetaching(
            collect([$mathSubject, $engSubject])->filter()->pluck('id')
        );

        // Depósito que respalda el saldo fijado arriba. Sin este asiento,
        // credits_available=5 no sería explicable desde el ledger y la
        // reconciliación contable de una BD sembrada quedaría incompleta.
        // Aritmética: 9 depositados − 4 reservados (2 clases programadas aún
        // abiertas + 2 clases pasadas ya consumidas) = 5 disponibles.
        CreditTransaction::firstOrCreate(
            ['idempotency_key' => "teacher:{$teacherProfile->id}:seed-deposit"],
            [
                'teacher_profile_id' => $teacherProfile->id,
                'type' => 'deposit',
                'amount' => 9,
                'description' => 'Recarga inicial (dato de prueba)',
            ]
        );

        // Sin esto el marketplace queda vacío: MarketplaceController lista
        // class_offers, no basta con ser profesor verificado con materias.
        \App\Models\ClassOffer::updateOrCreate(
            ['teacher_profile_id' => $teacherProfile->id, 'subject_id' => $mathSubject->id],
            ['title' => 'Clases de Matemáticas', 'description' => 'Refuerzo escolar personalizado, todos los niveles.', 'is_active' => true]
        );
        if ($engSubject && $engSubject->id !== $mathSubject->id) {
            \App\Models\ClassOffer::updateOrCreate(
                ['teacher_profile_id' => $teacherProfile->id, 'subject_id' => $engSubject->id],
                ['title' => 'Clases de Inglés', 'description' => 'Conversación y gramática para todos los niveles.', 'is_active' => true]
            );
        }

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
            $startTime = now()->addDays($i)->startOfMinute();

            $lesson = Lesson::firstOrCreate(
                [
                    'teacher_profile_id' => $teacherProfile->id,
                    'student_id' => $student1->id,
                    'start_time' => $startTime,
                ],
                [
                    'duration_minutes' => 60,
                    'jitsi_room' => 'mova-lesson-test-'.uniqid(),
                    'status' => 'scheduled',
                ]
            );

            if (! $lesson->wasRecentlyCreated) {
                continue;
            }

            $request = ClassRequest::create([
                'student_id' => $student1->id,
                'subject_id' => $mathSubject->id,
                'help_needed' => "Clase programada de prueba #{$i}",
                'status' => 'accepted',
            ]);
            $lesson->update(['class_request_id' => $request->id]);

            CreditTransaction::firstOrCreate(
                ['idempotency_key' => "lesson:{$lesson->id}:reservation"],
                [
                    'teacher_profile_id' => $teacherProfile->id,
                    'lesson_id' => $lesson->id,
                    'type' => 'reservation',
                    'amount' => $lesson->credit_cost,
                    'description' => 'Reserva por aceptación de clase (dato de prueba)',
                ]
            );
        }

        // ── Clases completadas, con reporte y reseña ────────────────────────
        // firstOrCreate (no create) para que reejecutar el seeder no duplique
        // clases ni asientos contables — mismo patrón que las clases
        // programadas de arriba.
        $pastRequest = ClassRequest::firstOrCreate(
            [
                'student_id' => $student2->id,
                'subject_id' => $engSubject->id,
                'help_needed' => 'Clase histórica completada de prueba',
            ],
            ['status' => 'accepted']
        );

        $pastLesson = Lesson::firstOrCreate(
            [
                'teacher_profile_id' => $teacherProfile->id,
                'student_id' => $student2->id,
                'class_request_id' => $pastRequest->id,
            ],
            [
                'start_time' => now()->subDays(3),
                'duration_minutes' => 60,
                'jitsi_room' => 'mova-lesson-test-'.uniqid(),
                'status' => 'completed',
            ]
        );

        // Una clase consumida SIEMPRE tuvo antes una reserva: el flujo real es
        // LessonController::store() (reservation) → TeacherReviewController::store()
        // (consumption). Sembrar solo el consumo dejaba un asiento huérfano que
        // rompe la invariante contable (reserva sin cierre ↔ credits_reserved) y
        // haría fallar los tests de invariantes contra cualquier BD sembrada.
        CreditTransaction::firstOrCreate(
            ['idempotency_key' => "lesson:{$pastLesson->id}:reservation"],
            [
                'teacher_profile_id' => $teacherProfile->id,
                'lesson_id' => $pastLesson->id,
                'type' => 'reservation',
                'amount' => $pastLesson->credit_cost,
                'description' => 'Reserva por aceptación de clase (dato de prueba)',
            ]
        );

        CreditTransaction::firstOrCreate(
            ['idempotency_key' => "lesson:{$pastLesson->id}:consumption"],
            [
                'teacher_profile_id' => $teacherProfile->id,
                'lesson_id' => $pastLesson->id,
                'type' => 'consumption',
                'amount' => $pastLesson->credit_cost,
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

        // ── 2da clase completada con reseña — la landing (Welcome.vue) muestra
        // testimonios reales desde teacher_reviews; con solo 1 reseña limpia el
        // grid de 3 columnas se ve incompleto en local.
        $pastRequest2 = ClassRequest::firstOrCreate(
            [
                'student_id' => $student1->id,
                'subject_id' => $mathSubject->id,
                'help_needed' => 'Clase histórica completada de prueba #2',
            ],
            ['status' => 'accepted']
        );

        $pastLesson2 = Lesson::firstOrCreate(
            [
                'teacher_profile_id' => $teacherProfile->id,
                'student_id' => $student1->id,
                'class_request_id' => $pastRequest2->id,
            ],
            [
                'start_time' => now()->subDays(5),
                'duration_minutes' => 60,
                'jitsi_room' => 'mova-lesson-test-'.uniqid(),
                'status' => 'completed',
            ]
        );

        // Ver comentario en pastLesson: reserva antes del consumo, siempre.
        CreditTransaction::firstOrCreate(
            ['idempotency_key' => "lesson:{$pastLesson2->id}:reservation"],
            [
                'teacher_profile_id' => $teacherProfile->id,
                'lesson_id' => $pastLesson2->id,
                'type' => 'reservation',
                'amount' => $pastLesson2->credit_cost,
                'description' => 'Reserva por aceptación de clase (dato de prueba)',
            ]
        );

        CreditTransaction::firstOrCreate(
            ['idempotency_key' => "lesson:{$pastLesson2->id}:consumption"],
            [
                'teacher_profile_id' => $teacherProfile->id,
                'lesson_id' => $pastLesson2->id,
                'type' => 'consumption',
                'amount' => $pastLesson2->credit_cost,
                'description' => 'Consumo por clase completada (dato de prueba)',
            ]
        );

        LessonReport::firstOrCreate(
            ['lesson_id' => $pastLesson2->id],
            [
                'teacher_profile_id' => $teacherProfile->id,
                'student_id' => $student1->id,
                'topic_covered' => 'Ecuaciones cuadráticas y factorización.',
                'student_performance' => 'Resolvió los ejercicios con poca ayuda.',
                'homework_assigned' => 'Practicar 10 ejercicios adicionales de la guía.',
                'next_step' => 'Repasar la fórmula general en la próxima clase.',
                'sent_to_parent_at' => now(),
            ]
        );

        TeacherReview::firstOrCreate(
            ['lesson_id' => $pastLesson2->id],
            [
                'teacher_profile_id' => $teacherProfile->id,
                'parent_id' => $parentUser->id,
                'student_id' => $student1->id,
                'rating' => 5,
                'comment' => 'Mateo por fin entendió ecuaciones cuadráticas, el profesor explica con mucha paciencia.',
                'is_visible' => true,
            ]
        );

        // ── Relleno: profesores y padres extra aleatorios ─────────────────
        for ($i = 1; $i <= 2; $i++) {
            $extraTeacherUser = User::firstOrCreate(
                ['email' => "profesor-relleno-{$i}@mova.test"],
                [
                    'name' => fake()->name(),
                    'password' => $password,
                    'email_verified_at' => now(),
                ]
            );
            if (! $extraTeacherUser->hasRole('teacher')) {
                $extraTeacherUser->assignRole('teacher');
            }

            $extraProfile = TeacherProfile::firstOrCreate(
                ['user_id' => $extraTeacherUser->id],
                [
                    'bio' => 'Profesor generado aleatoriamente para pruebas.',
                    'hourly_rate' => fake()->numberBetween(15, 20),
                    'is_verified' => true,
                    'credits_available' => 5,
                ]
            );
            $extraProfile->subjects()->syncWithoutDetaching(
                $subjects->random(min(2, $subjects->count()))->pluck('id')
            );

            // Igual que el profesor principal: el saldo debe tener un asiento
            // que lo respalde. Estos profesores no tienen clases, así que el
            // depósito coincide exactamente con credits_available.
            CreditTransaction::firstOrCreate(
                ['idempotency_key' => "teacher:{$extraProfile->id}:seed-deposit"],
                [
                    'teacher_profile_id' => $extraProfile->id,
                    'type' => 'deposit',
                    'amount' => 5,
                    'description' => 'Recarga inicial (dato de prueba)',
                ]
            );
        }

        for ($i = 1; $i <= 2; $i++) {
            $extraParentUser = User::firstOrCreate(
                ['email' => "padre-relleno-{$i}@mova.test"],
                [
                    'name' => fake()->name(),
                    'password' => $password,
                    'email_verified_at' => now(),
                ]
            );
            if (! $extraParentUser->hasRole('parent')) {
                $extraParentUser->assignRole('parent');
            }

            Student::firstOrCreate(
                ['parent_user_id' => $extraParentUser->id],
                [
                    'first_name' => fake()->firstName(),
                    'last_name' => fake()->lastName(),
                    'grade_level' => fake()->randomElement(['primaria', 'secundaria', 'universidad']),
                ]
            );
        }

        $this->command->info('LocalTestDataSeeder completado.');
        $this->command->table(
            ['Rol', 'Email', 'Password'],
            [
                ['Padre', 'padre@mova.test', 'password123'],
                ['Profesor', 'profesor@mova.test', 'password123'],
            ]
        );
        $this->command->line('Datos relacionales: 2 hijos, 3 solicitudes pendientes, 2 clases programadas, 2 clases completadas con reporte + reseña, 2 profesores y 2 padres extra aleatorios.');
    }
}
