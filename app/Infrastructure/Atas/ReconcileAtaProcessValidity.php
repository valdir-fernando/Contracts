<?php

namespace App\Infrastructure\Atas;

use App\Domain\Atas\AtaTerm;
use App\Infrastructure\Persistence\TransactionGuard;
use App\Models\ActivityEvent;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** System maintenance grounded exclusively in the original ata copies. */
final class ReconcileAtaProcessValidity
{
    public function __construct(private AtaProcessEvidenceReader $reader, private AtaNumbering $numbering) {}

    public function run(string $directory, bool $apply = false): array
    {
        $prepared = $this->reader->read($directory);
        if ($apply && $prepared['pending'] !== []) {
            throw new RuntimeException('Há processos com leitura pendente; nenhum registro foi alterado. Execute a simulação para conferir.');
        }

        return DB::transaction(function () use ($prepared, $apply) {
            $result = ['apply' => $apply, 'processes' => count($prepared['entries']) + count($prepared['pending']),
                'inserted' => 0, 'updated' => 0, 'confirmed' => 0, 'preserved_signed_extension' => 0,
                'pending' => $prepared['pending'], 'atas' => []];
            $entries = $prepared['entries'];
            $funds = [];
            $documents = array_unique(array_column($entries, 'CNPJ_FUNDO'));
            sort($documents);
            foreach ($documents as $document) {
                $ids = DB::table('contract_funds')->where('document_key', preg_replace('/\D/', '', $document))->whereNull('deleted_at')->orderBy('id')->lockForUpdate()->pluck('id');
                $funds[$document] = $ids->count() === 1 ? $ids->first() : null;
            }
            $counterChanges = [];
            if ($apply) {
                $maxima = [];
                foreach ($entries as $entry) {
                    $number = $this->numbering->parse($entry['ATA']);
                    $maxima[$number['ANO']] = max($maxima[$number['ANO']] ?? 0, $number['NUMERO']);
                }
                ksort($maxima);
                foreach ($maxima as $year => $number) {
                    if (($change = $this->numbering->reserveAtLeast($year, $number)) !== null) {
                        $counterChanges[] = $change;
                    }
                }
            }
            $atas = DB::table('tb_ATA')->orderBy('ID')->lockForUpdate()->get();
            $amendments = DB::table('tb_ADITIVOS_ATA')->where('link_status', 'vinculado')->orderBy('ID')->lockForUpdate()->get();
            $identity = new AtaAmendmentReader;
            foreach ($entries as $entry) {
                $source = $entry['source']['file'];
                if (! str_starts_with($source, '/') && ! preg_match('/^[A-Za-z]:[\\\\\/]/', $source)) {
                    $source = base_path($source);
                }
                if (! is_file($source) || ! hash_equals($entry['source']['sha256'], hash_file('sha256', $source))) {
                    throw new RuntimeException('Origem do processo alterada após a leitura: '.$entry['ATA']);
                }
                $candidates = $atas->filter(fn ($ata) => $identity->key($ata->ATA) === $entry['ATA']);
                if ($candidates->count() > 1) {
                    throw new RuntimeException('Identificação ambígua da ata '.$entry['ATA']);
                }
                $ata = $candidates->first();
                $row = ['ata' => $entry['ATA'], 'id' => $ata?->ID, 'date' => $entry['DATA_ATA'], 'period' => $entry['PERIODO_ATA'],
                    'original_end' => $entry['FINAL_ATA'], 'source' => $entry['source']];
                if ($ata === null) {
                    if ($entry['FORNECEDOR'] === null || $entry['CNPJ_FORNECEDOR'] === null || $entry['FUNDO'] === null) {
                        throw new RuntimeException('Cadastro original incompleto na cópia da ata '.$entry['ATA']);
                    }
                    $row['status'] = 'original_cadastrada';
                    $result['inserted']++;
                    if ($apply) {
                        $row['id'] = $this->insertOriginal($entry, $funds[$entry['CNPJ_FUNDO']]);
                    }
                } else {
                    if ($ata->deleted_at !== null || $ata->PROCESSO !== $entry['PROCESSO'] || $identity->key($ata->N_LICIT) !== $entry['N_LICIT']
                        || $ata->CNPJ_FUNDO !== $entry['CNPJ_FUNDO']
                        || $entry['CNPJ_FORNECEDOR'] !== null && $ata->CNPJ_FORNECEDOR !== null && $entry['CNPJ_FORNECEDOR'] !== $ata->CNPJ_FORNECEDOR) {
                        throw new RuntimeException('A cópia do processo diverge do cadastro da ata '.$entry['ATA']);
                    }
                    $signed = $amendments->where('ID_ATA', $ata->ID)->filter(fn ($item) => ! (new AtaAmendmentSourcePolicy)->isUnsigned(base_path('Reference/ATAS/ADITIVOS_ATAS/'.$item->source_path)));
                    if ($signed->isNotEmpty()) {
                        $row['status'] = 'aditivo_assinado_preservado';
                        $row['current_end'] = $ata->FINAL_ATA;
                        $result['preserved_signed_extension']++;
                    } elseif ($ata->DATA_ATA === $entry['DATA_ATA'] && $ata->INICIO_ATA === $entry['DATA_ATA'] && $ata->FINAL_ATA === $entry['FINAL_ATA']) {
                        $row['status'] = 'vigencia_original_confirmada';
                        $result['confirmed']++;
                    } else {
                        $beforeBasis = $ata->validity_basis === null ? null : json_decode($ata->validity_basis, true, flags: JSON_THROW_ON_ERROR);
                        if ($beforeBasis !== null && ($ata->INICIO_ATA !== $beforeBasis['start'] || $ata->FINAL_ATA !== $beforeBasis['end'])) {
                            throw new RuntimeException('Vigência editada após manutenção anterior; conferir ata '.$entry['ATA']);
                        }
                        $period = $ata->PERIODO_ATA ?? $entry['PERIODO_ATA'];
                        if (AtaTerm::end(new DateTimeImmutable($entry['DATA_ATA']), $period)->format('Y-m-d') !== $entry['FINAL_ATA']) {
                            throw new RuntimeException('Duração cadastrada diverge da cópia original: '.$entry['ATA']);
                        }
                        $row['status'] = 'vigencia_original_atualizada';
                        $row['before'] = ['date' => $ata->DATA_ATA, 'start' => $ata->INICIO_ATA, 'end' => $ata->FINAL_ATA, 'version' => $ata->version];
                        $result['updated']++;
                        if ($apply) {
                            TransactionGuard::requireActive();
                            DB::table('tb_ATA')->where('ID', $ata->ID)->update(['DATA_ATA' => $entry['DATA_ATA'], 'INICIO_ATA' => $entry['DATA_ATA'], 'FINAL_ATA' => $entry['FINAL_ATA'],
                                'PERIODO_ATA' => $period, 'version' => $ata->version + 1,
                                'validity_basis' => $this->json($this->basis($entry, $period, $ata->version + 1)), 'updated_at' => now()]);
                        }
                    }
                }
                $result['atas'][] = $row;
            }
            if ($apply && ($result['inserted'] > 0 || $result['updated'] > 0 || $counterChanges !== [])) {
                ActivityEvent::create(['actor_id' => null, 'action' => 'ata_original_processes_reconciled',
                    'metadata' => ['source' => 'authorized_cli', 'rule' => UpdateAtaValidity::RULE, 'atas' => $result['atas'], 'annual_counters' => $counterChanges], 'created_at' => now()]);
            }

            return $result;
        }, 3);
    }

