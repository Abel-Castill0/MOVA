<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\TeacherProfile;
use App\Models\User;
use Database\Seeders\SubjectSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Migración 2026_11_25_000001: fusiona las materias duplicadas que existían antes del catálogo cerrado sin perder
 * ninguna referencia (las FK de class_requests/teacher_subject borran en cascada si se borra la materia primero).
 * Reproduce el estado real de producción del 2026-11-25: «Matemáticas» (1) y «Matemática» (7) duplicadas,
 * «algebra» en minúscula y «robotica» creada por un profesor.
 */
class SubjectCatalogMergeMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicates_are_merged_into_the_catalog_name_keeping_every_reference(): void
    {
        $migration = require database_path('migrations/2026_11_25_000001_add_catalog_columns_and_merge_duplicate_subjects.php');
        $migration->down(); // estado previo: sin slug/category/icon

        $now = now();
        $id = fn (string $name, string $normalized) => DB::table('subjects')->insertGetId(['name' => $name, 'normalized_name' => $normalized, 'level' => 'todos', 'created_at' => $now, 'updated_at' => $now]);
        $plural = $id('Matemáticas', 'matematicas');
        $singular = $id('Matemática', 'matematica');
        $algebra = $id('algebra', 'algebra');
        $robotica = $id('robotica', 'robotica');
        $cpp = $id('C++', 'c++');
        $csharp = $id('C#', 'c#');

        $teacherA = TeacherProfile::create(['user_id' => User::factory()->create()->id]);
        $teacherB = TeacherProfile::create(['user_id' => User::factory()->create()->id]);
        DB::table('teacher_subject')->insert([
            ['teacher_profile_id' => $teacherA->id, 'subject_id' => $plural, 'specific_rate' => 25],
            ['teacher_profile_id' => $teacherA->id, 'subject_id' => $singular, 'specific_rate' => null], // ya tenía la que queda
            ['teacher_profile_id' => $teacherB->id, 'subject_id' => $plural, 'specific_rate' => 30],     // solo tenía el duplicado
            ['teacher_profile_id' => $teacherB->id, 'subject_id' => $algebra, 'specific_rate' => null],
        ]);

        $parent = User::factory()->create();
        $student = Student::create(['parent_user_id' => $parent->id, 'first_name' => 'Alumno', 'last_name' => 'Prueba', 'grade_level' => 'secundaria']);
        foreach ([$plural, $plural, $singular, $algebra] as $subjectId) {
            DB::table('class_requests')->insert(['student_id' => $student->id, 'subject_id' => $subjectId, 'help_needed' => 'Repaso.', 'status' => 'expired', 'created_at' => $now, 'updated_at' => $now]);
        }

        DB::table('class_offers')->insert(['teacher_profile_id' => $teacherB->id, 'subject_id' => $plural, 'title' => 'Refuerzo', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('student_diagnostics')->insert(['parent_user_id' => $parent->id, 'student_id' => $student->id, 'subject_id' => $plural, 'difficulty_text' => 'Fracciones', 'goal' => 'reinforce_topic', 'urgency' => 'flexible', 'status' => 'completed', 'created_at' => $now, 'updated_at' => $now]);

        $migration->up();
        $migration->up(); // reintento tras un fallo a medias: no duplica columnas ni cambia el resultado

        // «Matemáticas» se fusiona en «Matemática» (la fila escrita como el catálogo); no se pierde ninguna solicitud.
        $this->assertDatabaseMissing('subjects', ['id' => $plural]);
        $this->assertDatabaseHas('subjects', ['id' => $singular, 'name' => 'Matemática', 'slug' => 'matematica', 'category' => 'matematicas']);
        $this->assertSame(3, DB::table('class_requests')->where('subject_id', $singular)->count());
        $this->assertSame(4, DB::table('class_requests')->count());

        // Profesores: A conserva una sola fila; B pasa a la materia que queda con su tarifa.
        $this->assertSame(1, DB::table('teacher_subject')->where('teacher_profile_id', $teacherA->id)->count());
        $this->assertDatabaseHas('teacher_subject', ['teacher_profile_id' => $teacherB->id, 'subject_id' => $singular, 'specific_rate' => 30]);

        // «algebra» toma el nombre del catálogo con el MISMO id (sus referencias no se tocan).
        $this->assertDatabaseHas('subjects', ['id' => $algebra, 'name' => 'Álgebra', 'slug' => 'algebra', 'level' => 'secundaria']);
        $this->assertDatabaseHas('teacher_subject', ['teacher_profile_id' => $teacherB->id, 'subject_id' => $algebra]);
        $this->assertDatabaseHas('subjects', ['id' => $robotica, 'name' => 'Robótica', 'slug' => 'robotica', 'category' => 'tecnologia']);

        // Ofertas y diagnósticos también se reapuntan (no se pierden por la cascada / set null).
        $this->assertSame($singular, DB::table('class_offers')->value('subject_id'));
        $this->assertSame($singular, DB::table('student_diagnostics')->value('subject_id'));

        // Fuera del catálogo: se conservan por separado (no se fusionan aunque su slug base coincida) y sin categoría.
        $this->assertDatabaseHas('subjects', ['id' => $cpp, 'name' => 'C++', 'slug' => 'c-'.$cpp, 'category' => null]);
        $this->assertDatabaseHas('subjects', ['id' => $csharp, 'name' => 'C#', 'slug' => 'c-'.$csharp, 'category' => null]);

        // El seeder completa el catálogo reutilizando esas filas, sin duplicar.
        $this->seed(SubjectSeeder::class);
        $this->assertSame(1, DB::table('subjects')->where('slug', 'matematica')->count());
        $this->assertSame($singular, DB::table('subjects')->where('slug', 'matematica')->value('id'));
        $this->assertSame($algebra, DB::table('subjects')->where('slug', 'algebra')->value('id'));
        $this->assertSame(count(\App\Support\SubjectCatalog::entries()) + 2, DB::table('subjects')->count()); // + C++ y C#

        // El índice único impide un duplicado futuro.
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('subjects')->insert(['name' => 'Algebra', 'normalized_name' => 'algebra x', 'slug' => 'algebra', 'level' => 'todos']);
    }
}
