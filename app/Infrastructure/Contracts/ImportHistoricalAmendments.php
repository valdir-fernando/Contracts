<?php

namespace App\Infrastructure\Contracts;

use App\Infrastructure\Persistence\TransactionGuard;
use App\Models\ActivityEvent;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\ContractAudit;
use App\Models\ContractHistoryRecord;
use App\Models\ImportRun;
use App\Models\LegacyRecord;
use App\Services\Legacy\Normalizer;
use App\Support\AmendmentFields;
use Illuminate\Support\Facades\DB;

/** Explicit system maintenance; never attributes legacy authorship to a human user. */
final class ImportHistoricalAmendments
{
    public function run(bool $dryRun = true): array
    {
        if ($dryRun) {
            DB::beginTransaction();
        }
        try {
            return DB::transaction(function () {
                TransactionGuard::requireActive();
                $counts = array_fill_keys(['source', 'imported', 'skipped', 'unmatched', 'invalid', 'conflict', 'contracts', 'normalized_fields'], 0);
                $run = ImportRun::create(['source_hash' => hash('sha256', 'historical-amendments-v1'), 'status' => 'running', 'started_at' => now()]);
                $histories = ContractHistoryRecord::where('kind', 'amendment')->orderBy('contract_id')->orderBy('id')->get(['id', 'contract_id']);
                foreach ($histories->groupBy('contract_id') as $items) {
                    // This command is a system import, with no authenticated actor to impersonate.
                    $contract = Contract::withTrashed()->whereKey($items->first()->contract_id)->lockForUpdate()->first();
                    $numberCounts = ContractHistoryRecord::whereIn('id', $items->pluck('id'))->whereIn('match_status', ['matched', 'manual_confirmed'])
                        ->with('source')->get()->filter(fn ($history) => $history->source?->source_table === 'tb_Aditivos')
                        ->countBy(fn ($history) => trim((string) ($history->source->raw_payload['N_ADT'] ?? '')));
                    $ids = [];
                    foreach ($items as $item) {
                        $counts['source']++;
                        $history = ContractHistoryRecord::whereKey($item->id)->lockForUpdate()->firstOrFail();
                        $source = LegacyRecord::whereKey($history->legacy_record_id)->lockForUpdate()->first();
                        $outcome = null;
                        if (! $contract || $history->contract_id !== $contract->id || ! in_array($history->match_status, ['matched', 'manual_confirmed'], true)) {
                            $outcome = 'unmatched';
                        } elseif (! $source || $source->source_table !== 'tb_Aditivos') {
                            $outcome = 'invalid';
                        }
                        if ($outcome) {
                            $counts[$outcome]++;
                            if ($source) {
                                $this->issue($run, $source, $outcome, 'Contrato', 'Origem ou vínculo exige revisão; histórico preservado.');
                            }

                            continue;
                        }
                        $existing = ContractAmendment::withTrashed()->whereIn('legacy_record_id', LegacyRecord::where('source_table', 'tb_Aditivos')->where('legacy_id', $source->legacy_id)->select('id'))->lockForUpdate()->first();
                        if ($existing) {
                            $same = $existing->legacy_record_id === $source->id && $existing->contract_id === $contract->id;
                            $counts[$same ? 'skipped' : 'conflict']++;
                            if (! $same) {
                                $this->issue($run, $source, 'conflict', 'ID', 'Origem já importada com outra versão ou vínculo; cadastro preservado.');
                            }

                            continue;
                        }
                        $payload = [];
                        foreach (AmendmentFields::MAP as $field => $definition) {
                            $value = $source->raw_payload[$field] ?? null;
                            $payload[$field] = match ($definition['type']) {
                                'date' => Normalizer::date($value),
                                'money' => Normalizer::money($value),
                                default => $value,
                            };
                            if ($value !== null && trim((string) $value) !== '' && $payload[$field] === null) {
                                $counts['normalized_fields']++;
                                $this->issue($run, $source, 'normalization', $field, 'Valor não utilizável na projeção; original completo preservado em legacy_records.');
                            }
                        }
                        $number = trim((string) $payload['N_ADT']);
                        if ($number === '' || mb_strlen($number) > 64 || $payload['Data_ADT'] === null
                            || ($payload['VL_ADT'] !== null && strlen(ltrim(explode('.', $payload['VL_ADT'])[0], '-')) > 14)) {
                            $counts['invalid']++;
                            $this->issue($run, $source, 'invalid', 'N_ADT', 'Número, data ou valor incompatível com o cadastro; origem preservada.');

                            continue;
                        }
                        if (($numberCounts[$number] ?? 0) > 1 || ContractAmendment::withTrashed()->where('contract_id', $contract->id)->where('number', $number)->exists()) {
                            $counts['conflict']++;
                            $this->issue($run, $source, 'conflict', 'N_ADT', 'Número duplicado na origem ou já reservado neste contrato; exige conferência, sem escolha automática.');

                            continue;
                        }
                        $payload['N_ADT'] = $number;
                        $amendment = ContractAmendment::create(['contract_id' => $contract->id, 'legacy_record_id' => $source->id,
                            'number' => $number, 'event_date' => $payload['Data_ADT'], 'amount' => $payload['VL_ADT'],
                            'payload' => $payload, 'status' => 'importado', 'created_by' => null, 'updated_by' => null, 'version' => 1]);
                        $ids[] = ['id' => $amendment->id, 'history_id' => $history->id, 'legacy_record_id' => $source->id];
                        $counts['imported']++;
                    }
                    if ($ids) {
                        $before = $contract->version;
                        $contract->version++;
                        $contract->updated_by = null;
                        $contract->save();
                        ContractAudit::create(['contract_id' => $contract->id, 'actor_id' => null, 'import_run_id' => $run->id,
                            'action' => 'amendments_imported', 'reason' => 'Cópia dos aditivos históricos; valores, vigência e histórico preservados.',
                            'changes' => ['amendments' => ['before' => [], 'after' => $ids], 'version' => ['before' => $before, 'after' => $contract->version]], 'created_at' => now()]);
                        $counts['contracts']++;
                    }
                }
                $run->update(['status' => 'completed', 'source_count' => $counts['source'], 'imported_count' => $counts['imported'],
                    'skipped_count' => $counts['skipped'], 'rejected_count' => $counts['unmatched'] + $counts['invalid'] + $counts['conflict'],
                    'issues_count' => DB::table('import_issues')->where('import_run_id', $run->id)->count(), 'summary' => $counts, 'finished_at' => now()]);
                ActivityEvent::create(['actor_id' => null, 'action' => 'historical_amendments_imported', 'metadata' => ['import_run_id' => $run->id] + $counts, 'created_at' => now()]);

                return $counts;
            }, 3);
        } finally {
            if ($dryRun) {
                DB::rollBack();
            }
        }
    }

    private function issue(ImportRun $run, LegacyRecord $source, string $code, string $field, string $message): void
    {
        DB::table('import_issues')->insert(['import_run_id' => $run->id, 'legacy_record_id' => $source->id,
            'source_table' => 'tb_Aditivos', 'legacy_id' => $source->legacy_id, 'field' => $field,
            'code' => 'amendment_'.$code, 'severity' => 'warning', 'message' => $message]);
    }
}
