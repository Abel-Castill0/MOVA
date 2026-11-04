<?php

namespace App\Services;

class SubjectNormalizer
{
    private const ACCENTS = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
    ];

    // Solo equivalencias editoriales comprobadas: no agrupar materias
    // diferentes por semejanza aproximada o por coincidencias parciales.
    private const ALIASES = [
        'matematicas' => 'matematica',
    ];

    public static function normalize(string $name): string
    {
        $value = mb_strtolower(trim($name));
        $value = strtr($value, self::ACCENTS);

        $value = trim(preg_replace('/\s+/', ' ', $value));

        return self::ALIASES[$value] ?? $value;
    }

    /** Claves legadas que pudieron guardarse antes del catálogo canónico. */
    public static function storedVariants(string $name): array
    {
        $canonical = self::normalize($name);

        return array_merge([$canonical], array_keys(self::ALIASES, $canonical, true));
    }

    public static function displayName(string $name): string
    {
        return match (self::normalize($name)) {
            'matematica' => 'Matemática',
            'algebra' => 'Álgebra',
            default => trim($name),
        };
    }
}
