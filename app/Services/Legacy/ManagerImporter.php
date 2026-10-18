<?php

namespace App\Services\Legacy;

use App\Models\ImportRun;
use App\Models\LegacyRecord;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ManagerImporter
{
    public function import(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Arquivo de referência indisponível.');
        }

        return DB::transaction(function () use ($path) {
            $run = ImportRun::create(['source_hash' => hash_file('sha256', $path), 'status' => 'running', 'started_at' => now()]);
            $counts = ['source_count' => 0, 'imported_count' => 0, 'skipped_count' => 0, 'rejected_count' => 0];
            foreach ((new SqlDumpReader)->rows($path, ['tb_Gestor']) as $row) {
                $raw = $row['data'];
                $counts['source_count']++;
                $id = (string) ($raw['ID_Gestor'] ?? '');
                $name = trim($raw['Nome_Gestor'] ?? '');
                $fundKey = Normalizer::name($raw['Fundo'] ?? '');
                if (! ctype_digit($id) || (int) $id < 1 || (int) $id > 4294967295 || $name === '' || strlen(Normalizer::name($name)) > 255 || $fundKey === '' || strlen($fundKey) > 255) {
                    throw new RuntimeException('Cadastro de gestor inválido. Nenhuma alteração foi aplicada.');
                }
                $json = json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                $record = LegacyRecord::firstOrCreate(['source_table' => 'tb_Gestor', 'legacy_id' => $id, 'source_hash' => hash('sha256', $json)],
                    ['raw_payload' => $raw, 'import_run_id' => $run->id]);
                $existing = DB::table('tb_Gestor')->where('legacy_id', (int) $id)->lockForUpdate()->first();
                if ($existing) {
                    $unchanged = json_decode($existing->raw_payload, true) === $raw;
                    $record->update(['status' => $unchanged ? 'imported' : 'conflict']);
                    $counts[$unchanged ? 'skipped_count' : 'rejected_count']++;

                    continue;
                }
                $document = Normalizer::document($raw['CPF_Gestor'] ?? '');
                DB::table('tb_Gestor')->insert(['legacy_id' => (int) $id, 'name' => $name, 'name_key' => Normalizer::name($name),
                    'document' => $raw['CPF_Gestor'] ?? null, 'document_key' => strlen($document) <= 32 ? ($document ?: null) : null,
                    'fund_name_key' => $fundKey, 'raw_payload' => $json, 'created_at' => now(), 'updated_at' => now()]);
                $record->update(['status' => 'imported']);
                $counts['imported_count']++;
            }
            if ($counts['source_count'] === 0) {
                throw new RuntimeException('Nenhum registro de tb_Gestor encontrado.');
            }
            $run->update($counts + ['status' => 'completed', 'finished_at' => now(), 'summary' => ['catalog' => 'tb_Gestor']]);

            return $counts;
        }, 3);
    }
}
