<?php

namespace App\Services\Legacy;

use App\Models\ActivityEvent;
use App\Models\ImportRun;
use App\Models\LegacyRecord;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class FichaValuesImporter
{
    public function import(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Arquivo de referência indisponível.');
        }

        return DB::transaction(function () use ($path) {
            $run = ImportRun::create(['source_hash' => hash_file('sha256', $path), 'status' => 'running', 'started_at' => now()]);
            $counts = ['source_count' => 0, 'imported_count' => 0, 'skipped_count' => 0, 'rejected_count' => 0];
            $fields = ['Fundo', 'Contrato', 'Ficha', 'Valor', 'CPF_CNPJ', 'Proc_Cont', 'Proc_ADT', 'N_ADT'];
            foreach ((new SqlDumpReader)->rows($path, ['tb_ValorFicha']) as $row) {
                $raw = $row['data'];
                $id = (string) ($raw['ID_Ficha'] ?? '');
                if (! ctype_digit($id) || (int) $id < 1 || (int) $id > 4294967295) {
                    throw new RuntimeException('Identificador de valor por ficha inválido.');
                }
                $counts['source_count']++;
                $data = [];
                foreach ($fields as $field) {
                    $data[$field] = (string) ($raw[$field] ?? '');
                }
                if (trim($data['N_ADT']) === '') {
                    $data['N_ADT'] = null;
                }
                $record = LegacyRecord::firstOrCreate(['source_table' => 'tb_ValorFicha', 'legacy_id' => $id,
                    'source_hash' => hash('sha256', json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))],
                    ['raw_payload' => $raw, 'import_run_id' => $run->id]);
                $existing = DB::table('tb_ValorFicha')->where('ID_Ficha', $id)->lockForUpdate()->first();
                if ($existing) {
                    $same = true;
                    foreach ($fields as $field) {
                        $same = $same && (string) ($existing->$field ?? '') === (string) $data[$field];
                    }
                    $record->update(['status' => $same ? 'imported' : 'conflict']);
                    $counts[$same ? 'skipped_count' : 'rejected_count']++;
                } else {
                    DB::table('tb_ValorFicha')->insert(['ID_Ficha' => (int) $id] + $data);
                    $record->update(['status' => 'imported']);
                    $counts['imported_count']++;
                }
            }
            if ($counts['source_count'] === 0) {
                throw new RuntimeException('Nenhum valor por ficha encontrado.');
            }
            $run->update($counts + ['status' => 'completed', 'finished_at' => now(), 'summary' => ['catalog' => 'tb_ValorFicha']]);
            ActivityEvent::create(['actor_id' => null, 'action' => 'ficha_values_catalog_imported', 'metadata' => ['import_run_id' => $run->id] + $counts, 'created_at' => now()]);

            return $counts;
        }, 3);
    }
}
