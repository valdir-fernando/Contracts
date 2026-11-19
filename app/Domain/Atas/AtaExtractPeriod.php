<?php

namespace App\Domain\Atas;

use DateTimeImmutable;

final class AtaExtractPeriod
{
    private const ENDING = ' e poderá ser prorrogado, por igual período, desde que comprovado o preço vantajoso, art. 84 da Lei Federal 14.133/21.';

    public static function text(bool $oneYearInpc, ?string $period, ?string $start, ?string $end): string
    {
        return 'O registro de preços formalizado na presente ata terá vigência de '.self::duration($oneYearInpc, $period, $start, $end).self::ENDING;
    }

    public static function duration(bool $oneYearInpc, ?string $period, ?string $start, ?string $end): string
    {
        if ($oneYearInpc) {
            return '1 (um) ano, contado a partir do primeiro dia útil subsequente à data de divulgação no PNCP';
        }
        $date = static fn (?string $value) => $value ? (new DateTimeImmutable($value))->format('d/m/Y') : 'Não informado';

        return (trim($period ?? '') ?: 'Não informado').', iniciando-se em '.$date($start).' e término em '.$date($end);
    }
}
