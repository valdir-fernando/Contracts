<?php

namespace App\Infrastructure\Atas;

use App\Domain\Shared\DecimalAmount;
use RuntimeException;

/** Documentary extraction only: no validity, balance or financial effects. */
final class AtaDocumentParser
{
    public function parse(string $text, array $tables = []): array
    {
        // Remove repeating PDF page furniture before joining wrapped lines.
        $text = str_replace("\f", "\n", $text);
        $text = preg_replace('/^\h*(?:MUNICÍPIO DE NIQUELÂNDIA|ADM:\h*\d{4}\/\d{4}|(?:Pregão Eletrônico|ATA Registro Preço)[^\n]*Página[^\n]*)\h*$/mu', '', $text);
        $flat = trim(preg_replace('/\s+/u', ' ', $text));
        $flat = preg_replace('/(\d{2}\.\d{3}\.\d{3}\/\d{4}-)\s+(\d{2})/u', '$1$2', $flat);
        $flat = preg_replace('/(\d{3}\.\d{3}\.\d{3}-)\s+(\d{2})/u', '$1$2', $flat);
        $flat = preg_replace('/(\d{2}\.\d{3}\.\d{3})\.(\d{4}-\d{2})/u', '$1/$2', $flat);
        $flat = preg_replace('/\be-\s+mail:/ui', 'e-mail:', $flat);
        $ata = $this->required('/ATA DE REGISTRO DE PREÇOS N[º°.]?\s*(\d+\/\d{4})/u', $flat);
        $number = $this->required('/PREGÃO ELETRÔNICO\s+(\d+\/\d{4})/u', $flat);
        $process = $this->required('/PROCESSO ADMINISTRATIVO\s+N[.º°]*\s*(\d+)/u', $flat);
        $manager = $this->required('/(?:^|\s)O(?:\(A\))?\s+((?:MUNICIPIO|MUNICÍPIO|FUNDO MUNICIPAL).*?),\s*pessoa jurídica/u', $flat);
        $intro = $this->required('/pessoa jurídica(.*?)considerando o julgamento/u', $flat);
        $supplier = $this->required('/FORNECEDOR:\s*(.*?)(?:\s*\|\s*|\s+\d+\.\d+\s+)?A listagem do cadastro/u', $flat);
        $cnpj = '/\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}/';
        $cpf = '/\d{3}\.\d{3}\.\d{3}-\d{2}/';
        $name = $this->required('/^(.*?),\s*com sede/u', $supplier);
        $fundCnpj = $this->required($cnpj, $intro, 0);
        $issues = ['vigencia_pendente_publicacao_pncp'];
        $date = $this->date($flat);
        if ($date === null) {
            $issues[] = 'data_ata_nao_identificada';
        } elseif (substr($date, 0, 4) !== substr($ata, -4)) {
            $issues[] = 'ano_data_diverge_ano_ata';
        }
        $registration = $this->capture('/para REGISTRO DE PREÇOS\s*n[º°.]?\s*(\d+\/\d{4})/ui', $flat);
        if ($registration !== null && $registration !== $number) {
            $issues[] = 'referencia_registro_no_preambulo:'.$registration;
        }
        $phones = $this->capture('/telefone para contato\s*n[º°.]?\s*(.*?),\s*neste ato/ui', $supplier);
        preg_match_all('/\(\d{2}\)\s*\d(?:[\d\s-]*\d)/u', $phones ?? '', $phoneMatches);
        $data = [
            'ATA' => $ata, 'DATA_ATA' => $date, 'PROCESSO' => $process,
            'LICITACAO' => 'PREGÃO ELETRÔNICO', 'N_LICIT' => $number, 'FORNECEDOR' => $name,
            'CNPJ_FORNECEDOR' => $this->required($cnpj, $supplier, 0),
            'END_FORNECEDOR' => $this->capture('/com sede(?: na)?\s+(.*?),\s*inscrita/ui', $supplier),
            'EMAIL' => $this->capture('/e-mail:\s*([^\s,]+)/ui', $supplier),
            'TELEFONE1' => isset($phoneMatches[0][0]) ? trim($phoneMatches[0][0]) : null,
            'TELEFONE2' => isset($phoneMatches[0][1]) ? trim($phoneMatches[0][1]) : null,
            'SOCIO' => $this->capture('/(?:Sr\(a\)\.|Senhora)\s*(.*?)(?:,\s*(?:residente|portador))/ui', $supplier),
            'RG_SOCIO' => $this->capture('/Carteira de Identidade\s*(?:CI\s*)?n[º°.]?\s*(.*?),\s*CPF/ui', $supplier),
            'CPF_SOCIO' => $this->capture($cpf, $supplier, 0),
            'FUNDO' => $manager, 'CNPJ_FUNDO' => $fundCnpj,
            'END_FUNDO' => $this->capture('/com endereço\s+(?:no\(a\)|na)\s+(.*?),\s*neste ato/u', $intro),
            'GESTOR' => $this->capture('/Senhor(?:\(a\)|a)\s+(.*?),\s*nomeado/u', $intro),
            'CPF_GESTOR' => $this->capture($cpf, $intro, 0),
            'RG_GESTOR' => $this->capture('/(?:\bRG\s+|Carteira de Identidade\s+CI\s+n[º°.]*\s*)(.*?),\s*residente/u', $intro),
            'DECRETO_GESTOR' => $this->capture('/Decreto\s+n[º°.]?\s*(\d+\/\d{4}[^,]*,\s*(?:de\s+)?[^,]+),\s*brasileiro/u', $intro),
            'MATRICULA_GESTOR' => $this->capture('/matrícula funcional\s+n[º°.]?\s*(\d+)/u', $intro),
            'OBJETO DA ATA' => $this->required('/A presente Ata tem por (?:o )?objeto\s+(.*?independentemente de transcrição\.)/u', $flat),
            'INICIO_ATA' => null, 'FINAL_ATA' => null,
            'PERIODO_ATA' => $this->required('/A validade da Ata de Registro de Preços será de\s+(.*?preço vantajoso\.)/u', $flat),
        ];
        foreach (['END_FORNECEDOR', 'EMAIL', 'TELEFONE1', 'SOCIO', 'RG_SOCIO', 'CPF_SOCIO', 'END_FUNDO', 'GESTOR', 'CPF_GESTOR', 'RG_GESTOR', 'DECRETO_GESTOR', 'MATRICULA_GESTOR'] as $column) {
            if ($data[$column] === null) {
                $issues[] = 'campo_nao_informado:'.$column;
            }
        }
        $funds = [['CNPJ_FUNDO' => $fundCnpj, 'FUNDO' => $manager, 'papel' => 'gerenciador']];
        $section = $this->capture('/Além do gerenciador, são participantes do registro de preços:\s*(.*?)(?:\s+4\.\s*)?ADESÃO À ATA/u', $flat);
        if ($section !== null) {
            preg_match_all('/(\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2})\s*(.*?)(?=\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}|$)/u', $section, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                if (in_array($match[1], array_column($funds, 'CNPJ_FUNDO'), true)) {
                    continue;
                }
                $funds[] = ['CNPJ_FUNDO' => $match[1], 'FUNDO' => ltrim(trim($match[2]), '. '), 'papel' => 'participante'];
            }
        }
        $products = $tables !== [] ? $this->tableProducts($tables) : $this->pdfProducts($text);
        if ($products === []) {
            throw new RuntimeException('Nenhum produto reconhecido; importação interrompida.');
        }
        $seen = [];
        foreach ($products as $product) {
            if (isset($seen[$product['ITEM']])) {
                throw new RuntimeException('Item repetido na mesma ata.');
            }
            $seen[$product['ITEM']] = true;
            if (bccomp(bcadd(bcmul($product['QTD'], $product['VL_UNIT'], 8), '0.005', 2), $product['VL_TOTAL'], 2) !== 0) {
                $issues[] = 'total_divergente_item:'.$product['ITEM'];
            }
        }

        return ['ata' => $data, 'funds' => $funds, 'products' => $products, 'issues' => $issues];
    }

