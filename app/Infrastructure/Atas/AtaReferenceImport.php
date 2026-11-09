<?php

namespace App\Infrastructure\Atas;

use App\Domain\Shared\DecimalAmount;
use App\Infrastructure\Persistence\TransactionGuard;
use App\Models\ActivityEvent;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/** Explicit system CLI import; never accepts actor/abilities from HTTP. */
final class AtaReferenceImport
{
    public function __construct(private AtaSourceReader $reader, private AtaDocumentParser $parser, private AtaNumbering $numbering) {}

    public function run(string $directory, bool $apply = false): array
    {
        $sourceRoot = is_file($directory.'/BD_ATAS.xlsx') ? $directory : dirname($directory);
        $this->checkWorkbook($sourceRoot.'/BD_ATAS.xlsx');
        $groups = [];
        foreach (glob($directory.'/PE_*/*') as $path) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (! in_array($extension, ['docx', 'pdf'], true)) {
                continue;
            }
            if (! preg_match('/_(?:ATA_)?(\d+)-(\d{2})_PE_(\d+)-(\d{2})_/u', basename($path), $match)) {
                throw new RuntimeException('Nome de documento não reconhecido: '.basename($path));
            }
            $key = basename(dirname($path)).'/'.$match[1].'/20'.$match[2];
            if (isset($groups[$key][$extension])) {
                if ($extension !== 'pdf') {
                    throw new RuntimeException('Mais de um '.$extension.' para a mesma ata: '.$key);
                }
                $extension = 'pdf_'.count($groups[$key]);
            }
            $groups[$key][$extension] = $path;
        }
        if ($groups === []) {
            throw new RuntimeException('Nenhum documento encontrado nas pastas PE_*.');
        }
        ksort($groups);
        $entries = [];
        foreach ($groups as $key => $files) {
            try {
                $entry = $this->readEntry($files, $sourceRoot);
                if (! str_ends_with($key, '/'.$entry['ata']['ATA'])) {
                    throw new RuntimeException('Número da ata diverge do nome do arquivo.');
                }
                preg_match('/_PE_(\d+)-(\d{2})_/', basename(reset($files)), $procurement);
                if ($entry['ata']['N_LICIT'] !== $procurement[1].'/20'.$procurement[2]) {
                    throw new RuntimeException('Número do pregão diverge do nome do arquivo.');
                }
                $entries[] = $entry;
            } catch (\Throwable $error) {
                throw new RuntimeException($key.': '.$error->getMessage(), 0, $error);
            }
        }

