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
}
