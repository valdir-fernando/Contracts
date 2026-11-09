<?php

namespace App\Infrastructure\Atas;

use App\Domain\Shared\DecimalAmount;
use RuntimeException;

final class AtaAmendmentProductParser
{
    public function parse(array $document, array $metadata): array
    {
        $products = [];
        $header = [];
        $issues = [];
        $totals = [];
        $text = implode("\n", array_column($document['pages'], 'text'));
        foreach ($document['pages'] as $page) {
            foreach ($page['tables'] as $tableIndex => $table) {
                $combined = [];
                $start = 0;
                foreach (array_slice($table, 0, 5) as $index => $row) {
                    foreach ($row as $column => $cell) {
                        $combined[$column] = trim(($combined[$column] ?? '').' '.($cell ?? ''));
                    }
                    $candidate = $this->header($this->cells($combined));
                    if ($candidate !== []) {
                        $header = $candidate;
                        $start = $index + 1;
                        break;
                    }
                }
                $rows = array_slice($table, $start);
                if ($header === []) {
                    continue;
                }
                foreach ($rows as $raw) {
                    $cells = $this->cells($raw);
                    if ($cells === [] || $cells === ['M'] || array_filter($cells, fn ($cell) => $cell !== '(R$)') === []) {
                        continue;
                    }
                    if (preg_match('/^(?:VALOR|TOTAL)/ui', $cells[0])) {
                        $amount = $this->decimal(end($cells));
                        if ($amount !== null) {
                            $totals[] = $amount;
                        }

                        continue;
                    }
                    if ($this->header($cells) !== []) {
                        continue;
                    }
                    if (! preg_match('/^\d+$/D', $cells[0])) {
                        // A merged OCR footer can lose its label; accept it only when its
                        // printed amount exactly equals all preceding documentary items.
                        $footerAmount = count($cells) <= 3 ? $this->decimal(end($cells)) : null;
                        $runningTotal = array_reduce($products, fn ($sum, $product) => bcadd($sum, $product['VL_TOTAL'], 4), '0.0000');
                        if ($products !== [] && $footerAmount !== null && bccomp($runningTotal, $footerAmount, 4) === 0) {
                            $totals[] = $footerAmount;

                            continue;
                        }
                        // Tables of signatures are outside the item grid.
                        if (count($cells) <= 2 && str_contains(implode(' ', $cells), '___')) {
                            continue;
                        }
                        if (count($cells) === 1 && $products !== [] && ! str_contains($cells[0], 'R$')) {
                            $last = array_key_last($products);
                            $products[$last]['DISCRIMINACAO'] .= ' '.$cells[0];
                            $products[$last]['source_row']['continuations'][] = ['page' => $page['page'], 'cells' => $raw];

                            continue;
                        }
                        throw new RuntimeException('Linha de produto não reconhecida na página '.$page['page'].': '.mb_substr(implode(' | ', $cells), 0, 100));
                    }
                    if (count($cells) === count($header) - 1 && in_array('MARCA', $header, true)) {
                        array_splice($cells, array_search('MARCA', $header, true), 0, [null]);
                    }
                    if (count($cells) !== count($header)) {
                        throw new RuntimeException('Colunas de produto divergentes na página '.$page['page'].', item '.$cells[0]);
                    }
                    $data = array_combine($header, $cells);
                    $data['MARCA'] ??= null;
                    foreach (['QTD', 'VL_UNIT', 'VL_TOTAL'] as $column) {
                        $data[$column] = $this->decimal($data[$column]);
                        if ($data[$column] === null || str_starts_with($data[$column], '-')) {
                            throw new RuntimeException('Decimal inválido no item '.$data['ITEM'].', campo '.$column);
                        }
                    }
                    if (isset($products[$data['ITEM']])) {
                        throw new RuntimeException('Item repetido no aditivo: '.$data['ITEM']);
                    }
                    if (bccomp(bcadd(bcmul($data['QTD'], $data['VL_UNIT'], 8), '0.005', 2), $data['VL_TOTAL'], 2) !== 0) {
                        $issues[] = 'total_divergente_item:'.$data['ITEM'];
                    }
                    $data['source_row'] = ['page' => $page['page'], 'cells' => $raw, 'extraction' => $document['extraction'], 'sha256' => $metadata['source_sha256']];
                    if (isset($document['reviews'][$page['page'].'/'.$tableIndex])) {
                        $data['source_row']['review'] = $document['reviews'][$page['page'].'/'.$tableIndex];
                    }
                    $products[$data['ITEM']] = $data;
                }
            }
        }
        $total = '0.0000';
        foreach ($products as $product) {
            $total = bcadd($total, $product['VL_TOTAL'], 4);
        }
        if ($totals !== [] && bccomp(end($totals), $total, 2) !== 0) {
            $issues[] = 'soma_itens_diverge_total_documento:'.end($totals);
        }
        $date = $this->instrumentDate($text);
        $hasNumericRows = false;
        foreach ($document['pages'] as $page) {
            foreach ($page['tables'] as $table) {
                foreach ($table as $row) {
                    $cells = $this->cells($row);
                    $hasNumericRows = $hasNumericRows || count($cells) >= 5 && ctype_digit($cells[0]);
                }
            }
        }
        if ($products === [] && ($header !== [] || $hasNumericRows || preg_match('/\b(?:tabela|planilha|discriminados)\b/ui', $text))) {
            throw new RuntimeException('O documento menciona uma tabela, mas nenhum produto foi reconhecido; requer conferência.');
        }
        if ($date === null) {
            $issues[] = 'data_aditivo_nao_identificada';
        }
        $process = $metadata['PROCESSO_ADITIVO'];
        if ($process === null) {
            preg_match_all('/(?:Processo(?:\s+Administrativo)?\s*(?:n[º°o.]*)?\s*:?\s*)(\d{8,})/ui', $text, $matches);
            $candidates = array_values(array_diff(array_unique($matches[1]), [$metadata['PROCESSO_ORIGEM']]));
            $process = count($candidates) === 1 ? $candidates[0] : null;
        }
        if ($process === null) {
            $issues[] = 'processo_aditivo_nao_identificado';
        }

        return ['DATA_ADT' => $date, 'PROC_ADT' => $process, 'products' => array_values($products), 'total' => $total,
            'issues' => $issues, 'has_item_header' => $header !== []];
    }

