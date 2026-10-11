<?php

namespace App\Services\Reports;

class CsvCell
{
    public static function safe(string $value): string
    {
        if (preg_match('/^-?\d+(?:\.\d+)?$/D', $value)) {
            return $value;
        }

        return preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $value) ? "'".$value : $value;
    }
}