    private function tableProducts(array $tables): array
    {
        $products = [];
        foreach ($tables as $rows) {
            if (! in_array(mb_strtoupper(trim($rows[0][0] ?? '')), ['ITEM', 'ID'], true)) {
                continue;
            }
            $header = array_map(fn ($cell) => mb_strtoupper(trim($cell)), $rows[0]);
            foreach (array_slice($rows, 1) as $row) {
                if (count($row) === 1 && str_starts_with(trim($row[0]), 'FORNECEDOR:')) {
                    continue;
                }
                if (count($row) !== count($header) || ! preg_match('/^\d+$/D', trim($row[0]))) {
                    throw new RuntimeException('Linha de produto não reconhecida; não será omitida.');
                }
                $data = ['ITEM' => trim($row[0]), 'DISCRIMINACAO' => trim($row[1]), 'MARCA' => null];
                foreach ($header as $index => $label) {
                    $column = match ($label) {
                        'MARCA' => 'MARCA', 'UND', 'UND.', 'MEDIDA' => 'UND',
                        'QTD', 'QTDE', 'QUNT.' => 'QTD',
                        'VL_UNIT', 'VL. UNT.', 'VL UNIT', 'VALOR UNT' => 'VL_UNIT',
                        'VL_TOTAL', 'TOTAL', 'VL. TOTAL', 'VALOR TOTAL' => 'VL_TOTAL', default => null,
                    };
                    if ($column !== null) {
                        $data[$column] = trim($row[$index]);
                    }
                }
                $products[] = $this->product($data, $row);
            }
        }

        return $products;
    }

