<?php

namespace App\Infrastructure\Dfd;

use App\Models\ActivityEvent;
use App\Models\ImportRun;
use App\Models\LegacyRecord;
use App\Services\Legacy\Normalizer;
use App\Services\Legacy\SqlDumpReader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DfdLegacyImport
{
    public function __construct(private DfdNumbering $numbering) {}

    public function run(string $path, bool $apply, bool $catalogOnly = false): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Arquivo de referência indisponível.');
        }
        $tables = $catalogOnly ? ['tb_Produtos_Prodata'] : ['tb_Produtos_Prodata', 'tb_N_DFD', 'tb_DFD', 'tb_Produtos_DFD'];
        $rows = array_fill_keys($tables, []);
        foreach ((new SqlDumpReader)->rows($path, $tables) as $row) {
            $raw = $row['data'];
            if (! ctype_digit((string) ($raw['ID'] ?? '')) || (int) $raw['ID'] < 1) {
                throw new RuntimeException('Identificador legado inválido.');
            }
            $rows[$row['table']][] = $raw;
        }
        if (! $apply) {
            return ['mode' => 'dry-run', 'source_count' => array_map('count', $rows)];
        }

        return Cache::lock('contracts-dfd-legacy-import', 120)->block(5, fn () => DB::transaction(function () use ($path, $rows, $tables, $catalogOnly) {
            $funds = DB::table('contract_funds')->whereNull('deleted_at')->orderBy('id')->lockForUpdate()->get();
            $departments = DB::table('contract_departments')->get(['id', 'name']);
            $run = ImportRun::create(['source_hash' => hash_file('sha256', $path), 'status' => 'running', 'started_at' => now()]);
            $counts = ['source_count' => 0, 'imported_count' => 0, 'skipped_count' => 0, 'rejected_count' => 0];
            foreach ($tables as $table) {
                foreach ($rows[$table] as $raw) {
                    $counts['source_count']++;
                    $json = json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                    $origin = LegacyRecord::firstOrCreate(['source_table' => $table, 'legacy_id' => (string) $raw['ID'], 'source_hash' => hash('sha256', $json)],
                        ['raw_payload' => $raw, 'import_run_id' => $run->id]);
                    if ($table === 'tb_N_DFD') {
                        if ($origin->status === 'imported') {
                            $counts['skipped_count']++;

                            continue;
                        }
                        $previous = LegacyRecord::where('source_table', $table)->where('legacy_id', (string) $raw['ID'])->where('status', 'imported')->where('id', '!=', $origin->id)->exists();
                        $valid = ctype_digit((string) ($raw['N_DFD'] ?? '')) && (int) $raw['N_DFD'] <= 2147483647;
                        $year = $raw['ANO'] ?? null;
                        if ($previous || ! $valid || ($year !== null && (! ctype_digit((string) $year) || (int) $year < 1000 || (int) $year > 9999))) {
                            $origin->update(['status' => 'conflict']);
                            $counts['rejected_count']++;

                            continue;
                        }
                        if ($year === null) {
                            // Preserve a source counter without assigning an unsupported year.
                            DB::table($table)->insert(['ANO' => null, 'N_DFD' => (int) $raw['N_DFD']]);
                        } else {
                            $this->numbering->reserveAtLeast((int) $year, (int) $raw['N_DFD']);
                        }
                        $origin->update(['status' => 'imported']);
                        $counts['imported_count']++;

                        continue;
                    }
                    $existing = DB::table($table)->where($table === 'tb_Produtos_Prodata' ? 'ID' : 'legacy_id', $raw['ID'])->lockForUpdate()->first();
                    if ($existing) {
                        $previous = LegacyRecord::where('source_table', $table)->where('legacy_id', (string) $raw['ID'])->where('status', 'imported')->where('id', '!=', $origin->id)->exists();
                        $same = $origin->status === 'imported';
                        if ($table === 'tb_Produtos_Prodata') {
                            $same = collect(['ID_Prodata', 'Classe', 'Descricao', 'UND', 'Inativo'])->every(fn ($field) => (string) $existing->$field === (string) $raw[$field]);
                        }
                        $origin->update(['status' => $same && ! $previous ? 'imported' : 'conflict']);
                        $counts[$same && ! $previous ? 'skipped_count' : 'rejected_count']++;

                        continue;
                    }
                    if ($table === 'tb_Produtos_Prodata') {
                        $patch = array_intersect_key($raw, array_flip(['ID', 'ID_Prodata', 'Classe', 'Descricao', 'UND', 'Inativo']));
                    } elseif ($table === 'tb_DFD') {
                        $matches = $funds->filter(fn ($fund) => Normalizer::name($fund->name) === Normalizer::name($raw['Fundo'] ?? ''));
                        if ($matches->count() !== 1) {
                            $origin->update(['status' => 'conflict']);
                            $counts['rejected_count']++;

                            continue;
                        }
                        $patch = array_intersect_key($raw, array_flip([...DfdRegistration::TEXT_FIELDS, 'N_DFD', 'Fundo', 'Requisitante', 'Categoria', 'Ficha']));
                        $patch['Data_DFD'] = Normalizer::date($raw['Data_DFD'] ?? null);
                        $patch['Data_Conclusao'] = Normalizer::date($raw['Data_Conclusao'] ?? null);
                        $patch += ['legacy_id' => (int) $raw['ID'], 'fund_id' => $matches->first()->id, 'version' => 1, 'created_at' => now(), 'updated_at' => now()];
                        if (Normalizer::name($matches->first()->name) === 'MUNICIPIO DE NIQUELANDIA') {
                            $areas = $departments->filter(fn ($department) => Normalizer::name($department->name) === Normalizer::name($raw['Requisitante'] ?? ''));
                            if ($areas->count() === 1) {
                                $patch['department_id'] = $areas->first()->id;
                            }
                        }
                        if (DB::table('tb_DFD')->where('N_DFD', $raw['N_DFD'])->exists()) {
                            $origin->update(['status' => 'conflict']);
                            $counts['rejected_count']++;

                            continue;
                        }
                    } else {
                        $dfd = DB::table('tb_DFD')->where('legacy_id', $raw['ID_DFD'])->first();
                        if (! $dfd || $dfd->N_DFD !== $raw['N_DFD']) {
                            $origin->update(['status' => 'conflict']);
                            $counts['rejected_count']++;

                            continue;
                        }
                        $patch = array_intersect_key($raw, array_flip(['ID_DFD', 'N_DFD', 'ID_PROD', 'COD_PROD', 'ITEM', 'PROD_SIMPLES', 'CARACT', 'PRODUTO', 'UND', 'QTD', 'VL_UNIT', 'VL_TOTAL']));
                        $patch['ID_DFD'] = $dfd->ID;
                        $patch += ['legacy_id' => (int) $raw['ID'], 'created_at' => now(), 'updated_at' => now()];
                    }
                    DB::table($table)->insert($patch);
                    $origin->update(['status' => 'imported']);
                    $counts['imported_count']++;
                }
            }
            $annualCounters = $catalogOnly ? [] : $this->numbering->synchronizeHistory();
            $run->update($counts + ['status' => 'completed', 'finished_at' => now(), 'summary' => ['module' => 'dfd', 'annual_counters' => $annualCounters]]);
            ActivityEvent::create(['actor_id' => null, 'action' => 'dfd_legacy_imported', 'metadata' => ['import_run_id' => $run->id, 'reason' => 'Importação explícita de DFDs, produtos, contadores e catálogo Prodata', 'counts' => $counts, 'annual_counters' => $annualCounters], 'created_at' => now()]);

            return $counts;
        }, 3));
    }
}