    private function cells(array $row): array
    {
        return array_values(array_filter(array_map(fn ($cell) => trim(preg_replace('/\s+/u', ' ', $cell ?? '')), $row), fn ($cell) => $cell !== ''));
    }

    private function header(array $cells): array
    {
        $columns = [];
        foreach ($cells as $cell) {
            $label = preg_replace('/[^\p{L}]/u', '', mb_strtoupper($cell));
            $column = match ($label) {
                'ITEM', 'ID', 'ITE', 'TEM' => 'ITEM', 'DESCRIÇÃO', 'ÉDESCRIÇÃO', 'DISCRIMINAÇÃO', 'ESPECIFICAÇÃOSINTÉTICA' => 'DISCRIMINACAO',
                'MARCA', 'MARÇA' => 'MARCA', 'UND', 'UNIDADE' => 'UND', 'QTD', 'QUANT' => 'QTD',
                'VUNITR', 'VUNIT', 'VLUNIT', 'VLUNITR' => 'VL_UNIT', 'VTOTALR', 'VTOTAL', 'VLTOTAL', 'VLTOTALR' => 'VL_TOTAL', default => null,
            };
            if ($column === null) {
                return [];
            }
            $columns[] = $column;
        }

        if (count($columns) === 6 && $columns[0] === 'DISCRIMINACAO' && in_array('MARCA', $columns, true)) {
            array_unshift($columns, 'ITEM');
        }

        return count($columns) >= 6 && $columns[0] === 'ITEM' && in_array('VL_TOTAL', $columns, true) ? $columns : [];
    }

    private function decimal(string $value): ?string
    {
        $value = preg_replace('/(?:R\$|\s)/u', '', $value);
        if (preg_match('/^\d{1,3}(?:\.\d{3})+$/D', $value)) {
            $value = str_replace('.', '', $value);
        }

        return DecimalAmount::normalize($value);
    }

    public function instrumentDate(string $text): ?string
    {
        preg_match_all('/Niquel[âa]ndia\s*[–—\/\x{00AD}-]?\s*(?:GO|Goiás)\s*,?\s*(\d{1,2})\s*de\s*(\p{L}+?)\s*de\s*(\d{4})/ui', $text, $matches, PREG_SET_ORDER);
        $dates = [];
        $months = ['janeiro' => 1, 'fevereiro' => 2, 'março' => 3, 'abril' => 4, 'maio' => 5, 'junho' => 6, 'julho' => 7, 'agosto' => 8, 'setembro' => 9, 'outubro' => 10, 'novembro' => 11, 'dezembro' => 12];
        foreach ($matches as $match) {
            $month = $months[mb_strtolower($match[2])] ?? 0;
            if (checkdate($month, (int) $match[1], (int) $match[3])) {
                $dates[] = sprintf('%04d-%02d-%02d', $match[3], $month, $match[1]);
            }
        }
        $dates = array_values(array_unique($dates));

        return count($dates) === 1 ? $dates[0] : null;
    }
}
