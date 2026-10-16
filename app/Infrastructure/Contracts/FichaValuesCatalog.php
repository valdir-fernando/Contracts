<?php

namespace App\Infrastructure\Contracts;

use App\Domain\Shared\DecimalAmount;
use App\Models\Contract;
use App\Services\Legacy\Normalizer;
use Illuminate\Support\Facades\DB;

final class FichaValuesCatalog
{
    /** Called only after authorization of the current contract. */
    public function lookup(Contract $contract, string $ficha): array
    {
        $document = Normalizer::document($contract->fornecedor_documento_snapshot);
        $fund = Normalizer::name($contract->fundo_nome_snapshot);
        if ($ficha === '' || $fund === '' || ! filled($contract->numero) || ! Normalizer::validDocument($document)) {
            return [];
        }
        $rows = DB::table('tb_ValorFicha')->whereRaw('TRIM(Ficha) = ?', [$ficha])
            ->whereRaw('TRIM(Contrato) = ?', [trim($contract->numero)])
            ->whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(CPF_CNPJ, '.', ''), '/', ''), '-', ''), ' ', '') = ?", [$document])
            ->orderBy('ID_Ficha')->get(['Ficha', 'Valor', 'Fundo']);
        $result = [];
        foreach ($rows as $row) {
            if (Normalizer::name($row->Fundo) === $fund) {
                $result[] = ['label' => trim($row->Ficha), 'total' => DecimalAmount::normalize($row->Valor)];
            }
        }

        return $result;
    }
}
