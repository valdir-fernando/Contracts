<?php

namespace App\Domain\Atas;

use DateTimeImmutable;

final class AtaExtractPeriod
{
    private const ENDING = ' e poderá ser prorrogado, por igual período, desde que comprovado o preço vantajoso, art. 84 da Lei Federal 14.133/21.';

    public static function text(bool $oneYearInpc, ?string $period, ?string $start, ?string $end): string
    {
        $prefix = 'O registro de preços formalizado na presente ata terá vigência de ';
        if ($oneYearInpc) {
            return $prefix.'1 (um) ano, contado a partir do primeiro dia útil subsequente à data de divulgação no PNCP'.self::ENDING;
        }
        $date = static fn (?string $value) => $value ? (new DateTimeImmutable($value))->format('d/m/Y') : 'Não informado';

        return $prefix.(trim($period ?? '') ?: 'Não informado').', iniciando-se em '.$date($start).' e término em '.$date($end).self::ENDING;
    }
}
