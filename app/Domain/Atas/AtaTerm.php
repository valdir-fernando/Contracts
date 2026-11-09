<?php

namespace App\Domain\Atas;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

final class AtaTerm
{
    public static function end(DateTimeImmutable $start, string $period): DateTimeImmutable
    {
        if (! preg_match('/^\s*(\d{1,3})\s*(?:\([^)]*\)\s*)?(anos?|meses|mês|mes|dias?)\b/ui', $period, $match) || (int) $match[1] < 1) {
            throw new InvalidArgumentException('Período original da ata não reconhecido.');
        }
        $amount = (int) $match[1];
        $unit = mb_strtolower($match[2]);
        if (str_starts_with($unit, 'dia')) {
            return $start->add(new DateInterval('P'.$amount.'D'));
        }
        $months = str_starts_with($unit, 'ano') ? $amount * 12 : $amount;
        $index = (int) $start->format('Y') * 12 + (int) $start->format('n') - 1 + $months;
        $first = $start->setDate(intdiv($index, 12), $index % 12 + 1, 1);

        // Calendar anniversary: clamp to the last day when the target month is shorter.
        return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), min((int) $start->format('j'), (int) $first->format('t')));
    }
}
