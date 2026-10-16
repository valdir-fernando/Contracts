<?php

namespace App\Services\Legacy;

use App\Models\ActivityEvent;
use App\Models\ImportRun;
use App\Models\LegacyRecord;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class BudgetImporter
{
    public function import(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Arquivo de referência indisponível.');
        }

        return DB::transaction(function () use ($path) {
            $run = ImportRun::create(['source_hash' => hash_file('sha256', $path), 'status' => 'running', 'started_at' => now()]);
            $counts = ['source_count' => 0, 'imported_count' => 0, 'skipped_count' => 0, 'rejected_count' => 0];
            $fields = ['Orgao', 'Gestao', 'Unidade', 'Funcao', 'SubFunc', 'Programa', 'Projeto', 'Fonte', 'Codigo', 'Ficha', 'Descricao', 'Dotacao', 'Fundo', 'Secretaria'];
            foreach ((new SqlDumpReader)->rows($path, ['tb_Dotacao']) as $row) {
                $raw = $row['data'];
                $id = (string) ($raw['ID_Dotacao'] ?? '');
                if (! ctype_digit($id) || (int) $id < 1 || (int) $id > 4294967295) {
                    throw new RuntimeException('Identificador de dotação inválido.');
                }
                $counts['source_count']++;
                $data = [];
                foreach ($fields as $field) {
                    $data[$field] = (string) ($raw[$field] ?? '');
                }
                $record = LegacyRecord::firstOrCreate(['source_table' => 'tb_Dotacao', 'legacy_id' => $id,
                    'source_hash' => hash('sha256', json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))],
                    ['raw_payload' => $raw, 'import_run_id' => $run->id]);
                $existing = DB::table('tb_Dotacao')->where('ID_Dotacao', $id)->lockForUpdate()->first();
                if ($existing) {
                    $same = true;
                    foreach ($fields as $field) {
                        $same = $same && (string) ($existing->$field ?? '') === $data[$field];
                    }
                    $record->update(['status' => $same ? 'imported' : 'conflict']);
                    $counts[$same ? 'skipped_count' : 'rejected_count']++;
                } else {
                    DB::table('tb_Dotacao')->insert(['ID_Dotacao' => (int) $id] + $data);
                    $record->update(['status' => 'imported']);
                    $counts['imported_count']++;
                }
            }
            if ($counts['source_count'] === 0) {
                throw new RuntimeException('Nenhuma dotação encontrada.');
            }
            $run->update($counts + ['status' => 'completed', 'finished_at' => now(), 'summary' => ['catalog' => 'tb_Dotacao']]);
            ActivityEvent::create(['actor_id' => null, 'action' => 'budget_catalog_imported', 'metadata' => ['import_run_id' => $run->id] + $counts, 'created_at' => now()]);

            return $counts;
        }, 3);
    }
}
