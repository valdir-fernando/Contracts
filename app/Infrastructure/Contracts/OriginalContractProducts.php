<?php

namespace App\Infrastructure\Contracts;

use App\Domain\Contracts\ProductOrigin;
use App\Models\Contract;
use App\Models\ContractAmendmentProduct;
use App\Models\ContractProduct;
use App\Models\LegacyRecord;
use App\Services\Legacy\Normalizer;
use Illuminate\Support\Collection;

/** Read the original instrument's items without changing imported amendment history. */
final class OriginalContractProducts
{
    public function forContract(Contract $contract): Collection
    {
        if (! $contract->exists) {
            return collect();
        }
        $items = ContractProduct::where('contract_id', $contract->id)
            ->whereRaw('TRIM(process_number) = ?', [trim($contract->processo ?? '')])
            ->orderBy('id')->get();
        $sources = LegacyRecord::whereIn('id', $items->pluck('legacy_record_id')->filter())
            ->get(['id', 'source_table', 'raw_payload'])->keyBy('id');

        return $items->filter(fn ($item) => $this->matches($item, $contract, $item->legacy_record_id ? ($sources[$item->legacy_record_id] ?? null) : null))->values();
    }

    public function isOriginal(ContractProduct $item, Contract $contract): bool
    {
        $source = $item->legacy_record_id ? LegacyRecord::find($item->legacy_record_id) : null;

        return $this->matches($item, $contract, $source);
    }

    /** Fichas used only by amendments, even when their value record lacks amendment markers. */
    public function amendmentOnlyFichas(Contract $contract): array
    {
        if (! $contract->exists || ! filled($contract->processo)) {
            return [];
        }
        $items = ContractProduct::where('contract_id', $contract->id)
            ->whereRaw('TRIM(process_number) = ?', [trim($contract->processo)])->get();
        $items = $items->concat(ContractAmendmentProduct::where('contract_id', $contract->id)
            ->whereRaw('TRIM(process_number) = ?', [trim($contract->processo)])->get());
        $sources = LegacyRecord::whereIn('id', $items->pluck('legacy_record_id')->filter())
            ->get(['id', 'source_table', 'raw_payload'])->keyBy('id');
        $original = [];
        $amendments = [];
        foreach ($items as $item) {
            $ficha = trim((string) $item->Ficha);
            $source = $item->legacy_record_id ? ($sources[$item->legacy_record_id] ?? null) : null;
            if ($ficha === '' || ! $this->identityMatches($item->getAttributes(), $contract)) {
                continue;
            }
            if ($this->matches($item, $contract, $source)) {
                $original[] = $ficha;

                continue;
            }
            if ($item->legacy_record_id && (! $source || $source->source_table !== 'tb_Produtos_Contratos'
                || ! $this->identityMatches($source->raw_payload, $contract))) {
                continue;
            }
            if (! $this->amendmentFieldsAreEmpty($item->getAttributes())
                || ($source && ! $this->amendmentFieldsAreEmpty($source->raw_payload))) {
                $amendments[] = $ficha;
            }
        }

        return array_values(array_unique(array_diff($amendments, $original)));
    }

    private function matches(ContractProduct $item, Contract $contract, ?LegacyRecord $source): bool
    {
        if ((int) $item->contract_id !== (int) $contract->id
            || trim((string) $item->process_number) !== trim($contract->processo ?? '')) {
            return false;
        }
        if (! $this->identityMatches($item->getAttributes(), $contract) || ! $this->amendmentFieldsAreEmpty($item->getAttributes())) {
            return false;
        }
        if (! $item->legacy_record_id) {
            return true;
        }
        if (! $source || $source->source_table !== 'tb_Produtos_Contratos' || ! filled($contract->processo)) {
            return false;
        }
        $raw = $source->raw_payload;

        return $this->amendmentFieldsAreEmpty($raw) && $this->identityMatches($raw, $contract);
    }

    private function amendmentFieldsAreEmpty(array $fields): bool
    {
        return ! ProductOrigin::isAmendment($fields);
    }

    private function identityMatches(array $fields, Contract $contract): bool
    {
        // Early native allocations predate the optional legacy identity columns.
        foreach (['Contrato' => $contract->numero, 'Processo' => $contract->processo, 'FUNDO' => $contract->fundo_nome_snapshot] as $field => $expected) {
            if (! filled($fields[$field] ?? null)) {
                continue;
            }
            $normalize = $field === 'FUNDO' ? Normalizer::name(...) : static fn ($value) => trim((string) $value);
            if ($normalize($fields[$field]) !== $normalize($expected)) {
                return false;
            }
        }

        return true;
    }
}
