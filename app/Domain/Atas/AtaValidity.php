<?php

namespace App\Domain\Atas;

use DateTimeInterface;

/** Documentary dashboard classification, with an explicit civil reference date. */
final class AtaValidity
{
    public static function classify(?DateTimeInterface $start, ?DateTimeInterface $end, DateTimeInterface $reference): string
    {
        if ($start === null || $end === null || $end->format('Y-m-d') < $start->format('Y-m-d')) {
            return 'indeterminado';
        }
        if ($end->format('Y-m-d') <= $reference->format('Y-m-d')) {
            return 'vencido';
        }

        return $start->format('Y-m-d') > $reference->format('Y-m-d') ? 'a_iniciar' : 'vigente';
    }
}
