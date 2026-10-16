<?php

namespace App\Services\Legacy;

use App\Models\ActivityEvent;
use App\Models\ImportRun;
use App\Models\LegacyRecord;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ProductImporter
{
    public function import(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Arquivo de referência indisponível.');
        }

        return DB::transaction(function () use ($path) {
            $run = ImportRun::create(['source_hash' => hash_file('sha256', $path), 'status' => 'running', 'started_at' => now()]);
            $counts = ['source_count' => 0, 'imported_count' => 0, 'skipped_count' => 0, 'rejected_count' => 0];
            $fields = ['Processo', 'Cod_Prod', 'CNPJ_Fornec', 'Discriminacao', 'Marca', 'Unidade', 'QTD', 'QTD_Acumulado', 'QTD_Autorizado', 'VL_Unitario', 'VL_Total'];
            foreach ((new SqlDumpReader)->rows($path, ['tb_Produtos', 'tb_produtos']) as $row) {
                $raw = $row['data'];
                $id = (string) ($raw['ID_Produto'] ?? '');
                if (! ctype_digit($id) || (int) $id < 1 || (int) $id > 4294967295) {
                    throw new RuntimeException('Identificador de produto inválido.');
                }
                $counts['source_count']++;
                $data = ['item' => (string) ($raw['Item'] ?? $raw['item'] ?? '')];
                foreach ($fields as $field) {
                    $data[$field] = $raw[$field] ?? null;
                }
                $record = LegacyRecord::firstOrCreate(['source_table' => 'tb_Produtos', 'legacy_id' => $id,
                    'source_hash' => hash('sha256', json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))],
                    ['raw_payload' => $raw, 'import_run_id' => $run->id]);
                $existing = DB::table('tb_Produtos')->where('ID_Produto', $id)->lockForUpdate()->first();
                if ($existing) {
                    $same = true;
                    foreach ($data as $field => $value) {
                        $same = $same && $value === $existing->$field;
                    }
                    $record->update(['status' => $same ? 'imported' : 'conflict']);
                    $counts[$same ? 'skipped_count' : 'rejected_count']++;
                } else {
                    DB::table('tb_Produtos')->insert(['ID_Produto' => (int) $id] + $data);
                    $record->update(['status' => 'imported']);
                    $counts['imported_count']++;
                }
            }
            if ($counts['source_count'] === 0) {
                throw new RuntimeException('Nenhum produto encontrado.');
            }
            $run->update($counts + ['status' => 'completed', 'finished_at' => now(), 'summary' => ['catalog' => 'tb_Produtos']]);
            ActivityEvent::create(['actor_id' => null, 'action' => 'product_catalog_imported', 'metadata' => ['import_run_id' => $run->id] + $counts, 'created_at' => now()]);

            return $counts;
        }, 3);
    }
}
