<?php

namespace App\Infrastructure\Contracts;

use App\Domain\Contracts\ProductOrigin;
use App\Infrastructure\Persistence\TransactionGuard;
use App\Models\ActivityEvent;
use App\Models\Contract;
use App\Models\ContractAmendmentProduct;
use App\Models\ContractAudit;
use App\Models\ContractProduct;
use App\Models\ImportRun;
use App\Models\LegacyRecord;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Explicit system maintenance: archive original rows only after an exact copy and audit. */
final class SeparateAmendmentProducts
{
    public function run(bool $dryRun = true): array
    {
        if ($dryRun) {
            DB::beginTransaction();
        }
        try {
            return DB::transaction(function () {
                TransactionGuard::requireActive();
                $counts = ['copied' => 0, 'archived' => 0, 'already_separated' => 0, 'contracts' => 0];
                $run = null;
                // Same contract-first order as the product importer; no human actor is impersonated.
                $contractIds = ContractProduct::withTrashed()->distinct()->orderBy('contract_id')->pluck('contract_id');
                foreach ($contractIds as $contractId) {
                    $contract = Contract::withTrashed()->whereKey($contractId)->lockForUpdate()->firstOrFail();
                    $items = ContractProduct::withTrashed()->where('contract_id', $contractId)->orderBy('id')->lockForUpdate()->get();
                    $sources = LegacyRecord::whereIn('id', $items->pluck('legacy_record_id')->filter())->get()->keyBy('id');
                    $moved = [];
                    foreach ($items as $item) {
                        $source = $sources[$item->legacy_record_id] ?? null;
                        $raw = $source?->source_table === 'tb_Produtos_Contratos' ? $source->raw_payload : [];
                        $attributes = $item->getAttributes();
                        if (! ProductOrigin::isAmendment($raw) && ! ProductOrigin::isAmendment($attributes)) {
                            continue;
                        }
                        foreach (['ADT', 'Proc_ADT'] as $field) {
                            $attributes[$field] = trim((string) ($attributes[$field] ?? '')) !== '' ? $attributes[$field] : ($raw[$field] ?? null);
                        }
                        $existing = ContractAmendmentProduct::withTrashed()->whereKey($item->id)->lockForUpdate()->first();
                        if ($existing) {
                            foreach ($attributes as $field => $value) {
                                if ($field === 'deleted_at') {
                                    continue; // The archive timestamp is intentionally different.
                                }
                                if ((string) $existing->getRawOriginal($field) !== (string) $value) {
                                    throw new RuntimeException('Conflito na cópia do produto '.$item->id.'; separação revertida.');
                                }
                            }
                        }
                        if ($existing && $item->trashed()) {
                            $counts['already_separated']++;

                            continue;
                        }
                        $run ??= ImportRun::create(['source_hash' => hash('sha256', 'separate-amendment-products-v1'), 'status' => 'running', 'started_at' => now()]);
                        if (! $existing) {
                            DB::table('tb_Prtodutos_Aditivos')->insert($attributes);
                            $counts['copied']++;
                        }
                        if (! $item->trashed()) {
                            DB::table('tb_Produtos_Contratos')->where('id', $item->id)->update(['deleted_at' => now()]);
                            $counts['archived']++;
                        }
                        $moved[] = $item->id;
                    }
                    if ($moved === []) {
                        continue;
                    }
                    $before = $contract->version;
                    $contract->version++;
                    $contract->updated_by = null;
                    $contract->save();
                    ContractAudit::create(['contract_id' => $contractId, 'actor_id' => null, 'import_run_id' => $run->id,
                        'action' => 'amendment_products_separated',
                        'reason' => 'Separação de produtos de aditivos; origens arquivadas, IDs, valores e saldos preservados.',
                        'changes' => ['product_ids' => $moved, 'source_table' => 'tb_Produtos_Contratos', 'destination_table' => 'tb_Prtodutos_Aditivos',
                            'version' => ['before' => $before, 'after' => $contract->version]], 'created_at' => now()]);
                    $counts['contracts']++;
                }
                if ($run) {
                    $run->update(['status' => 'completed', 'source_count' => $counts['copied'], 'imported_count' => $counts['copied'], 'summary' => $counts, 'finished_at' => now()]);
                    ActivityEvent::create(['actor_id' => null, 'action' => 'amendment_products_separated', 'metadata' => ['import_run_id' => $run->id] + $counts, 'created_at' => now()]);
                }

                return $counts;
            }, 3);
        } finally {
            if ($dryRun) {
                DB::rollBack();
            }
        }
    }
}
