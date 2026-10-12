<?php

namespace App\Domain\Shared;

final class BrazilianDocument
{
    public static function document(?string $value): string
    {
        return preg_replace('/\D/', '', $value ?? '');
    }

    public static function validDocument(string $digits): bool
    {
        if (! in_array(strlen($digits), [11, 14]) || preg_match('/^(\d)\1+$/', $digits)) {
            return false;
        }
        $base = strlen($digits) === 11 ? 9 : 12;
        for ($round = 0; $round < 2; $round++) {
            $sum = 0;
            for ($i = 0; $i < $base + $round; $i++) {
                $weight = $base === 9 ? $base + $round + 1 - $i : (($base + $round - 1 - $i) % 8) + 2;
                $sum += (int) $digits[$i] * $weight;
            }
            $digit = $sum % 11 < 2 ? 0 : 11 - $sum % 11;
            if ((int) $digits[$base + $round] !== $digit) {
                return false;
            }
        }

        return true;
    }

    public static function mask(?string $value): string
    {
        $digits = self::document($value);

        return strlen($digits) >= 4 ? '•••'.substr($digits, -4) : '•••';
    }
}
