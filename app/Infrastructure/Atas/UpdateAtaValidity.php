<?php

namespace App\Infrastructure\Atas;

use App\Domain\Atas\AtaTerm;
use App\Infrastructure\Persistence\TransactionGuard;
use App\Models\ActivityEvent;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/** Explicit CLI maintenance applying the user-defined documentary validity rule. */
final class UpdateAtaValidity
{
    public const RULE = 'ata_date_and_amendment_or_original_period_v1';

    public function __construct(private AtaAmendmentReader $reader) {}

    public function run(string $directory, bool $apply = false): array
    {
        // PDF/OCR work never runs inside the write transaction.
        $documents = $this->reader->read($directory);

        return DB::transaction(function () use ($directory, $documents, $apply) {
            $atas = DB::table('tb_ATA')->orderBy('ID')->lockForUpdate()->get();
            $stored = DB::table('tb_ADITIVOS_ATA')->orderBy('ID')->lockForUpdate()->get()->keyBy('source_path');
            $result = ['apply' => $apply, 'documents' => count($documents), 'new_amendments' => 0, 'linked' => 0,
                'updated_atas' => 0, 'unchanged_atas' => 0, 'ignored_unsigned' => 0, 'pending_documents' => [], 'skipped_atas' => [], 'changes' => []];
            $linked = [];
            $blocked = [];
            $documentChanges = [];
            foreach ($stored as $record) {
                if ((new AtaAmendmentSourcePolicy)->isUnsigned($directory.'/'.$record->source_path)) {
                    $result['ignored_unsigned']++;
                    if ($apply && $record->link_status !== 'nao_assinado') {
                        DB::table('tb_ADITIVOS_ATA')->where('ID', $record->ID)->update(['link_status' => 'nao_assinado', 'updated_at' => now()]);
                        $documentChanges[] = ['id' => $record->ID, 'status' => 'nao_assinado'];
                    }

                    continue;
                }
                if ($record->ID_ATA !== null && $record->link_status === 'vinculado') {
                    $linked[$record->ID_ATA][$record->source_path] = (array) $record;
                }
            }
            foreach ($documents as $document) {
                $candidates = $atas->filter(fn ($ata) => $this->reader->key($ata->ATA) === $document['ATA']);
                $matching = $candidates->filter(fn ($ata) => $ata->deleted_at === null
                    && $ata->PROCESSO === $document['PROCESSO_ORIGEM']
                    && $this->reader->key($ata->N_LICIT) === $document['N_LICIT']
                    && ($document['CNPJ_FORNECEDOR'] === null || $this->digits($ata->CNPJ_FORNECEDOR) === $this->digits($document['CNPJ_FORNECEDOR'])));
                $target = $matching->count() === 1 && $document['issues'] === [] ? $matching->first() : null;
                $status = $target ? 'vinculado' : ($candidates->isEmpty() ? 'ata_ausente' : 'conferencia_necessaria');
                if ($target === null) {
                    foreach ($candidates as $candidate) {
                        $blocked[$candidate->ID] = true;
                    }
                    $result['pending_documents'][] = ['ata' => $document['ATA'], 'file' => $document['source_path'], 'reason' => $status, 'issues' => $document['issues']];
                } else {
                    $result['linked']++;
                }
                $current = $stored->get($document['source_path']);
                if ($current?->ID_ATA !== null && $target === null) {
                    $blocked[$current->ID_ATA] = true;
                }
                if ($current !== null) {
                    foreach ($document as $column => $value) {
                        $actual = $column === 'issues' ? json_decode($current->issues, true, flags: JSON_THROW_ON_ERROR) : $current->{$column};
                        if ($value === null ? $actual !== null : (is_array($value) ? $value !== $actual : (string) $value !== (string) $actual)) {
                            throw new RuntimeException('Origem do aditivo já registrado foi alterada: '.$document['source_path']);
                        }
                    }
                }
                $id = $current?->ID;
                if ($current === null) {
                    $result['new_amendments']++;
                    if ($apply) {
                        $id = $this->insertAmendment($document, $target?->ID, $status);
                        $documentChanges[] = ['id' => $id, 'status' => $status];
                    }
                } elseif ($current->link_status !== $status || (string) $current->ID_ATA !== (string) ($target?->ID ?? $current->ID_ATA)) {
                    if ($current->ID_ATA !== null && $target !== null && (int) $current->ID_ATA !== (int) $target->ID) {
                        throw new RuntimeException('O vínculo confirmado de um aditivo não pode ser transferido automaticamente.');
                    }
                    if ($apply) {
                        DB::table('tb_ADITIVOS_ATA')->where('ID', $id)->update(['ID_ATA' => $target?->ID ?? $current->ID_ATA, 'link_status' => $status, 'updated_at' => now()]);
                        $documentChanges[] = ['id' => $id, 'status' => $status];
                    }
                }
                if ($target !== null) {
                    $linked[$target->ID][$document['source_path']] = $document + ['ID' => $id];
                }
            }
            foreach ($atas as $ata) {
                if ($ata->deleted_at !== null) {
                    continue;
                }
                if (isset($blocked[$ata->ID])) {
                    $result['skipped_atas'][] = ['ata' => $ata->ATA, 'reason' => 'aditivo_com_vinculo_ou_leitura_pendente'];

                    continue;
                }
                try {
                    $basis = $this->basis($ata, array_values($linked[$ata->ID] ?? []));
                } catch (InvalidArgumentException $error) {
                    $result['skipped_atas'][] = ['ata' => $ata->ATA, 'reason' => $error->getMessage()];

                    continue;
                }
                $beforeBasis = $ata->validity_basis === null ? null : json_decode($ata->validity_basis, true, flags: JSON_THROW_ON_ERROR);
                if ($beforeBasis !== null && ($ata->INICIO_ATA !== ($beforeBasis['start'] ?? null) || $ata->FINAL_ATA !== ($beforeBasis['end'] ?? null))) {
                    throw new RuntimeException('Datas da ata '.$ata->ATA.' foram alteradas após a última atualização. Requer conferência.');
                }
                $issues = array_values(array_filter(json_decode($ata->quality_issues, true, flags: JSON_THROW_ON_ERROR), fn ($issue) => $issue !== 'vigencia_pendente_publicacao_pncp'));
                $basis['applied_version'] = (int) $ata->version;
                if ($ata->INICIO_ATA === $basis['start'] && $ata->FINAL_ATA === $basis['end'] && $beforeBasis == $basis && json_decode($ata->quality_issues, true) === $issues) {
                    $result['unchanged_atas']++;

                    continue;
                }
                $change = ['id' => $ata->ID, 'ata' => $ata->ATA,
                    'before' => ['start' => $ata->INICIO_ATA, 'end' => $ata->FINAL_ATA, 'version' => (int) $ata->version],
                    'after' => ['start' => $basis['start'], 'end' => $basis['end'], 'version' => (int) $ata->version + 1],
                    'source' => $basis['amendments'] === [] ? 'periodo_original' : 'aditivo'];
                $result['changes'][] = $change;
                $result['updated_atas']++;
                if ($apply) {
                    TransactionGuard::requireActive();
                    $basis['applied_version']++;
                    DB::table('tb_ATA')->where('ID', $ata->ID)->update(['INICIO_ATA' => $basis['start'], 'FINAL_ATA' => $basis['end'],
                        'quality_issues' => $this->json($issues), 'validity_basis' => $this->json($basis),
                        'version' => $basis['applied_version'], 'updated_at' => now()]);
                }
            }
            if ($apply && ($result['changes'] !== [] || $documentChanges !== [])) {
                ActivityEvent::create(['actor_id' => null, 'action' => 'ata_validity_updated',
                    'metadata' => ['source' => 'authorized_cli', 'rule' => self::RULE, 'ata_changes' => $result['changes'],
                        'amendments' => $documentChanges, 'pending_count' => count($result['pending_documents'])], 'created_at' => now()]);
            }

            return $result;
        }, 3);
    }

