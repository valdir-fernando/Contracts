<?php

namespace App\Domain\Atas;

use App\Domain\Shared\DecimalAmount;

final class AtaAnnualIndex
{
    public static function normalize(?string $value): ?string
    {
        $text = str_replace(',', '.', trim($value ?? ''));
        if (! preg_match('/^(\d+)(?:\.(\d+))?%?$/D', $text, $parts)) {
            return null;
        }

        return (ltrim($parts[1], '0') ?: '0').(isset($parts[2]) ? '.'.$parts[2] : '');
    }

    public static function adjustedUnitPrice(string $price, string $index): ?string
    {
        $scale = strlen(explode('.', $index, 2)[1] ?? '') + 2;
        $factor = bcadd('1', bcdiv($index, '100', $scale), $scale);

        return DecimalAmount::normalize(bcmul($price, $factor, 4));
    }
}
