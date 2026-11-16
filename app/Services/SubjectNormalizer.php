<?php

namespace App\Services;

class SubjectNormalizer
{
    private const ACCENTS = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
    ];

    public static function normalize(string $name): string
    {
        $value = mb_strtolower(trim($name));
        $value = strtr($value, self::ACCENTS);

        return trim(preg_replace('/\s+/', ' ', $value));
    }

    /**
     * Clave de EQUIVALENCIA entre materias: el nombre normalizado con cada palabra en singular («matematicas» y
     * «matematica» dan la misma clave). Solo se usa para emparejar solicitudes con profesores y para no crear una
     * materia casi duplicada; nunca reemplaza a `normalized_name` ni cambia ids. Regla deliberadamente mínima: quita
     * una «s» final de las palabras de más de 3 letras. No hace coincidencias difusas ni por significado.
     */
    public static function matchKey(string $name): string
    {
        $words = preg_split('/\s+/', self::normalize($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_map(
            static fn (string $w) => mb_strlen($w) > 3 && str_ends_with($w, 's') ? mb_substr($w, 0, -1) : $w,
            $words,
        ));
    }
}