        // All extraction/PDF processing finishes before acquiring database locks.
        return DB::transaction(function () use ($entries, $apply) {
            $result = ['apply' => $apply, 'checked' => count($entries), 'inserted' => 0, 'existing' => 0,
                'products' => 0, 'fund_links' => 0, 'issues' => [], 'by_procurement' => []];
            $created = [];
            $counterChanges = [];
            // Resolve and lock destination funds before counters and documentary records.
            $entries = array_map(fn ($entry) => $this->resolveFunds($entry), $entries);
            if ($apply) {
                $maxima = [];
                foreach ($entries as $entry) {
                    $number = $this->numbering->parse($entry['ata']['ATA']);
                    $maxima[$number['ANO']] = max($maxima[$number['ANO']] ?? 0, $number['NUMERO']);
                }
                ksort($maxima);
                // Lock years in ascending order before locking atas, just like new allocations.
                foreach ($maxima as $year => $last) {
                    $change = $this->numbering->reserveAtLeast($year, $last);
                    if ($change !== null) {
                        $counterChanges[] = $change;
                    }
                }
            }
            foreach ($entries as $entry) {
                $data = $entry['ata'];
                $identity = ['CNPJ_FUNDO' => $data['CNPJ_FUNDO'], 'PROCESSO' => $data['PROCESSO'], 'ATA' => $data['ATA']];
                $current = DB::table('tb_ATA')->where($identity)->lockForUpdate()->first();
                if ($current !== null) {
                    if ($current->deleted_at !== null || ! hash_equals($current->import_hash, $this->hash($entry)) || ! $this->unchanged($current, $entry)) {
                        throw new RuntimeException('Conflito na ata '.$data['ATA'].': origem ou dados existentes divergentes. Nada será sobrescrito.');
                    }
                    $result['existing']++;
                } elseif ($apply) {
                    $created[] = $this->insert($entry);
                    $result['inserted']++;
                }
                $result['products'] += count($entry['products']);
                $result['fund_links'] += count($entry['funds']);
                $label = $data['N_LICIT'];
                $result['by_procurement'][$label] ??= ['atas' => 0, 'products' => 0, 'total' => '0.0000'];
                $result['by_procurement'][$label]['atas']++;
                $result['by_procurement'][$label]['products'] += count($entry['products']);
                foreach ($entry['products'] as $product) {
                    $result['by_procurement'][$label]['total'] = bcadd($result['by_procurement'][$label]['total'], $product['VL_TOTAL'], 4);
                }
                if ($entry['issues'] !== []) {
                    $result['issues'][$data['ATA']] = $entry['issues'];
                }
            }
            if ($created !== [] || $counterChanges !== []) {
                ActivityEvent::create(['actor_id' => null, 'action' => 'atas_reference_imported',
                    'metadata' => ['source' => 'authorized_cli', 'ata_ids' => $created,
                        'products' => $result['products'], 'fund_links' => $result['fund_links'], 'annual_counters' => $counterChanges], 'created_at' => now()]);
            }

            return $result;
        }, 3);
    }

    private function readEntry(array $files, string $sourceRoot): array
    {
        $hashes = array_map(fn ($path) => hash_file('sha256', $path), $files);
        $document = isset($files['docx']) ? $this->reader->docx($files['docx']) : null;
        $pdf = isset($files['pdf']) ? $this->reader->pdf($files['pdf']) : null;
        $entry = $this->parser->parse($document['text'] ?? $pdf, $document['tables'] ?? []);
        foreach (array_filter($files, fn ($path) => strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf') as $pdfPath) {
            if ($document === null) {
                continue;
            }
            $pdf = $this->reader->pdf($pdfPath);
            $pdfEntry = $this->parser->parse($pdf, $document['tables']);
            foreach ($entry['ata'] as $column => $value) {
                $pdfValue = $pdfEntry['ata'][$column];
                if (in_array($column, ['END_FORNECEDOR', 'END_FUNDO'], true)) {
                    $value = preg_replace('/-\s+/u', '-', $value ?? '');
                    $pdfValue = preg_replace('/-\s+/u', '-', $pdfValue ?? '');
                }
                if (in_array($column, ['RG_SOCIO', 'RG_GESTOR', 'TELEFONE1', 'TELEFONE2'], true)) {
                    $value = preg_replace('/\s+/u', '', $value ?? '');
                    $pdfValue = preg_replace('/\s+/u', '', $pdfValue ?? '');
                }
                if ($value !== $pdfValue) {
                    throw new RuntimeException('DOCX e PDF divergem no campo '.$column.'. Requer conferência.');
                }
            }
            if ($entry['funds'] !== $pdfEntry['funds']) {
                throw new RuntimeException('DOCX e PDF divergem nos participantes.');
            }
            $this->verifyPdfAmounts($pdfPath, $entry['products']);
        }
        $entry['sources'] = [];
        ksort($files);
        foreach ($files as $extension => $path) {
            $hash = hash_file('sha256', $path);
            if (! hash_equals($hashes[$extension], $hash)) {
                throw new RuntimeException('Documento alterado durante a leitura. Execute novamente.');
            }
            $entry['sources'][] = ['file' => str_replace('\\', '/', substr($path, strlen(rtrim($sourceRoot, '/\\')) + 1)),
                'format' => strtolower(pathinfo($path, PATHINFO_EXTENSION)), 'sha256' => $hash, 'size' => filesize($path)];
        }

        return $entry;
    }

    private function verifyPdfAmounts(string $path, array $products): void
    {
        $expected = [];
        foreach ($products as $product) {
            foreach (['QTD', 'VL_UNIT', 'VL_TOTAL'] as $column) {
                $value = $product[$column];
                $expected[$value] = ($expected[$value] ?? 0) + 1;
            }
        }
        $actual = [];
        foreach ($this->reader->pdfTableNumbers($path) as $raw) {
            if (preg_match('/^\d{1,3}(?:\.\d{3})+$/D', $raw)) {
                $raw = str_replace('.', '', $raw);
            }
            $value = DecimalAmount::normalize($raw);
            $actual[$value] = ($actual[$value] ?? 0) + 1;
        }
        ksort($expected);
        ksort($actual);
        if ($expected !== $actual) {
            throw new RuntimeException('Quantidades/preços/totais do PDF não conferem com as linhas do Word.');
        }
    }

    private function resolveFunds(array $entry): array
    {
        foreach ($entry['funds'] as &$fund) {
            $ids = DB::table('contract_funds')->where('document_key', preg_replace('/\D/', '', $fund['CNPJ_FUNDO']))
                ->whereNull('deleted_at')->orderBy('id')->lockForUpdate()->pluck('id');
            $fund['fund_id'] = $ids->count() === 1 ? $ids->first() : null;
            if ($fund['fund_id'] === null) {
                $entry['issues'][] = 'fundo_sem_vinculo_univoco:'.$fund['CNPJ_FUNDO'];
            }
        }
        unset($fund);
        $entry['ata']['fund_id'] = $entry['funds'][0]['fund_id'];

        return $entry;
    }

    private function insert(array $entry): int
    {
        TransactionGuard::requireActive();
        $timestamp = now();
        $id = DB::table('tb_ATA')->insertGetId([...$entry['ata'],
            'source_files' => $this->json($entry['sources']), 'quality_issues' => $this->json($entry['issues']),
            'import_hash' => $this->hash($entry), 'created_at' => $timestamp, 'updated_at' => $timestamp], 'ID');
        foreach ($entry['funds'] as $fund) {
            DB::table('tb_FUNDOS_ATA')->insert([...$fund, 'ID_ATA' => $id, 'created_at' => $timestamp, 'updated_at' => $timestamp]);
        }
        foreach ($entry['products'] as $product) {
            DB::table('tb_PRODUTOS_ATA')->insert([...$product, 'source_row' => $this->json($product['source_row']),
                'ID_ATA' => $id, ...array_intersect_key($entry['ata'], array_flip(['ATA', 'PROCESSO', 'LICITACAO', 'N_LICIT'])),
                'created_at' => $timestamp, 'updated_at' => $timestamp]);
        }

        return $id;
    }

    private function unchanged(object $current, array $entry): bool
    {
        $basis = $current->validity_basis === null ? null : json_decode($current->validity_basis, true, flags: JSON_THROW_ON_ERROR);
        $derivedValidity = $basis !== null && ($basis['rule'] ?? null) === UpdateAtaValidity::RULE
            && ($basis['data_ata'] ?? null) === $entry['ata']['DATA_ATA']
            && ($basis['period'] ?? null) === $entry['ata']['PERIODO_ATA']
            && ($basis['start'] ?? null) === $current->INICIO_ATA && ($basis['end'] ?? null) === $current->FINAL_ATA
            && (int) ($basis['applied_version'] ?? 0) === (int) $current->version;
        foreach ($entry['ata'] as $column => $value) {
            if ($derivedValidity && in_array($column, ['INICIO_ATA', 'FINAL_ATA'], true)) {
                continue;
            }
            if ($value === null ? $current->{$column} !== null : (string) $current->{$column} !== (string) $value) {
                return false;
            }
        }
        $sortSourceKeys = static function (array $source): array {
            ksort($source);

            return $source;
        };
        // MySQL normalizes JSON object key order; compare typed content, not key positions.
        $sources = array_map($sortSourceKeys, json_decode($current->source_files, true, flags: JSON_THROW_ON_ERROR));
        $expectedSources = array_map($sortSourceKeys, $entry['sources']);
        $issues = $derivedValidity ? array_values(array_filter($entry['issues'], fn ($issue) => $issue !== 'vigencia_pendente_publicacao_pncp')) : $entry['issues'];
        if ((! $derivedValidity && (int) $current->version !== 1) || $sources !== $expectedSources || json_decode($current->quality_issues, true) !== $issues) {
            return false;
        }
        foreach (['funds' => 'tb_FUNDOS_ATA', 'products' => 'tb_PRODUTOS_ATA'] as $key => $table) {
            $rows = DB::table($table)->where('ID_ATA', $current->ID)->orderBy('ID')->lockForUpdate()->get();
            if ($key === 'products') {
                $rows = $rows->filter(fn ($row) => $row->ID_ADITIVO === null && (int) $row->ADITIVO === 0)->values();
            }
            if ($rows->count() !== count($entry[$key])) {
                return false;
            }
            foreach ($entry[$key] as $index => $expected) {
                $row = $rows[$index];
                if ($row->deleted_at !== null) {
                    return false;
                }
                foreach ($expected as $column => $value) {
                    $actual = $column === 'source_row' ? json_decode($row->{$column}, true) : $row->{$column};
                    if (in_array($column, ['QTD', 'VL_UNIT', 'VL_TOTAL'], true)) {
                        $actual = DecimalAmount::normalize((string) $actual);
                    }
                    if ($value === null ? $actual !== null : (is_array($value) ? $actual !== $value : (string) $actual !== (string) $value)) {
                        return false;
                    }
                }
                if ($key === 'products') {
                    foreach (['ATA', 'PROCESSO', 'LICITACAO', 'N_LICIT'] as $column) {
                        if ($row->{$column} !== $entry['ata'][$column]) {
                            return false;
                        }
                    }
                }
            }
        }

        return true;
    }

    private function hash(array $entry): string
    {
        return hash('sha256', $this->json($entry));
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function checkWorkbook(string $path): void
    {
        $workbook = IOFactory::load($path);
        try {
            $schema = [];
            $table = null;
            foreach ($workbook->getActiveSheet()->toArray() as $row) {
                if (str_starts_with((string) ($row[0] ?? ''), 'tb_')) {
                    $table = $row[0];
                    $schema[$table] = [];
                } elseif ($table !== null && ($row[1] ?? null) !== null) {
                    $schema[$table][] = $row[1];
                }
            }
            $expected = [
                'tb_ATA' => ['ATA', 'DATA_ATA', 'PROCESSO', 'LICITACAO', 'N_LICIT', 'FORNECEDOR', 'END_FORNECEDOR', 'EMAIL', 'TELEFONE1', 'TELEFONE2', 'SOCIO', 'RG_SOCIO', 'CPF_SOCIO', 'CNPJ_FUNDO', 'FUNDO', 'END_FUNDO', 'GESTOR', 'CPF_GESTOR', 'RG_GESTOR', 'DECRETO_GESTOR', 'MATRICULA_GESTOR', 'OBJETO DA ATA', 'INICIO_ATA', 'FINAL_ATA', 'PERIODO_ATA'],
                'tb_FUNDOS_ATA' => ['CNPJ_FUNDO', 'FUNDO'],
                'tb_PRODUTOS_ATA' => ['ATA', 'PROCESSO', 'LICITACAO', 'N_LICIT', 'ITEM', 'DISCRIMINACAO', 'MARCA', 'UND', 'QTD', 'VL_UNIT', 'VL_TOTAL'],
            ];
            if ($schema !== $expected) {
                throw new RuntimeException('Estrutura de BD_ATAS.xlsx diverge do mapeamento suportado.');
            }
        } finally {
            $workbook->disconnectWorksheets();
        }
    }
}
