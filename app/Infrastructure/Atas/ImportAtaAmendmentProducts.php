<?php

namespace App\Infrastructure\Atas;

use App\Domain\Shared\DecimalAmount;
use App\Infrastructure\Persistence\TransactionGuard;
use App\Models\ActivityEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Explicit system maintenance; preserves original products and documentary totals. */
final class ImportAtaAmendmentProducts
{
    public function __construct(private AtaAmendmentProductReader $reader) {}

    public function run(string $directory, bool $apply = false): array
    {
        $prepared = [];
        $pending = [];
        foreach (DB::table('tb_ADITIVOS_ATA')->orderBy('ID')->get() as $record) {
            $metadata = (array) $record;
            try {
                $entry = $this->reader->read($directory, $metadata);
                if ($entry['products'] === [] && $entry['has_item_header']) {
                    throw new RuntimeException('Tabela identificada sem produtos reconhecidos.');
                }
                if ($entry['DATA_ADT'] === null || $entry['PROC_ADT'] === null) {
                    throw new RuntimeException('Data ou processo do aditivo não identificado.');
                }
                $prepared[] = ['metadata' => $metadata, 'entry' => $entry];
            } catch (\Throwable $error) {
                $pending[] = ['aditivo_id' => $record->ID, 'ata' => $record->ATA, 'file' => $record->source_path, 'reason' => $error->getMessage()];
            }
        }

        // All PDF/OCR processing has finished before locks and writes.
        return DB::transaction(function () use ($prepared, $pending, $apply) {
            $result = ['apply' => $apply, 'documents' => count($prepared) + count($pending), 'prepared' => count($prepared),
                'products' => 0, 'total' => '0.0000',
                'inserted_products' => 0, 'existing_products' => 0, 'linked_products' => 0, 'without_original_ata' => 0,
                'without_item_table' => [], 'pending' => $pending, 'issues' => [], 'changes' => []];
            $atas = DB::table('tb_ATA')->orderBy('ID')->lockForUpdate()->get()->keyBy('ID');
            $amendments = DB::table('tb_ADITIVOS_ATA')->orderBy('ID')->lockForUpdate()->get()->keyBy('ID');
            foreach ($prepared as ['metadata' => $before, 'entry' => $entry]) {
                $amendment = $amendments->get($before['ID']);
                foreach (['ID_ATA', 'ATA', 'NUMERO', 'PROCESSO_ORIGEM', 'PROCESSO_ADITIVO', 'N_LICIT', 'source_path', 'source_sha256', 'extraction', 'link_status', 'products_import'] as $column) {
                    if ($amendment->{$column} !== $before[$column]) {
                        throw new RuntimeException('Aditivo alterado durante a preparação: '.$before['ID']);
                    }
                }
                $ata = $amendment->ID_ATA === null ? null : $atas->get($amendment->ID_ATA);
                $identity = new AtaAmendmentReader;
                if ($ata !== null && ($identity->key($ata->ATA) !== $identity->key($amendment->ATA)
                    || $ata->PROCESSO !== $amendment->PROCESSO_ORIGEM || $identity->key($ata->N_LICIT) !== $identity->key($amendment->N_LICIT))) {
                    throw new RuntimeException('A ata vinculada diverge da identificação do aditivo.');
                }
                if ($amendment->link_status === 'conferencia_necessaria' || $ata !== null && $ata->deleted_at !== null) {
                    throw new RuntimeException('Vínculo de aditivo requer conferência: '.$amendment->ID);
                }
                $manifest = ['rule' => 'documentary_amendment_products_v1', 'sha256' => $amendment->source_sha256,
                    'date' => $entry['DATA_ADT'], 'process' => $entry['PROC_ADT'], 'count' => count($entry['products']),
                    'total' => $entry['total'], 'issues' => $entry['issues'], 'items_hash' => hash('sha256', $this->json($entry['products']))];
                if ($amendment->products_import !== null && json_decode($amendment->products_import, true, flags: JSON_THROW_ON_ERROR) != $manifest) {
                    throw new RuntimeException('Conteúdo de produtos já importado diverge: '.$amendment->ID);
                }
                $current = DB::table('tb_PRODUTOS_ATA')->where('ID_ADITIVO', $amendment->ID)->orderBy('ID')->lockForUpdate()->get()->keyBy('ITEM');
                if (array_diff($current->keys()->map(fn ($item) => (string) $item)->all(), array_column($entry['products'], 'ITEM')) !== []) {
                    throw new RuntimeException('Produtos extras encontrados no aditivo '.$amendment->ID);
                }
                $result['products'] += count($entry['products']);
                $result['total'] = bcadd($result['total'], $entry['total'], 4);
                $ids = [];
                $linkedIds = [];
                foreach ($entry['products'] as $product) {
                    $data = [...$product, 'ID_ATA' => $ata?->ID, 'ID_ADITIVO' => $amendment->ID,
                        'ATA' => $amendment->ATA, 'PROCESSO' => $amendment->PROCESSO_ORIGEM,
                        'LICITACAO' => $ata?->LICITACAO ?? 'PREGÃO ELETRÔNICO', 'N_LICIT' => $amendment->N_LICIT,
                        'ADITIVO' => $amendment->NUMERO, 'DATA_ADT' => $entry['DATA_ADT'], 'PROC_ADT' => $entry['PROC_ADT']];
                    if ($ata === null) {
                        $result['without_original_ata']++;
                    }
                    $existing = $current->get($product['ITEM']);
                    if ($existing !== null) {
                        $this->verifyExisting($existing, $data);
                        $result['existing_products']++;
                        if ($existing->ID_ATA === null && $ata !== null) {
                            $result['linked_products']++;
                            if ($apply) {
                                DB::table('tb_PRODUTOS_ATA')->where('ID', $existing->ID)->update(['ID_ATA' => $ata->ID, 'updated_at' => now()]);
                                $linkedIds[] = $existing->ID;
                            }
                        }
                    } elseif ($amendment->products_import !== null) {
                        throw new RuntimeException('Produto removido de aditivo importado: '.$amendment->ID);
                    } elseif ($apply) {
                        TransactionGuard::requireActive();
                        $ids[] = DB::table('tb_PRODUTOS_ATA')->insertGetId([...$data, 'source_row' => $this->json($data['source_row']),
                            'created_at' => now(), 'updated_at' => now()], 'ID');
                        $result['inserted_products']++;
                    }
                }
                if ($entry['products'] === []) {
                    $result['without_item_table'][] = ['aditivo_id' => $amendment->ID, 'ata' => $amendment->ATA, 'reason' => 'sem_produtos_discriminados'];
                }
                if ($entry['issues'] !== []) {
                    $result['issues'][$amendment->ID] = ['ata' => $amendment->ATA, 'issues' => $entry['issues']];
                }
                if ($apply && $amendment->products_import === null) {
                    DB::table('tb_ADITIVOS_ATA')->where('ID', $amendment->ID)->update(['products_import' => $this->json($manifest), 'updated_at' => now()]);
                }
                if ($apply && ($amendment->products_import === null || $linkedIds !== [])) {
                    $result['changes'][] = ['aditivo_id' => $amendment->ID, 'ata_id' => $ata?->ID, 'product_ids' => $ids, 'linked_product_ids' => $linkedIds, 'manifest' => $manifest];
                }
            }
            if ($apply && $result['changes'] !== []) {
                ActivityEvent::create(['actor_id' => null, 'action' => 'ata_amendment_products_imported',
                    'metadata' => ['source' => 'authorized_cli', 'changes' => $result['changes'], 'pending' => count($pending)], 'created_at' => now()]);
            }

            return $result;
        }, 3);
    }

    private function verifyExisting(object $existing, array $data): void
    {
        if ($existing->deleted_at !== null) {
            throw new RuntimeException('Produto do aditivo excluído; histórico preservado.');
        }
        foreach ($data as $column => $value) {
            if ($column === 'ID_ATA' && $existing->ID_ATA === null && $value !== null) {
                continue;
            }
            $actual = $column === 'source_row' ? json_decode($existing->{$column}, true, flags: JSON_THROW_ON_ERROR) : $existing->{$column};
            if (in_array($column, ['QTD', 'VL_UNIT', 'VL_TOTAL'], true)) {
                $actual = DecimalAmount::normalize((string) $actual);
            }
            if (is_array($value) ? $value != $actual : ($value === null ? $actual !== null : (string) $value !== (string) $actual)) {
                throw new RuntimeException('Produto existente divergente no campo '.$column.'; nada será sobrescrito.');
            }
        }
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
