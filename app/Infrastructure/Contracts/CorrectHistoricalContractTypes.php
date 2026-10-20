<?php

namespace App\Infrastructure\Contracts;

use App\Infrastructure\Persistence\TransactionGuard;
use App\Models\Contract;
use App\Models\ContractAudit;
use App\Support\ContractFields;
use Illuminate\Support\Facades\DB;

/** Explicit CLI maintenance with system identity, like legacy imports. */
final class CorrectHistoricalContractTypes
{
    public function execute(bool $dryRun = true): int
    {
        if ($dryRun) {
            return Contract::whereIn('tipo', array_keys(ContractFields::TYPE_CORRECTIONS))->get(['tipo'])
                ->filter(fn ($contract) => isset(ContractFields::TYPE_CORRECTIONS[$contract->tipo]))->count();
        }

        return DB::transaction(function () {
            TransactionGuard::requireActive();
            $count = 0;
            // No user is impersonated: this is a system maintenance operation.
            foreach (Contract::whereIn('tipo', array_keys(ContractFields::TYPE_CORRECTIONS))->orderBy('id')->lockForUpdate()->get() as $contract) {
                $before = $contract->tipo;
                $after = ContractFields::TYPE_CORRECTIONS[$before] ?? null;
                // MySQL collations may match accents/case; only exact known aliases are changed.
                if ($after === null) {
                    continue;
                }
                $version = $contract->version;
                $contract->tipo = $after;
                $contract->version++;
                $contract->updated_by = null;
                $contract->save();
                ContractAudit::create(['contract_id' => $contract->id, 'actor_id' => null, 'action' => 'updated',
                    'reason' => 'Correção de grafia do tipo histórico por manutenção CLI autorizada (contracts:correct-historical-types).',
                    'changes' => ['tipo' => ['before' => $before, 'after' => $after], 'version' => ['before' => $version, 'after' => $contract->version]],
                    'created_at' => now()]);
                $count++;
            }

            return $count;
        }, 3);
    }
}
