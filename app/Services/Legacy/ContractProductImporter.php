<?php

namespace App\Services\Legacy;

use App\Models\ActivityEvent;
use App\Models\Contract;
use App\Models\ContractAudit;
use App\Models\ContractProduct;
use App\Models\ImportRun;
use App\Models\LegacyRecord;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ContractProductImporter
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
                foreach ((new SqlDumpReader)->rows($path, ['tb_Produtos_Contratos']) as $row) {
                    $counts['source']++;
                    $raw = $row['data'];
                    $id = (string) ($raw['ID'] ?? '');
                    if (! ctype_digit($id) || (int) $id < 1) {
                        throw new RuntimeException('Identificador de produto inválido; importação revertida.');
                    }
                    $hash = hash('sha256', json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                    $record = LegacyRecord::firstOrCreate(['source_table' => 'tb_Produtos_Contratos', 'legacy_id' => $id, 'source_hash' => $hash],
                        ['raw_payload' => $raw, 'import_run_id' => $run->id]);
                    $existing = ContractProduct::withTrashed()->whereIn('legacy_record_id', LegacyRecord::where('source_table', 'tb_Produtos_Contratos')->where('legacy_id', $id)->select('id'))->lockForUpdate()->first();
                    if ($existing) {
                        $outcome = $existing->legacy_record_id === $record->id ? 'skipped' : 'conflict';
                    } else {
                        $supplier = Normalizer::document($raw['CNPJ'] ?? '');
                        $fund = Normalizer::name($raw['FUNDO'] ?? '');
                        $process = trim($raw['Processo'] ?? '');
                        $number = trim($raw['Contrato'] ?? '');
                        $matches = Contract::where('numero', $number)->orderBy('id')->lockForUpdate()->get()->filter(fn ($contract) => $number !== '' && $process !== '' && $fund !== '' && Normalizer::validDocument($supplier)
                            && Normalizer::document($contract->fornecedor_documento_snapshot) === $supplier
                            && Normalizer::name($contract->fundo_nome_snapshot) === $fund && trim($contract->processo ?? '') === $process);
                        $data = $this->product($raw);
                        $outcome = $matches->count() > 1 ? 'ambiguous' : ($matches->isEmpty() ? 'unmatched' : ($data === null ? 'invalid' : 'imported'));
                        if ($outcome === 'imported') {
                            $contract = $matches->first();
                            $product = ContractProduct::create($data + ['contract_id' => $contract->id, 'legacy_record_id' => $record->id,
                                'product_id' => null, 'process_number' => $process, 'supplier_document' => $supplier]);
                            $touched[$contract->id][] = $product->id;
                        }
                    }
                    $counts[$outcome]++;
                    if (! in_array($outcome, ['imported', 'skipped'], true)) {
                        DB::table('import_issues')->insert(['import_run_id' => $run->id, 'legacy_record_id' => $record->id,
                            'source_table' => 'tb_Produtos_Contratos', 'legacy_id' => $id, 'field' => 'Contrato', 'code' => 'contract_product_'.$outcome,
                            'severity' => 'warning', 'message' => 'Produto preservado na origem; exige revisão antes de importar.']);
                    }
                }
                if ($counts['source'] === 0) {
                    throw new RuntimeException('Nenhum produto de contrato encontrado.');
                }
                foreach ($touched as $contractId => $ids) {
                    $contract = Contract::findOrFail($contractId);
                    $before = $contract->version;
                    $contract->version++;
                    $contract->updated_by = null;
                    $contract->save();
                    ContractAudit::create(['contract_id' => $contractId, 'actor_id' => null, 'import_run_id' => $run->id,
                        'action' => 'products_imported', 'reason' => 'Importação histórica de produtos; valores e saldos preservados.',
                        'changes' => ['product_ids' => ['before' => [], 'after' => $ids], 'version' => ['before' => $before, 'after' => $contract->version]], 'created_at' => now()]);
                }
                $run->update(['status' => 'completed', 'source_count' => $counts['source'], 'imported_count' => $counts['imported'],
                    'skipped_count' => $counts['skipped'], 'rejected_count' => array_sum($counts) - $counts['source'] - $counts['imported'] - $counts['skipped'],
                    'issues_count' => DB::table('import_issues')->where('import_run_id', $run->id)->count(), 'summary' => $counts, 'finished_at' => now()]);
                ActivityEvent::create(['actor_id' => null, 'action' => 'contract_products_imported', 'metadata' => ['import_run_id' => $run->id] + $counts, 'created_at' => now()]);

                return $counts;
            }, 3);
        } finally {
            if ($dryRun) {
                DB::rollBack();
            }
        }
    }

    private function product(array $raw): ?array
    {
        $data = ['item' => trim($raw['Item'] ?? ''), 'description' => trim($raw['Discriminacao'] ?? ''), 'unit' => trim($raw['UND'] ?? ''),
            'code' => trim($raw['Cod_Prod'] ?? ''), 'brand' => trim($raw['Marca'] ?? '')];
        foreach (['quantity' => 'QTD', 'unit_price' => 'VL_UNIT', 'total' => 'TOTAL_UNIT'] as $field => $source) {
            $value = Normalizer::money($raw[$source] ?? null);
            if ($value === null || BigDecimal::of($value)->isNegative() || ($field === 'quantity' && BigDecimal::of($value)->isZero())) {
                return null;
            }
            $data[$field] = $value;
        }
        foreach (['item' => 100, 'unit' => 40, 'code' => 100, 'brand' => 255, 'description' => 5000] as $field => $limit) {
            if (mb_strlen($data[$field]) > $limit || (in_array($field, ['unit', 'description'], true) && $data[$field] === '')) {
                return null;
            }
        }
        if (mb_strlen(trim($raw['Ficha'] ?? '')) > 64) {
            return null;
        }
        foreach (['CNPJ', 'Contrato', 'Processo', 'FUNDO', 'Ficha', 'Cod_Prod', 'Descricao', 'Discriminacao', 'Marca', 'UND', 'QTD', 'VL_UNIT', 'TOTAL_UNIT'] as $field) {
            $data[$field] = $raw[$field] ?? null;
        }

        return $data;
    }
}
