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
        return $this->values($contract, $ficha);
    }

    /** Sum only the selected ficha's original value records, after contract authorization. */
    public function selectedTotal(Contract $contract, string $ficha): ?string
    {
        return $this->summarize($this->values($contract, trim($ficha), true), null)['total'];
    }

    private function values(Contract $contract, ?string $ficha, bool $strictEmptyAmendment = false): array
    {
        $fund = Normalizer::name($contract->fundo_nome_snapshot);
        if ($ficha === '' || $fund === '' || ! filled($contract->numero) || ! filled($contract->processo)) {
            return [];
        }
        $rows = DB::table('tb_ValorFicha')->when($ficha !== null, fn ($query) => $query->whereRaw('TRIM(Ficha) = ?', [$ficha]))
            ->whereRaw('TRIM(Contrato) = ?', [trim($contract->numero)])
            ->whereRaw('TRIM(Proc_Cont) = ?', [trim($contract->processo)])
            ->whereRaw("TRIM(COALESCE(Proc_ADT, '')) = ''")
            ->where(fn ($query) => $strictEmptyAmendment
                ? $query->whereNull('N_ADT')
                : $query->whereNull('N_ADT')->orWhere('N_ADT', 0))
            ->orderBy('ID_Ficha')->get(['Ficha', 'Valor', 'Fundo']);
        $amendmentFichas = $strictEmptyAmendment ? [] : (new OriginalContractProducts)->amendmentOnlyFichas($contract);
        $result = [];
        foreach ($rows as $row) {
            if (Normalizer::name($row->Fundo) === $fund && ! in_array(trim($row->Ficha), $amendmentFichas, true)) {
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

    /** One authorized projection for the panel; product totals fill missing original ficha values. */
    public function panel(array $fichaValues, array $productValues, ?string $contractTotal): array
    {
        $rows = [];
        foreach ($fichaValues as $row) {
            $label = trim((string) $row['label']);
            if ($label === '') {
                continue;
            }
            $key = 'ficha:'.$label;
            $amount = DecimalAmount::normalize($row['total']);
            if (isset($rows[$key])) {
                $previous = $rows[$key]['total'];
                $amount = $previous === null || $amount === null ? null : (string) BigDecimal::of($previous)->plus($amount)->toScale(4);
            }
            $rows[$key] = ['label' => $label, 'total' => $amount, 'source' => 'ficha'];
        }
        foreach ($productValues as $row) {
            $label = trim((string) $row['label']);
            $key = 'ficha:'.$label;
            if ($label !== '' && ! isset($rows[$key])) {
                $rows[$key] = ['label' => $label, 'total' => DecimalAmount::normalize($row['total']), 'source' => 'products'];
            }
        }
        $rows = array_values($rows);
        usort($rows, fn ($a, $b) => strnatcmp($a['label'], $b['label']));

        return ['rows' => $rows, 'summary' => $this->summarize($rows, $contractTotal)];
    }
}
