<?php

namespace App\Infrastructure\Contracts;

use App\Domain\Shared\DecimalAmount;
use App\Infrastructure\Persistence\TransactionGuard;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\ContractAudit;
use App\Models\ContractHistoryRecord;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/** Explicit CLI system maintenance. No authenticated user is impersonated. */
final class ReconcileContractAccumulatedValues
{
    public function run(bool $dryRun = true): array
    {
        return DB::transaction(function () use ($dryRun) {
            TransactionGuard::requireActive();
            $result = ['mode' => $dryRun ? 'simulation' : 'apply', 'checked' => 0, 'ok' => 0,
                'divergent' => 0, 'updated' => 0, 'pending' => 0, 'differences' => [], 'issues' => []];
            // Same lock order as the amendment writer: contract before its amendments.
            $contracts = Contract::whereIn('id', ContractAmendment::select('contract_id'))
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($contracts as $contract) {
                $amendments = ContractAmendment::where('contract_id', $contract->id)->orderBy('id')->lockForUpdate()->get();
                if ($amendments->isEmpty()) {
                    continue;
                }
                $result['checked']++;
                $original = DecimalAmount::normalize($contract->valor_total);
                $issue = $original === null ? 'missing_original_value' : null;
                $pendingHistory = ContractHistoryRecord::where('kind', 'amendment')->where('contract_id', $contract->id)
                    ->whereNotIn('legacy_record_id', ContractAmendment::withTrashed()->whereNotNull('legacy_record_id')->select('legacy_record_id'))
                    ->orderBy('id')->lockForUpdate()->get(['id']);
                if ($pendingHistory->isNotEmpty()) {
                    $issue = 'unimported_amendments';
                }
                $sum = BigDecimal::zero()->toScale(4);
                $sources = [];
                foreach ($amendments as $amendment) {
                    $amount = DecimalAmount::normalize($amendment->amount);
                    if ($amount === null) {
                        $issue ??= 'missing_amendment_value';
                    } elseif (! in_array($amendment->status, ['registrado', 'importado'], true)) {
                        $issue ??= 'unconfirmed_amendment';
                    } else {
                        $sum = $sum->plus($amount);
                    }
                    $sources[] = ['id' => $amendment->id, 'version' => $amendment->version, 'amount' => $amount];
                }
                $expected = $original === null ? null : DecimalAmount::normalize((string) $sum->plus($original));
                if ($expected === null) {
                    $issue ??= 'amount_out_of_range';
                }
                if ($issue !== null) {
                    $result['pending']++;
                    $result['issues'][] = ['contract_id' => $contract->id, 'number' => $contract->numero, 'reason' => $issue];

                    continue;
                }
                $state = ContractMapper::toDomain($contract);
                $current = DecimalAmount::normalize($contract->valor_acumulado);
                if ($current === $expected) {
                    $result['ok']++;

                    continue;
                }
                $difference = ['contract_id' => $contract->id, 'number' => $contract->numero,
                    'original' => $original, 'amendments_sum' => (string) $sum, 'before' => $current, 'after' => $expected];
                $result['differences'][] = $difference;
                $result['divergent']++;
                if ($dryRun) {
                    continue;
                }
                $updated = ContractMapper::toModel($state->with(['valor_acumulado' => $expected,
                    'version' => $state->version() + 1, 'updated_by' => null]));
                $updated->save();
                ContractAudit::create(['contract_id' => $contract->id, 'actor_id' => null,
                    'action' => 'accumulated_value_reconciled',
                    'reason' => 'Conciliação CLI autorizada: valor original do contrato + soma dos valores VL_ADT dos aditivos vinculados.',
                    'changes' => ['valor_acumulado' => ['before' => $current, 'after' => $expected],
                        'version' => ['before' => $state->version(), 'after' => $updated->version],
                        'reconciliation' => ['before' => null, 'after' => ['original' => $original,
                            'amendments_sum' => (string) $sum, 'amendments' => $sources]]], 'created_at' => now()]);
                $result['updated']++;
            }

            return $result;
        }, 3);
    }
}
