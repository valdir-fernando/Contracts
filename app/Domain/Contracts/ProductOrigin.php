<?php

namespace App\Domain\Contracts;

final class ProductOrigin
{
    public static function isAmendment(array $fields): bool
    {
        return trim((string) ($fields['ADT'] ?? '')) !== ''
            || trim((string) ($fields['Proc_ADT'] ?? '')) !== '';
    }
}
