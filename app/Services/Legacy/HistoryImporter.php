<?php

namespace App\Services\Legacy;

use App\Models\Contract;
use App\Models\ContractAudit;
use App\Models\ContractHistoryRecord;
use App\Models\ImportRun;
use App\Models\LegacyRecord;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class HistoryImporter
{
    public function import(string $path, bool $dryRun = false): array
    {
        $counts = ['source' => 0, 'imported' => 0, 'skipped' => 0, 'conflict' => 0, 'ambiguous' => 0, 'unmatched' => 0];
        $run = $dryRun ? null : ImportRun::create(['source_hash' => hash_file('sha256', $path), 'status' => 'running', 'started_at' => now()]);
        DB::beginTransaction();
        try {
            $run ??= ImportRun::create(['source_hash' => hash_file('sha256', $path), 'status' => 'running', 'started_at' => now()]);
            foreach ((new SqlDumpReader)->rows($path, ['tb_Aditivos', 'tb_Produtos_Contratos']) as $row) {
                $counts['source']++;
                $raw = $row['data'];
                $kind = $row['table'] === 'tb_Aditivos' ? 'amendment' : 'item';
                $id = $raw['ID'] ?? '';
                if (! ctype_digit($id) || (int) $id < 1) {
                    throw new RuntimeException('Identificador histórico inválido; importação revertida.');
                }
                $hash = hash('sha256', json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                $record = LegacyRecord::firstOrCreate(['source_table' => $row['table'], 'legacy_id' => $id, 'source_hash' => $hash],
                    ['raw_payload' => $raw, 'import_run_id' => $run->id]);
                $existing = ContractHistoryRecord::where('kind', $kind)->where('legacy_id', $id)->first();
                if ($existing) {
                    $same = $existing->source->source_hash === $hash;
                    $counts[$same ? 'skipped' : 'conflict']++;
                    $record->update(['status' => $same ? 'imported' : 'conflict']);
                    if (! $same) {
                        DB::table('import_issues')->insert(['import_run_id' => $run->id, 'legacy_record_id' => $record->id,
                            'source_table' => $row['table'], 'legacy_id' => $id, 'field' => 'ID', 'code' => 'source_changed',
                            'severity' => 'error', 'message' => 'Origem histórica alterada; versão existente preservada.']);
                    }

                    continue;
                }
                $number = trim($raw['Contrato'] ?? '');
                $candidates = $number === '' ? collect() : Contract::withTrashed()->where('Contrato', $number)->orderBy('id')->lockForUpdate()->get();
                $supplier = Normalizer::document($raw[$kind === 'amendment' ? 'CNPJ_Fornec' : 'CNPJ'] ?? '');
                $fund = $raw[$kind === 'amendment' ? 'CNPJ_Fundo' : 'FUNDO'] ?? '';
                $fundDocument = Normalizer::document($fund);
                $fundName = Normalizer::name($fund);
                $process = trim($raw['Processo'] ?? '');
                // A number alone never proves correspondence. Require supplier and fund evidence.
                $enoughEvidence = Normalizer::validDocument($supplier) && trim($fund) !== '' && ($kind === 'amendment' || $process !== '');
                $candidates = $candidates->filter(function ($contract) use ($supplier, $fundDocument, $fundName, $kind, $process) {
                    if (Normalizer::document($contract->fornecedor_documento_snapshot ?? '') !== $supplier) {
                        return false;
                    }
                    $sameFund = $kind === 'amendment'
                        ? Normalizer::validDocument($fundDocument) && Normalizer::document($contract->fundo_documento_snapshot ?? '') === $fundDocument
                        : Normalizer::name($contract->fundo_nome_snapshot ?? '') === $fundName;

                    return $sameFund && ($process === '' || trim($contract->processo ?? '') === $process);
                });
                $ids = $candidates->pluck('id')->values()->all();
                $status = $enoughEvidence && count($ids) === 1 ? 'matched' : (count($ids) > 1 ? 'ambiguous' : 'unmatched');
                if ($status !== 'matched') {
                    $counts[$status]++;
                }
                $issues = [];
                $date = $this->normalize($raw, 'Data_ADT', 'date', $issues);
                $end = $this->normalize($raw, 'Final_Prorrog', 'date', $issues);
                $amount = $this->normalize($raw, $kind === 'amendment' ? 'VL_ADT' : 'TOTAL_UNIT', 'money', $issues);
                $history = ContractHistoryRecord::create(['kind' => $kind, 'legacy_id' => $id, 'legacy_record_id' => $record->id,
                    'contract_id' => $status === 'matched' ? $ids[0] : null, 'match_status' => $status, 'candidate_ids' => $ids,
                    'contract_number' => $number, 'description' => $raw[$kind === 'amendment' ? 'Motivo_ADT' : 'Descricao'] ?? null,
                    'event_date' => $date, 'end_date' => $end, 'amount' => $amount, 'quality_issues' => $issues]);
                $record->update(['status' => $status]);
                if ($kind === 'amendment') {
                    // Matching history does not certify validity or apply any financial change.
                    foreach ($candidates as $contract) {
                        $before = $contract->vigencia_em_revisao;
                        $contract->vigencia_em_revisao = true;
                        $contract->updated_by = null;
                        $contract->version++;
                        $contract->save();
                        ContractAudit::create(['contract_id' => $contract->id, 'actor_id' => null, 'import_run_id' => $run->id,
                            'action' => 'history_imported', 'reason' => 'Importação histórica pelo sistema; execução '.$run->id.'.',
                            'changes' => ['history_id' => ['before' => null, 'after' => $history->id],
                                'match_status' => ['before' => null, 'after' => $status],
                                'vigencia_em_revisao' => ['before' => $before, 'after' => true]], 'created_at' => now()]);
                    }
                }
                if ($status !== 'matched' || $issues) {
                    DB::table('import_issues')->insert(['import_run_id' => $run->id, 'legacy_record_id' => $record->id,
                        'source_table' => $row['table'], 'legacy_id' => $id, 'field' => 'Contrato', 'code' => 'history_review',
                        'severity' => 'warning', 'message' => 'Histórico exige revisão de vínculo ou normalização.']);
                }
                $counts['imported']++;
            }
            $run->update(['status' => $dryRun ? 'simulated' : 'completed', 'source_count' => $counts['source'],
                'imported_count' => $counts['imported'], 'skipped_count' => $counts['skipped'], 'rejected_count' => $counts['conflict'],
                'issues_count' => DB::table('import_issues')->where('import_run_id', $run->id)->count(),
                'summary' => $counts, 'finished_at' => now()]);
            $dryRun ? DB::rollBack() : DB::commit();

            return $counts;
        } catch (Throwable $error) {
            DB::rollBack();
            if (! $dryRun) {
                $run?->update(['status' => 'failed', 'finished_at' => now(), 'summary' => ['error' => 'Histórico revertido integralmente.']]);
            }
            throw new RuntimeException('Importação histórica interrompida; nenhuma alteração aplicada.', 0, $error);
        }
    }

    private function normalize(array $raw, string $field, string $type, array &$issues): ?string
    {
        $value = $raw[$field] ?? null;
        if ($value === null || trim($value) === '') {
            return null;
        }
        $normalized = $type === 'date' ? Normalizer::date($value) : Normalizer::money($value);
        if ($normalized === null) {
            $issues[] = ['field' => $field, 'message' => 'Valor histórico inválido, preservado na origem.'];
        }

        return $normalized;
    }
}
