<?php

namespace App\Services\Legacy;

use App\Models\ActivityEvent;
use App\Models\Contract;
use App\Models\ContractAudit;
use App\Models\ContractServiceOrder;
use App\Models\ImportRun;
use App\Models\LegacyRecord;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ServiceOrderImporter
{
    public function import(string $path, bool $dryRun = false): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Arquivo de referência indisponível.');
        }
        if ($dryRun) {
            DB::beginTransaction();
        }
        try {
            return DB::transaction(function () use ($path) {
                $run = ImportRun::create(['source_hash' => hash_file('sha256', $path), 'status' => 'running', 'started_at' => now()]);
                $counts = array_fill_keys(['source', 'imported', 'skipped', 'conflict', 'unmatched', 'ambiguous', 'invalid'], 0);
                $touched = [];
                foreach ((new SqlDumpReader)->rows($path, ['tb_OS']) as $row) {
                    $counts['source']++;
                    $raw = $row['data'];
                    $id = (string) ($raw['ID_OS'] ?? '');
                    if (! ctype_digit($id) || (int) $id < 1) {
                        throw new RuntimeException('Identificador de OS inválido; importação revertida.');
                    }
                    $hash = hash('sha256', json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                    $record = LegacyRecord::firstOrCreate(['source_table' => 'tb_OS', 'legacy_id' => $id, 'source_hash' => $hash],
                        ['raw_payload' => $raw, 'import_run_id' => $run->id]);
                    $existing = ContractServiceOrder::withTrashed()->whereIn('legacy_record_id', LegacyRecord::where('source_table', 'tb_OS')->where('legacy_id', $id)->select('id'))->lockForUpdate()->first();
                    if ($existing) {
                        $outcome = $existing->legacy_record_id === $record->id ? 'skipped' : 'conflict';
                    } else {
                        $number = trim($raw['Contrato'] ?? '');
                        $fund = Normalizer::name($raw['Fundo'] ?? '');
                        $process = trim($raw['Processo'] ?? '');
                        $matches = Contract::where('Contrato', $number)->orderBy('id')->lockForUpdate()->get()
                            ->filter(fn ($contract) => $number !== '' && $fund !== '' && $process !== ''
                                && Normalizer::name($contract->fundo_nome_snapshot) === $fund && trim($contract->processo ?? '') === $process);
                        $data = $this->order($raw);
                        $outcome = $matches->count() > 1 ? 'ambiguous' : ($matches->isEmpty() ? 'unmatched' : ($data === null ? 'invalid' : 'imported'));
                        if ($outcome === 'imported') {
                            $contract = $matches->first();
                            // Native and imported OS may share numbers. Never overwrite or silently duplicate a native record.
                            if (ContractServiceOrder::withTrashed()->where('contract_id', $contract->id)->where('number', $data['number'])->whereDate('issued_on', $data['issued_on'])->exists()) {
                                $outcome = 'conflict';
                            } else {
                                $order = ContractServiceOrder::create($data + ['contract_id' => $contract->id, 'legacy_record_id' => $record->id, 'created_by' => null]);
                                $touched[$contract->id][] = $order->id;
                            }
                        }
                    }
                    $counts[$outcome]++;
                    if (! in_array($outcome, ['imported', 'skipped'], true)) {
                        DB::table('import_issues')->insert(['import_run_id' => $run->id, 'legacy_record_id' => $record->id,
                            'source_table' => 'tb_OS', 'legacy_id' => $id, 'field' => 'Contrato', 'code' => 'service_order_'.$outcome,
                            'severity' => 'warning', 'message' => 'OS preservada na origem; exige revisão antes de importar.']);
                    }
                }
                if ($counts['source'] === 0) {
                    throw new RuntimeException('Nenhuma OS encontrada na origem.');
                }
                foreach ($touched as $contractId => $ids) {
                    $contract = Contract::findOrFail($contractId);
                    $before = $contract->version;
                    $contract->version++;
                    $contract->updated_by = null;
                    $contract->save();
                    ContractAudit::create(['contract_id' => $contractId, 'actor_id' => null, 'import_run_id' => $run->id,
                        'action' => 'service_orders_imported', 'reason' => 'Importação histórica de OS; prazos, valores e vigência do contrato preservados.',
                        'changes' => ['service_order_ids' => ['before' => [], 'after' => $ids], 'version' => ['before' => $before, 'after' => $contract->version]], 'created_at' => now()]);
                }
                $run->update(['status' => 'completed', 'source_count' => $counts['source'], 'imported_count' => $counts['imported'],
                    'skipped_count' => $counts['skipped'], 'rejected_count' => $counts['conflict'] + $counts['unmatched'] + $counts['ambiguous'] + $counts['invalid'],
                    'issues_count' => DB::table('import_issues')->where('import_run_id', $run->id)->count(), 'summary' => $counts, 'finished_at' => now()]);
                ActivityEvent::create(['actor_id' => null, 'action' => 'service_orders_imported', 'metadata' => ['import_run_id' => $run->id] + $counts, 'created_at' => now()]);

                return $counts;
            }, 3);
        } finally {
            if ($dryRun) {
                DB::rollBack();
            }
        }
    }

    private function order(array $raw): ?array
    {
        $data = ['number' => trim($raw['N_OS'] ?? ''), 'deadline' => trim($raw['Periodo'] ?? '')];
        if ($data['number'] === '' || $data['deadline'] === '' || mb_strlen($data['number']) > 64 || mb_strlen($data['deadline']) > 255) {
            return null;
        }
        foreach (['issued_on' => 'Data_OS', 'starts_on' => 'Data_Inicio', 'ends_on' => 'Data_Final'] as $field => $source) {
            $value = $raw[$source] ?? '';
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if (! $date || $date->format('Y-m-d') !== $value || $value < '1000-01-01') {
                return null;
            }
            $data[$field] = $value;
        }
        if ($data['ends_on'] < $data['starts_on']) {
            return null;
        }

        return $data;
    }
}
