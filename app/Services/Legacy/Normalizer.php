<?php

namespace App\Services\Legacy;

use DateTimeImmutable;
use Illuminate\Support\Str;

class Normalizer
{
    public static function name(?string $value): string
    {
        return Str::upper(Str::ascii(preg_replace('/\s+/u', ' ', trim($value ?? ''))));
    }

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

    public static function date(?string $value): ?string
    {
        $value = trim($value ?? '');
        if ($value === '' || $value === '0000-00-00') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    /** String conversion only: DECIMAL(18,4), without floating-point rounding. */
    public static function money(?string $value): ?string
    {
        $value = trim($value ?? '');
        if ($value === '') {
            return null;
        }
        if (str_contains($value, ',')) {
            if (! preg_match('/^-?(?:\d{1,3}(?:\.\d{3})+|\d+)(?:,\d{1,4})?$/D', $value)) {
                return null;
            }
            $value = str_replace(['.', ','], ['', '.'], $value);
        } elseif (! preg_match('/^-?\d+(?:\.\d{1,4})?$/D', $value)) {
            return null;
        }
        $negative = str_starts_with($value, '-');
        [$integer, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';
        if (strlen($integer) > 14) {
            return null;
        }

        return ($negative && ($integer !== '0' || trim($fraction, '0') !== '') ? '-' : '').$integer.'.'.str_pad($fraction, 4, '0');
    }

    public static function mask(?string $value): string
    {
        $digits = self::document($value);

        return strlen($digits) >= 4 ? '•••'.substr($digits, -4) : '•••';
    }
}
