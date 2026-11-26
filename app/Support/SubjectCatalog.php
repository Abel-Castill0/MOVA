<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Catálogo maestro CERRADO de materias de MOVA (Perú: primaria, secundaria, preuniversitario y universidad).
 *
 * Es la única fuente de las materias que existen: los profesores ya no crean materias escribiendo texto libre,
 * solo marcan materias de esta lista. Lo siembra Database\Seeders\SubjectSeeder (idempotente, por `slug`), y la
 * migración 2026_11_25_000001 lo usa para fusionar duplicados que ya existían («Matemáticas» → «Matemática»,
 * «algebra» → «Álgebra»).
 *
 * Que una materia exista aquí NO la publica: aparece en «Materias disponibles» solo cuando la marca al menos un
 * profesor verificado con la cuenta activa (Subject::scopeAvailableForPublic).
 *
 * Para añadir una materia: agrégala aquí y ejecuta `php artisan db:seed --class=SubjectSeeder --force`.
 * No cambies el nombre de una existente sin pensar en su slug: el slug es la clave estable.
 */
final class SubjectCatalog
{
    /** Orden y rótulo de los grupos del selector del profesor. */
    public const CATEGORIES = [
        'matematicas' => 'Matemáticas',
        'ciencias' => 'Ciencias',
        'letras' => 'Letras y humanidades',
        'idiomas' => 'Idiomas',
        'tecnologia' => 'Tecnología y otros',
    ];

    /**
     * name, level (enum de subjects: primaria|secundaria|universidad|todos), category, icon (clave que
     * resources/js/utils/subjectIcon.js traduce a un ícono Lucide).
     */
    private const ENTRIES = [
        // Matemáticas
        ['Matemática', 'todos', 'matematicas', 'calculator'],
        ['Álgebra', 'secundaria', 'matematicas', 'sigma'],
        ['Aritmética', 'todos', 'matematicas', 'calculator'],
        ['Geometría', 'todos', 'matematicas', 'triangle'],
        ['Trigonometría', 'secundaria', 'matematicas', 'triangle'],
        ['Razonamiento Matemático', 'secundaria', 'matematicas', 'puzzle'],
        ['Cálculo', 'universidad', 'matematicas', 'sigma'],
        ['Estadística', 'todos', 'matematicas', 'chart'],
        // Ciencias
        ['Física', 'secundaria', 'ciencias', 'zap'],
        ['Química', 'secundaria', 'ciencias', 'flask'],
        ['Biología', 'secundaria', 'ciencias', 'microscope'],
        ['Ciencias Naturales', 'primaria', 'ciencias', 'leaf'],
        // Letras y humanidades
        ['Lengua y Literatura', 'todos', 'letras', 'book'],
        ['Razonamiento Verbal', 'secundaria', 'letras', 'puzzle'],
        ['Comunicación', 'todos', 'letras', 'message'],
        ['Historia', 'todos', 'letras', 'landmark'],
        ['Geografía', 'todos', 'letras', 'globe'],
        ['Filosofía', 'secundaria', 'letras', 'brain'],
        ['Economía', 'todos', 'letras', 'chart'],
        // Idiomas
        ['Inglés', 'todos', 'idiomas', 'languages'],
        ['Francés', 'todos', 'idiomas', 'languages'],
        ['Portugués', 'todos', 'idiomas', 'languages'],
        ['Quechua', 'todos', 'idiomas', 'languages'],
        // Tecnología y otros
        ['Programación', 'todos', 'tecnologia', 'code'],
        ['Computación e Informática', 'todos', 'tecnologia', 'monitor'],
        ['Excel', 'todos', 'tecnologia', 'table'],
        // Ya existía en producción (la creó un profesor): se conserva dentro del catálogo.
        ['Robótica', 'todos', 'tecnologia', 'bot'],
        ['Contabilidad', 'universidad', 'tecnologia', 'chart'],
        ['Finanzas', 'universidad', 'tecnologia', 'wallet'],
        ['Música', 'todos', 'tecnologia', 'music'],
        ['Dibujo y Arte', 'todos', 'tecnologia', 'palette'],
    ];

    /** @return list<array{name: string, slug: string, level: string, category: string, icon: string}> */
    public static function entries(): array
    {
        return array_map(fn (array $e) => [
            'name' => $e[0],
            'slug' => self::slugFor($e[0]),
            'level' => $e[1],
            'category' => $e[2],
            'icon' => $e[3],
        ], self::ENTRIES);
    }

    /** @return array{name: string, slug: string, level: string, category: string, icon: string}|null */
    public static function find(?string $slug): ?array
    {
        foreach (self::entries() as $entry) {
            if ($entry['slug'] === $slug) {
                return $entry;
            }
        }

        return null;
    }

    public static function slugFor(string $name): string
    {
        return Str::slug($name);
    }

    public static function categoryLabel(?string $category): string
    {
        return self::CATEGORIES[$category] ?? 'Otras';
    }

    /** Posición del grupo para ordenar el selector (las categorías desconocidas van al final). */
    public static function categoryOrder(?string $category): int
    {
        $index = array_search($category, array_keys(self::CATEGORIES), true);

        return $index === false ? count(self::CATEGORIES) : $index;
    }
}
