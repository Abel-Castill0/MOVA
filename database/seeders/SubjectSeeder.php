<?php

namespace Database\Seeders;

use App\Models\Subject;
use App\Services\SubjectNormalizer;
use App\Support\SubjectCatalog;
use Illuminate\Database\Seeder;

/**
 * Siembra el catálogo maestro cerrado de materias (App\Support\SubjectCatalog). Idempotente: se puede ejecutar
 * cuantas veces haga falta (`php artisan db:seed --class=SubjectSeeder --force`); actualiza por `slug` y nunca
 * duplica ni borra. Sembrar una materia NO la publica: solo aparece en «Materias disponibles» cuando la marca un
 * profesor verificado y activo.
 */
class SubjectSeeder extends Seeder
{
    public function run(): void
    {
        foreach (SubjectCatalog::entries() as $entry) {
            // Por slug; si no, por nombre normalizado (una fila previa escrita igual pero aún sin slug de catálogo).
            $subject = Subject::where('slug', $entry['slug'])->first()
                ?? Subject::where('normalized_name', SubjectNormalizer::normalize($entry['name']))->first()
                ?? new Subject;

            $subject->fill([
                'name' => $entry['name'],
                'slug' => $entry['slug'],
                'level' => $entry['level'],
                'category' => $entry['category'],
                'icon' => $entry['icon'],
            ])->save();
        }
    }
}