    private function insertOriginal(array $entry, ?int $fundId): int
    {
        TransactionGuard::requireActive();
        $data = array_intersect_key($entry, array_flip(['ATA', 'DATA_ATA', 'PROCESSO', 'N_LICIT', 'PERIODO_ATA', 'FORNECEDOR', 'CNPJ_FORNECEDOR', 'FUNDO', 'CNPJ_FUNDO', 'OBJETO DA ATA']));
        $issues = ['origem_copia_ata_em_processo', 'produtos_e_participantes_nao_importados_do_processo'];
        if ($fundId === null) {
            $issues[] = 'fundo_sem_vinculo_univoco:'.$entry['CNPJ_FUNDO'];
        }
        $id = DB::table('tb_ATA')->insertGetId([...$data, 'LICITACAO' => 'PREGÃO ELETRÔNICO', 'INICIO_ATA' => $entry['DATA_ATA'], 'FINAL_ATA' => $entry['FINAL_ATA'],
            'fund_id' => $fundId, 'version' => 1, 'source_files' => $this->json([$entry['source']]), 'import_hash' => hash('sha256', $this->json($entry)),
            'quality_issues' => $this->json($issues),
            'validity_basis' => $this->json($this->basis($entry, $entry['PERIODO_ATA'], 1)), 'created_at' => now(), 'updated_at' => now()], 'ID');
        DB::table('tb_FUNDOS_ATA')->insert(['ID_ATA' => $id, 'ATA' => $entry['ATA'], 'CNPJ_FUNDO' => $entry['CNPJ_FUNDO'], 'FUNDO' => $entry['FUNDO'], 'papel' => 'gerenciador',
            'fund_id' => $fundId, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function basis(array $entry, string $period, int $version): array
    {
        return ['rule' => UpdateAtaValidity::RULE, 'data_ata' => $entry['DATA_ATA'], 'period' => $period,
            'start' => $entry['DATA_ATA'], 'end' => $entry['FINAL_ATA'], 'amendments' => [], 'applied_version' => $version];
    }

    private function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
