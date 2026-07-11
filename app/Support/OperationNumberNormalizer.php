<?php

namespace App\Support;

final class OperationNumberNormalizer
{
    public static function normalize(string $value): string
    {
        $normalized = trim($value);
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', '', $normalized) ?? '';

        return mb_strtoupper($normalized, 'UTF-8');
    }
}
