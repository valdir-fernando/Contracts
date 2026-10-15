<?php

namespace App\Services\Legacy;

use App\Models\ImportRun;
use App\Models\LegacyRecord;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class InspectorImporter
{
    public function import(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Arquivo de referência indisponível.');
        }

        return DB::transaction(function () use ($path) {
            $run = ImportRun::create(['source_hash' => hash_file('sha256', $path), 'status' => 'running', 'started_at' => now()]);
            $counts = ['source_count' => 0, 'imported_count' => 0, 'skipped_count' => 0, 'rejected_count' => 0];
            foreach ((new SqlDumpReader)->rows($path, ['tb_Fiscal']) as $row) {
                $raw = $row['data'];
                $counts['source_count']++;
                $id = (string) ($raw['ID_Fiscal'] ?? '');
                $name = trim($raw['Nome_Fiscal'] ?? '');
                $fundKey = Normalizer::name($raw['Fundo'] ?? '');
                $departmentKey = Normalizer::name($raw['Secretaria'] ?? '');
                if (! ctype_digit($id) || (int) $id < 1 || (int) $id > 4294967295 || $fundKey === ''
                    || strlen(Normalizer::name($name)) > 255 || strlen($fundKey) > 255 || strlen($departmentKey) > 255) {
                    throw new RuntimeException('Cadastro de fiscal inválido. Nenhuma alteração foi aplicada.');
                }
                $json = json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                $record = LegacyRecord::firstOrCreate(['source_table' => 'tb_Fiscal', 'legacy_id' => $id, 'source_hash' => hash('sha256', $json)],
                    ['raw_payload' => $raw, 'import_run_id' => $run->id]);
                if ($name === '') {
                    $record->update(['status' => 'rejected']);
                    $counts['rejected_count']++;

                    continue;
                }
                $existing = DB::table('contract_inspectors')->where('legacy_id', (int) $id)->lockForUpdate()->first();
                if ($existing) {
                    $unchanged = json_decode($existing->raw_payload, true) === $raw;
                    $record->update(['status' => $unchanged ? 'imported' : 'conflict']);
                    $counts[$unchanged ? 'skipped_count' : 'rejected_count']++;

                    continue;
                }
                DB::table('contract_inspectors')->insert(['legacy_id' => (int) $id, 'name' => $name, 'name_key' => Normalizer::name($name),
                    'fund_name_key' => $fundKey, 'department_name_key' => $departmentKey, 'raw_payload' => $json,
                    'created_at' => now(), 'updated_at' => now()]);
                $record->update(['status' => 'imported']);
                $counts['imported_count']++;
            }
            if ($counts['source_count'] === 0) {
                throw new RuntimeException('Nenhum registro de tb_Fiscal encontrado.');
            }
            $run->update($counts + ['status' => 'completed', 'finished_at' => now(), 'summary' => ['catalog' => 'tb_Fiscal']]);

            return $counts;
        }, 3);
    }
}
