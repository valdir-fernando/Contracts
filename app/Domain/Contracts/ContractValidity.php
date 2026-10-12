<?php

namespace App\Domain\Contracts;

use DateTimeInterface;

/** Civil-date classification; this is not a legal assessment of a contract. */
final class ContractValidity
{
    public static function classify(bool $underReview, ?DateTimeInterface $start, ?DateTimeInterface $end, DateTimeInterface $reference): string
    {
        if ($underReview) {
            return 'em_revisao';
        }
        if ($start === null || $end === null) {
            return 'indeterminado';
        }
        if ($end->format('Y-m-d') < $reference->format('Y-m-d')) {
            return 'vencido';
        }

        return $start->format('Y-m-d') > $reference->format('Y-m-d') ? 'a_iniciar' : 'vigente';
    }

    public static function endsBeforeStart(?DateTimeInterface $start, ?DateTimeInterface $end): bool
    {
        return $start !== null && $end !== null && $end->format('Y-m-d') < $start->format('Y-m-d');
    }
}