    private function pdfProducts(string $text): array
    {
        $section = $this->required('/ITEM\s+DISCRIMINAÇÃO\s+UND\s+QTD\s+VL_UNIT\s+TOTAL(.*?)FORNECEDOR:/su', $text);
        $products = [];
        foreach (preg_split('/\R/u', trim($section)) as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (! preg_match('/^\s*(\d+)\s+(.+?)\s{2,}(\S+)\s+([\d.,]+)\s+([\d.,]+)\s+([\d.,]+)\s*$/uD', $line, $match)) {
                throw new RuntimeException('Layout PDF de produto não reconhecido; requer conferência.');
            }
            $products[] = $this->product(['ITEM' => $match[1], 'DISCRIMINACAO' => trim($match[2]),
                'MARCA' => null, 'UND' => $match[3], 'QTD' => $match[4], 'VL_UNIT' => $match[5], 'VL_TOTAL' => $match[6]], [$line]);
        }

        return $products;
    }

    private function product(array $data, array $source): array
    {
        foreach (['QTD', 'VL_UNIT', 'VL_TOTAL'] as $column) {
            $raw = $data[$column] ?? '';
            if (preg_match('/^\d{1,3}(?:\.\d{3})+$/D', $raw)) {
                $raw = str_replace('.', '', $raw);
            }
            $value = DecimalAmount::normalize($raw);
            if ($value === null || str_starts_with($value, '-')) {
                throw new RuntimeException('Decimal inválido em '.$column.' do item '.$data['ITEM']);
            }
            $data[$column] = $value;
        }
        if (trim($data['DISCRIMINACAO']) === '' || trim($data['UND'] ?? '') === '') {
            throw new RuntimeException('Descrição/unidade ausente no produto.');
        }
        $data['MARCA'] = ($data['MARCA'] ?? '') !== '' ? $data['MARCA'] : null;
        $data['source_row'] = $source;

        return $data;
    }

    private function date(string $text): ?string
    {
        if (! preg_match('/Niquelândia\s*(?:[–-]\s*)?(?:Goiás|GO),\s*(?:[\p{L}-]+(?:,| de)\s*)?(\d{1,2}) de (\p{L}+) de (\d{4})/ui', $text, $match)) {
            return null;
        }
        $months = ['janeiro' => 1, 'fevereiro' => 2, 'março' => 3, 'abril' => 4, 'maio' => 5, 'junho' => 6, 'julho' => 7, 'agosto' => 8, 'setembro' => 9, 'outubro' => 10, 'novembro' => 11, 'dezembro' => 12];
        $month = $months[mb_strtolower($match[2])] ?? 0;
        if (! checkdate($month, (int) $match[1], (int) $match[3])) {
            throw new RuntimeException('Data documental inválida.');
        }

        return sprintf('%04d-%02d-%02d', $match[3], $month, $match[1]);
    }

    private function capture(string $pattern, string $text, int $group = 1): ?string
    {
        return preg_match($pattern, $text, $matches) ? trim($matches[$group]) : null;
    }

    private function required(string $pattern, string $text, int $group = 1): string
    {
        return $this->capture($pattern, $text, $group) ?? throw new RuntimeException('Trecho obrigatório não reconhecido: '.$pattern);
    }
}
