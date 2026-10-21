<?php

namespace App\Domain\Contracts;

final class BudgetCode
{
    public const PARTS = ['Gestao', 'Unidade', 'Funcao', 'SubFunc', 'Programa', 'Projeto', 'Codigo'];

    public static function compose(array $fields): string
    {
        return implode('.', array_map(static fn ($key) => (string) ($fields[$key] ?? ''), self::PARTS))
            .'('.($fields['Fonte'] ?? '').')'.($fields['Ficha'] ?? '');
    }
}
