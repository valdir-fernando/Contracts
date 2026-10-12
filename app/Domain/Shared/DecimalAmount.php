<?php

namespace App\Domain\Shared;

final class DecimalAmount
{
    /** String conversion only: DECIMAL(18,4), without floating-point rounding. */
    public static function normalize(?string $value): ?string
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
}
