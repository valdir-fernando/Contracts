<?php

namespace App\Services\Legacy;

use App\Domain\Contracts\ContractNumber;
use App\Infrastructure\Contracts\EloquentContractNumbering;
use App\Models\ActivityEvent;
use App\Models\ImportRun;
use App\Models\LegacyRecord;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class ContractCounterImporter
{
    public function import(string $path, int $year, bool $dryRun = false): array
    {
        if ($year < 1000 || $year > 9999 || ! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Informe um exercício válido e um dump SQL acessível.');
        }
        $rows = [];
        foreach ((new SqlDumpReader)->rows($path, ['tb_N_Contrato']) as $source) {
            $raw = $source['data'];
            $key = Normalizer::name($raw['FUNDO'] ?? '');
            $funds = DB::table('contract_funds')->where('name_key', $key)->limit(2)->get(['id']);
            if ($funds->count() !== 1 || ! ctype_digit($raw['ID'] ?? '') || (int) $raw['ID'] < 1) {
                throw new RuntimeException('Contador com identificador inválido ou Fundo sem correspondência única. Nenhuma alteração foi aplicada.');
            }
            $fundId = $funds->first()->id;
            if (isset($rows[$fundId])) {
                throw new RuntimeException('Há mais de um contador para o mesmo Fundo na origem. Nenhuma alteração foi aplicada.');
            }
            $rows[$fundId] = ['raw' => $raw, 'fund_key' => $key, 'number' => ContractNumber::integer(trim($raw['NUMERO'] ?? ''))];
        }
        if (! $rows) {
            throw new RuntimeException('Nenhum registro de tb_N_Contrato encontrado.');
        }
        ksort($rows, SORT_NUMERIC);
        DB::beginTransaction();
        try {
            $run = ImportRun::create(['source_hash' => hash_file('sha256', $path), 'status' => 'running', 'started_at' => now()]);
            $summary = [];
            foreach ($rows as $fundId => $source) {
                $fund = DB::table('contract_funds')->where('id', $fundId)->lockForUpdate()->firstOrFail();
                if ($fund->name_key !== $source['fund_key']) {
                    throw new RuntimeException('O cadastro do Fundo mudou durante a importação. Tente novamente.');
                }
                $row = DB::table('tb_N_Contrato')->where('fund_id', $fundId)->where('ANO', $year)->lockForUpdate()->first();
                $used = EloquentContractNumbering::highestUsed($fundId, $year);
                $previous = $row ? ContractNumber::integer((string) $row->NUMERO) : 0;
                $last = max($previous, $source['number'], $used);
                $raw = $source['raw'];
                $json = json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                $record = LegacyRecord::firstOrCreate(['source_table' => 'tb_N_Contrato', 'legacy_id' => $raw['ID'], 'source_hash' => hash('sha256', $json)],
                    ['raw_payload' => $raw, 'import_run_id' => $run->id]);
                $values = ['FUNDO' => $fund->name, 'NUMERO' => $last, 'legacy_record_id' => $record->id, 'updated_at' => now()];
                if ($row) {
                    DB::table('tb_N_Contrato')->where('ID', $row->ID)->update($values);
                } else {
                    DB::table('tb_N_Contrato')->insert($values + ['fund_id' => $fundId, 'ANO' => $year, 'created_at' => now()]);
                }
                $record->update(['status' => 'imported']);
                $summary[] = ['fund_id' => $fundId, 'year' => $year, 'source_number' => $source['number'],
                    'highest_used' => $used, 'previous_number' => $previous, 'last_number' => $last];
            }
            $run->update(['source_count' => count($rows), 'imported_count' => count($rows), 'status' => $dryRun ? 'simulated' : 'completed',
                'finished_at' => now(), 'summary' => ['catalog' => 'tb_N_Contrato', 'year' => $year, 'counters' => $summary]]);
            ActivityEvent::create(['actor_id' => null, 'action' => 'contract_counters_imported',
                'metadata' => ['import_run_id' => $run->id, 'year' => $year, 'count' => count($rows)], 'created_at' => now()]);
            $dryRun ? DB::rollBack() : DB::commit();

            return $summary;
        } catch (Throwable $error) {
            DB::rollBack();
            throw $error;
        }
    }
}
