<?php

namespace App\Infrastructure\Contracts;

use App\Models\Contract;

final class ContractHistoryEntry
{
    public static function fromContract(Contract $contract): array
    {
        return [
            'Contrato' => $contract->numero ?? '',
            'Fundo' => $contract->fundo_nome_snapshot ?? '',
            'Fiscal' => ($contract->nome_fiscal ?? '')."\n".($contract->decreto_fiscal ?? ''),
            'Historico' => implode(' - ', [
                $contract->data_contrato?->format('d/m/Y') ?? '',
                $contract->tipo ?? '',
                trim(($contract->modalidade ?? '').' '.($contract->n_licit ?? '')),
                $contract->processo ?? '',
                'OBJETO: '.($contract->objeto ?? ''),
            ]),
        ];
    }
}
