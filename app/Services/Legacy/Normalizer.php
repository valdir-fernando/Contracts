<?php

namespace App\Services\Legacy;

use App\Domain\Shared\BrazilianDocument;
use App\Domain\Shared\DecimalAmount;
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
        return BrazilianDocument::document($value);
    }

    public static function validDocument(string $digits): bool
    {
        return BrazilianDocument::validDocument($digits);
    }

    public static function date(?string $value): ?string
    {
        $value = trim($value ?? '');
        if ($value === '' || ! preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) || substr($value, 0, 4) < '1000') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    public static function money(?string $value): ?string
    {
        return DecimalAmount::normalize($value);
    }

    public static function mask(?string $value): string
    {
        return BrazilianDocument::mask($value);
    }
}
