<?php

namespace App\Domain\Contracts;

use InvalidArgumentException;
use OverflowException;

final readonly class ContractNumber
{
    public function __construct(public int $sequence, public int $year)
    {
        if ($sequence < 1 || $year < 1000 || $year > 9999) {
            throw new InvalidArgumentException('Número ou exercício inválido.');
        }
    }

    public function formatted(): string
    {
        return str_pad((string) $this->sequence, 3, '0', STR_PAD_LEFT).'/'.substr((string) $this->year, -2);
    }

    public function attributes(): array
    {
        return ['numero' => $this->formatted(), 'cont_inteiro' => (string) $this->sequence,
            'exercicio' => $this->year, 'number_year' => $this->year, 'number_sequence' => $this->sequence];
    }

    public static function integer(string $digits): int
    {
        if ($digits === '' || ! ctype_digit($digits)) {
            throw new InvalidArgumentException('Sequência deve conter somente dígitos.');
        }
        $digits = ltrim($digits, '0') ?: '0';
        $limit = (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($limit) || strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0) {
            throw new OverflowException('Sequência fora da faixa suportada.');
        }

        return (int) $digits;
    }

    public static function sequenceIn(string $number, int $year): ?int
    {
        if (! preg_match('~^([0-9]+)\s*/\s*([0-9]{2}|[0-9]{4})$~D', trim($number), $match)) {
            return null;
        }
        $suffix = strlen($match[2]) === 2 ? substr((string) $year, -2) : (string) $year;

        return $match[2] === $suffix ? self::integer($match[1]) : null;
    }
}
