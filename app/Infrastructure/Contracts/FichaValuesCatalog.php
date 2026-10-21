<?php

namespace App\Infrastructure\Contracts;

use App\Domain\Shared\DecimalAmount;
use App\Models\Contract;
use App\Services\Legacy\Normalizer;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

final class FichaValuesCatalog
{
    /** Called only after authorization of the current contract. */
    public function lookup(Contract $contract, ?string $ficha = null): array
    {
        $fund = Normalizer::name($contract->fundo_nome_snapshot);
        if ($ficha === '' || $fund === '' || ! filled($contract->numero) || ! filled($contract->processo)) {
            return [];
        }
        $rows = DB::table('tb_ValorFicha')->when($ficha !== null, fn ($query) => $query->whereRaw('TRIM(Ficha) = ?', [$ficha]))
            ->whereRaw('TRIM(Contrato) = ?', [trim($contract->numero)])
            ->whereRaw('TRIM(Proc_Cont) = ?', [trim($contract->processo)])
            ->whereRaw("TRIM(COALESCE(Proc_ADT, '')) = ''")
            ->where(fn ($query) => $query->whereNull('N_ADT')->orWhere('N_ADT', 0))
            ->orderBy('ID_Ficha')->get(['Ficha', 'Valor', 'Fundo']);
        $result = [];
        foreach ($rows as $row) {
            if (Normalizer::name($row->Fundo) === $fund) {
                $result[] = ['label' => trim($row->Ficha), 'total' => DecimalAmount::normalize($row->Valor)];
            }
        }

        return $result;
    }

    public function summarize(array $rows, ?string $contractTotal): array
    {
        $sum = BigDecimal::zero();
        $valid = true;
        foreach ($rows as $row) {
            $amount = DecimalAmount::normalize($row['total']);
            if ($amount === null) {
                $valid = false;
                break;
            }
            $sum = $sum->plus($amount);
        }
        $expected = DecimalAmount::normalize($contractTotal);
        $comparable = $valid && $expected !== null;

        return ['total' => $valid ? (string) $sum->toScale(4) : null, 'contract_total' => $expected,
            'difference' => $comparable ? (string) $sum->minus($expected)->toScale(4) : null,
            'matches' => $comparable ? $sum->isEqualTo($expected) : null];
    }
}
