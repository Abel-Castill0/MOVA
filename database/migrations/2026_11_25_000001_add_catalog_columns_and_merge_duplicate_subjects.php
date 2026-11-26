<?php

use App\Services\SubjectNormalizer;
use App\Support\SubjectCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Catálogo maestro de materias (App\Support\SubjectCatalog).
 *
 * 1. Añade `slug` (clave estable y ÚNICA: impide duplicados futuros), `category` e `icon`.
 * 2. Fusiona las materias que ya existían duplicadas o mal escritas: se agrupan por la misma regla de
 *    equivalencia que ya usaba la app (SubjectNormalizer::matchKey: sin tildes, minúsculas, singular), así
 *    «Matemáticas» y «Matemática» caen en «Matemática» y «algebra» en «Álgebra». Antes de borrar un duplicado
 *    se mueven TODAS sus referencias a la fila que queda (solicitudes, ofertas, diagnósticos y materias de
 *    profesores), porque las FK de class_requests/class_offers/teacher_subject borran en cascada.
 * 3. Las filas que coinciden con el catálogo toman su nombre, nivel, categoría e ícono. Las demás (texto
 *    libre que no está en el catálogo) se conservan, cada una con su propio slug terminado en su id (nunca se
 *    fusionan entre sí: «C++» y «C#» darían el mismo slug), y sin categoría: no son elegibles por profesores
 *    nuevos (TeacherProfileController::subjectRules) pero no se pierde su historial.
 *
 * Reintentable: en MySQL cada DDL se confirma solo, así que si algo falla a mitad, volver a ejecutar
 * `migrate` retoma desde donde quedó (columnas e índice se crean solo si faltan; la fusión es idempotente).
 *
 * Las materias del catálogo que faltan las crea SubjectSeeder (idempotente), que se ejecuta después.
 * La fusión no se puede deshacer: down() solo quita las columnas.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'slug' => fn (Blueprint $t) => $t->string('slug', 80)->nullable()->after('normalized_name'),
            'category' => fn (Blueprint $t) => $t->string('category', 32)->nullable()->after('level'),
            'icon' => fn (Blueprint $t) => $t->string('icon', 32)->nullable()->after('category'),
        ] as $column => $add) {
            if (! Schema::hasColumn('subjects', $column)) {
                Schema::table('subjects', $add);
            }
        }

        DB::transaction(function () {
            $catalog = collect(SubjectCatalog::entries())->keyBy(fn (array $e) => SubjectNormalizer::matchKey($e['name']));

            $groups = DB::table('subjects')->orderBy('id')->get()->groupBy(function ($subject) use ($catalog) {
                $entry = $catalog->get(SubjectNormalizer::matchKey($subject->name));

                // Fuera del catálogo: grupo propio por id (no se fusiona con nada).
                return $entry['slug'] ?? 'id:'.$subject->id;
            });

            foreach ($groups as $slug => $rows) {
                $entry = $catalog->first(fn (array $e) => $e['slug'] === $slug);

                // Se queda la fila escrita igual que el catálogo (sin contar tildes ni mayúsculas); si ninguna lo está,
                // la más antigua.
                $keep = $entry
                    ? ($rows->first(fn ($r) => $r->normalized_name === SubjectNormalizer::normalize($entry['name'])) ?? $rows->first())
                    : $rows->first();

                foreach ($rows->where('id', '!=', $keep->id) as $duplicate) {
                    $this->mergeInto($duplicate->id, $keep->id);
                    Log::info('SUBJECT_MERGED', ['from' => $duplicate->id, 'from_name' => $duplicate->name, 'into' => $keep->id, 'slug' => $slug]);
                }

                $update = ['slug' => $entry
                    ? $slug
                    : (Str::limit(Str::slug($keep->name), 60, '') ?: 'materia').'-'.$keep->id];
                if ($entry) {
                    $update += [
                        'name' => $entry['name'],
                        'normalized_name' => SubjectNormalizer::normalize($entry['name']),
                        'level' => $entry['level'],
                        'category' => $entry['category'],
                        'icon' => $entry['icon'],
                    ];
                }
                DB::table('subjects')->where('id', $keep->id)->update($update + ['updated_at' => now()]);
            }
        });

        // Solo el índice ÚNICO, sin ->change() a NOT NULL: en SQLite ese cambio reconstruye la tabla `subjects`
        // (crear copia + borrar la original) y el borrado dispara los ON DELETE CASCADE de class_requests,
        // class_offers y teacher_subject. El modelo Subject completa siempre el slug al guardar.
        if (! Schema::hasIndex('subjects', ['slug'], 'unique')) {
            Schema::table('subjects', function (Blueprint $table) {
                $table->unique('slug');
            });
        }
    }

    private function mergeInto(int $fromId, int $toId): void
    {
        foreach (['class_requests', 'class_offers', 'student_diagnostics'] as $table) {
            DB::table($table)->where('subject_id', $fromId)->update(['subject_id' => $toId]);
        }

        // Materias de profesores: clave primaria (teacher_profile_id, subject_id). Si el profesor ya tenía la materia
        // que queda, sobra la fila duplicada; si no, se reapunta conservando su tarifa específica.
        foreach (DB::table('teacher_subject')->where('subject_id', $fromId)->get() as $pivot) {
            $alreadyHas = DB::table('teacher_subject')
                ->where('teacher_profile_id', $pivot->teacher_profile_id)
                ->where('subject_id', $toId)
                ->exists();

            $query = DB::table('teacher_subject')
                ->where('teacher_profile_id', $pivot->teacher_profile_id)
                ->where('subject_id', $fromId);

            $alreadyHas ? $query->delete() : $query->update(['subject_id' => $toId]);
        }

        DB::table('subjects')->where('id', $fromId)->delete();
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn(['slug', 'category', 'icon']);
        });
    }
};