    private function basis(object $ata, array $amendments): array
    {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $ata->DATA_ATA ?? '');
        if (! $start || $start->format('Y-m-d') !== $ata->DATA_ATA) {
            throw new InvalidArgumentException('data_da_ata_ausente_ou_invalida');
        }
        $sources = [];
        if ($amendments === []) {
            $end = AtaTerm::end($start, $ata->PERIODO_ATA ?? '')->format('Y-m-d');
        } else {
            usort($amendments, fn ($a, $b) => [(int) $a['NUMERO'], $a['source_path']] <=> [(int) $b['NUMERO'], $b['source_path']]);
            $last = end($amendments);
            $sameOrdinal = array_filter($amendments, fn ($item) => (int) $item['NUMERO'] === (int) $last['NUMERO']);
            if (count(array_unique(array_column($sameOrdinal, 'FINAL_ADITIVO'))) !== 1) {
                throw new InvalidArgumentException('aditivos_do_mesmo_numero_com_finais_divergentes');
            }
            $end = $last['FINAL_ADITIVO'];
            foreach ($amendments as $amendment) {
                $sources[] = ['id' => $amendment['ID'], 'sha256' => $amendment['source_sha256'],
                    'number' => (int) $amendment['NUMERO'], 'end' => $amendment['FINAL_ADITIVO']];
            }
        }
        if ($end === null || $end < $start->format('Y-m-d')) {
            throw new InvalidArgumentException('termino_do_aditivo_anterior_a_data_da_ata');
        }

        return ['rule' => self::RULE, 'data_ata' => $ata->DATA_ATA, 'period' => $ata->PERIODO_ATA,
            'start' => $start->format('Y-m-d'), 'end' => $end, 'amendments' => $sources];
    }

    private function insertAmendment(array $document, ?int $ataId, string $status): int
    {
        TransactionGuard::requireActive();

        return DB::table('tb_ADITIVOS_ATA')->insertGetId([...$document, 'issues' => $this->json($document['issues']),
            'ID_ATA' => $ataId, 'link_status' => $status, 'created_at' => now(), 'updated_at' => now()], 'ID');
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function digits(?string $text): string
    {
        return preg_replace('/\D/', '', $text ?? '');
    }
}
