<?php

namespace App\Infrastructure\Atas;

use App\Infrastructure\Persistence\TransactionGuard;
use App\Models\ActivityEvent;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** One-time, resumable conversion. The original import table is a frozen archive. */
final class ConsolidateAtaAmendments
{
    public function run(): array
    {
        return DB::transaction(function () {
            TransactionGuard::requireActive();
            $identity = new AtaAmendmentReader;
            $atas = DB::table('tb_ATA')->orderBy('ID')->lockForUpdate()->get();
            $recordColumns = ['ID', 'ID_ATA', 'imported_id', 'ATA', 'ADT', 'Proc_ATA', 'Proc_ADT', 'INICIO', 'FINAL', 'deleted_at'];
            $records = $this->amendments()->orderBy('ID')->lockForUpdate()->get($recordColumns);
            $imports = DB::table('tb_ADITIVOS_ATA')->orderBy('ID')->lockForUpdate()->get();
            $mapping = [];
            $changes = [];
            $linkedRecords = [];
            $linkedProducts = 0;
            $updatedBases = [];
            foreach ($imports as $import) {
                $existing = $records->firstWhere('imported_id', $import->ID);
                if ($existing === null) {
                    $candidates = $records->filter(fn ($row) => $identity->key($row->ATA) !== null
                        && $identity->key($row->ATA) === $identity->key($import->ATA)
                        && trim($row->Proc_ATA ?? '') === trim($import->PROCESSO_ORIGEM ?? '')
                        && (int) $row->ADT === (int) $import->NUMERO
                        && ($row->deleted_at === null) === ($import->deleted_at === null));
                    if ($candidates->count() > 1) {
                        throw new RuntimeException('Aditivo ambíguo na consolidação: importação '.$import->ID);
                    }
                    $existing = $candidates->first();
                    if ($existing?->imported_id !== null) {
                        throw new RuntimeException('Mais de uma origem para o mesmo aditivo: '.$import->ID);
                    }
                    $data = AtaAmendmentImportData::canonical((array) $import);
                    unset($data['ID']);
                    $data['imported_id'] = $import->ID;
                    $data['import_snapshot'] = json_encode((array) $import, JSON_THROW_ON_ERROR);
                    if ($existing !== null) {
                        foreach (['ATA', 'ADT', 'Proc_ATA', 'Proc_ADT', 'INICIO', 'FINAL', 'deleted_at'] as $field) {
                            $actual = $existing->{$field};
                            $incoming = $data[$field];
                            $same = $field === 'ATA' ? $identity->key($actual) === $identity->key($incoming)
                                : ($field === 'ADT' ? (int) $actual === (int) $incoming : trim((string) $actual) === trim((string) $incoming));
                            if ($actual !== null && $incoming !== null && ! $same) {
                                throw new RuntimeException('Campos divergentes na consolidação: importação '.$import->ID.' / '.$field);
                            }
                            if ($actual !== null) {
                                unset($data[$field]);
                            }
                        }
                        if ($existing->ID_ATA !== null && $import->ID_ATA !== null && (int) $existing->ID_ATA !== (int) $import->ID_ATA) {
                            throw new RuntimeException('Vínculo divergente na consolidação: '.$import->ID);
                        }
                        DB::table('tb_ADT_ATA')->where('ID', $existing->ID)->update($data);
                        $id = (int) $existing->ID;
                    } else {
                        // Unlinked documents can have no number/ATA; retain their original nulls in the snapshot.
                        $data['ATA'] ??= '';
                        $data['ADT'] ??= 0;
                        $id = DB::table('tb_ADT_ATA')->insertGetId($data, 'ID');
                    }
                    $changes[] = ['imported_id' => $import->ID, 'amendment_id' => $id];
                    $records = $this->amendments()->orderBy('ID')->get($recordColumns);
                } else {
                    $id = (int) $existing->ID;
                }
                $mapping[(int) $import->ID] = $id;
                if (DB::table('tb_PRODUTOS_ATA')->where('ID_ADITIVO', $import->ID)->whereNotNull('amendment_id')->where('amendment_id', '!=', $id)->exists()) {
                    throw new RuntimeException('Produto com vínculo canônico divergente: '.$import->ID);
                }
                $linkedProducts += DB::table('tb_PRODUTOS_ATA')->where('ID_ADITIVO', $import->ID)->whereNull('amendment_id')->update(['amendment_id' => $id]);
            }
            // Link native records/products too, without changing any existing IDs or amounts.
            foreach ($records as $record) {
                if ($record->ID_ATA !== null || $record->imported_id !== null) {
                    continue;
                }
                $matches = $atas->filter(fn ($ata) => $identity->key($record->ATA) !== null
                    && $identity->key($ata->ATA) === $identity->key($record->ATA)
                    && trim((string) $ata->PROCESSO) === trim($record->Proc_ATA ?? ''));
                if ($matches->count() === 1) {
                    DB::table('tb_ADT_ATA')->where('ID', $record->ID)->update(['ID_ATA' => $matches->first()->ID]);
                    $linkedRecords[] = $record->ID;
                }
            }
            foreach ($this->amendments()->whereNotNull('ID_ATA')->get(['ID', 'ID_ATA', 'ADT', 'deleted_at']) as $record) {
                $siblings = $this->amendments()->where('ID_ATA', $record->ID_ATA)->where('ADT', $record->ADT)
                    ->whereNull('deleted_at')->count();
                if ($record->deleted_at === null && $siblings === 1) {
                    $linkedProducts += DB::table('tb_PRODUTOS_ATA')->where('ID_ATA', $record->ID_ATA)->where('ADITIVO', $record->ADT)
                        ->whereNull('ID_ADITIVO')->whereNull('deleted_at')->whereNull('amendment_id')->update(['amendment_id' => $record->ID]);
                }
            }
            foreach ($atas as $ata) {
                $basis = json_decode($ata->validity_basis ?? 'null', true, flags: JSON_THROW_ON_ERROR);
                $changed = false;
                foreach ($basis['amendments'] ?? [] as $index => $source) {
                    if (isset($source['id']) && ! isset($source['imported_id']) && isset($mapping[$source['id']])) {
                        $basis['amendments'][$index]['imported_id'] = $source['id'];
                        $basis['amendments'][$index]['id'] = $mapping[$source['id']];
                        $changed = true;
                    }
                }
                if ($changed) {
                    DB::table('tb_ATA')->where('ID', $ata->ID)->update(['validity_basis' => json_encode($basis, JSON_THROW_ON_ERROR)]);
                    $updatedBases[] = $ata->ID;
                }
            }
            if ($changes !== [] || $linkedRecords !== [] || $linkedProducts > 0 || $updatedBases !== []) {
                ActivityEvent::create(['actor_id' => null, 'action' => 'ata_amendments_consolidated', 'created_at' => now(),
                    'metadata' => ['source' => 'schema_migration', 'mapping' => $changes, 'linked_records' => $linkedRecords,
                        'linked_products' => $linkedProducts, 'updated_validity_bases' => $updatedBases]]);
            }

            return $mapping;
        });
    }

    private function amendments(): Builder
    {
        return DB::table('tb_ADT_ATA')->whereRaw("COALESCE(TRIM(Tipo_ADT), '') <> ?", [SaveAtaAmendment::APOSTILLE]);
    }
}
