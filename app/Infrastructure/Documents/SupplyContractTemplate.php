<?php

namespace App\Infrastructure\Documents;

use App\Models\Contract;
use DOMDocument;
use DOMElement;
use Dompdf\Dompdf;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/** Interpret only the trusted, bundled Word model; never accept uploaded templates or HTML. */
final class SupplyContractTemplate
{
    private const WORD = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public function path(string $model = 'supply'): string
    {
        return resource_path('documents/contracts/'.match ($model) {
            'supply' => 'supply.docx',
            'services' => 'services.docx',
            'property-lease' => 'property-lease.docx',
            default => throw new RuntimeException('Modelo de contrato desconhecido.'),
        });
    }

    public function render(array $fields, array $products, array $budgets, string $productsTotal = '0.0000', string $model = 'supply'): string
    {
        $zip = new ZipArchive;
        if ($zip->open($this->path($model), ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Modelo do contrato de fornecimento indisponível.');
        }
        try {
            $xml = $zip->getFromName('word/document.xml');
            $logo = $zip->getFromName('word/media/image1.png');
            $footer = $zip->getFromName('word/media/image2.png');
        } finally {
            $zip->close();
        }
        if (! is_string($xml) || strlen($xml) > 2_000_000 || stripos($xml, '<!DOCTYPE') !== false || ! is_string($logo) || ! is_string($footer)) {
            throw new RuntimeException('Modelo do contrato de fornecimento inválido.');
        }
        $document = new DOMDocument;
        if (! $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
            throw new RuntimeException('Modelo do contrato de fornecimento inválido.');
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', self::WORD);
        $metrics = (new Dompdf)->getFontMetrics();
        $fundWidth = $metrics->getTextWidth($fields['#FUNDO'], $metrics->getFont('DejaVu Sans', 'bold'), 18);
        $fundSize = min(18, 18 * 297 / max(1, $fundWidth));
        $reference = 'Contrato: '.$fields['#CONTRATO'].' – Processo: '.$fields['#PROCESSO'];
        $html = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="contract-reference" content="'.$this->escape($reference).'"><style>
            @page { size:A4; margin:110pt 30pt 90pt 51pt; }
            body { margin:0; font-family:DejaVu Sans,sans-serif; font-size:10pt; line-height:1.2; color:#000; }
            header { position:fixed; top:-90pt; left:0; right:0; height:80pt; border-bottom:.6pt solid #12377a; }
            header img { width:205pt; height:auto; } .header-fund { position:absolute; right:0; top:24pt; width:297pt; font-weight:bold; white-space:nowrap; overflow-wrap:normal; text-align:right; }
            footer { position:fixed; bottom:-75pt; left:0; right:0; height:75pt; }
            footer img { width:100%; height:auto; }
            .footer-administration { position:absolute; left:60%; top:49%; width:20%; height:22%; background:#fff; color:#082b87; font-size:8pt; text-align:center; line-height:13pt; }
            .lease-footer .footer-administration { left:63%; top:61%; width:18%; height:20%; }
            p { margin:0 0 5pt; overflow-wrap:anywhere; } .gap { height:5pt; }
            .clause { page-break-after:avoid; margin-top:8pt; } .clause-opening { page-break-inside:avoid; }
            table { width:100%; border-collapse:collapse; table-layout:fixed; margin:5pt 0 10pt; font-size:8pt; }
            thead { display:table-header-group; } th, td { border:.5pt solid #777; padding:4pt; vertical-align:top; word-wrap:break-word; }
            th { font-weight:bold; text-align:center; background:#eee; } .number { text-align:right; }
            .ficha { font-weight:bold; margin-top:8pt; page-break-after:avoid; }
            .products { font-size:7.5pt; line-height:1.1; }
            .products caption { text-align:left; font-size:9pt; padding:5pt 0 2pt; }
            .products th, .products td { border:.5pt solid #000; padding:2pt 3pt; text-align:center; vertical-align:middle; }
            .products th:first-child { white-space:nowrap; }
            .products .products-total td { text-align:right; }
            .products td.description { text-align:left; }
            .products td.number { text-align:right; white-space:nowrap; }
            .budget-lines { margin:4pt 0 8pt; table-layout:auto; }
            .budget-lines td { border:0; padding:0 0 2pt; line-height:1.1; vertical-align:baseline; white-space:nowrap; word-wrap:normal; overflow-wrap:normal; }
            .budget-lines .budget-leader { text-align:center; }
            .budget-lines .budget-amount { text-align:right; }
            .signature-block { page-break-inside:avoid; margin-top:8pt; }
            .signature-block > p { text-align:right !important; }
            .signatures { margin:10pt 0 0; page-break-inside:avoid; }
            .signatures td { width:50%; border:0; padding:3pt 10pt; }
            .signatures p { margin-bottom:3pt; font-size:9pt !important; line-height:1.15; text-align:center !important; }
            .signatures .signature-space { height:60pt; border-bottom:.5pt solid #000; margin-bottom:5pt; }
            .signatures .witness { padding-top:12pt; }
            .signatures .witness-signature { padding-top:38pt; padding-bottom:0; }
            .signatures .witness-document { padding-top:1pt; padding-bottom:0; }
            .signatures .witness p { text-align:left !important; }
            </style></head><body>';
        $html .= '<header><img alt="Prefeitura de Niquelândia" src="data:image/png;base64,'.base64_encode($logo).'">'
            .'<div class="header-fund" style="font-size:'.$fundSize.'pt">'.$this->escape($fields['#FUNDO']).'</div></header>';
        // The source footer is an image with baked-in years. Cover only that legend with the current administration.
        $html .= '<footer'.($model === 'property-lease' ? ' class="lease-footer"' : '').'><img alt="Rodapé institucional" src="data:image/png;base64,'.base64_encode($footer).'">'
            .'<div class="footer-administration">Gestão '.$this->escape(str_replace('/', '–', $fields['#ADM'])).'</div></footer>';

        $clauseOpen = false;
        $signaturesOpen = false;
        foreach ($xpath->query('/w:document/w:body/*') as $node) {
            if ($node->localName === 'p') {
                $text = $this->text($node, $xpath);
                if (str_starts_with(trim($text), 'NIQUELÂNDIA-GO')) {
                    $html .= '<div class="signature-block">';
                    $signaturesOpen = true;
                }
                // Empty Word paragraphs must not add blank pages after the closing block.
                if ($signaturesOpen && trim($text) === '') {
                    continue;
                }
                if (str_contains($text, '#DOTAÇÃO') || str_contains($text, '#Dotacao')) {
                    $html .= $this->budgets($budgets);
                } else {
                    $heading = preg_match('/^(?:\d+\s*[-–]\s*)?CLÁUSULA/u', trim($text));
                    if ($heading && ! $clauseOpen) {
                        $html .= '<div class="clause-opening">';
                        $clauseOpen = true;
                    }
                    $html .= $this->paragraph($node, $xpath, $fields);
                    if ($clauseOpen && ! $heading && trim($text) !== '') {
                        $html .= '</div>';
                        $clauseOpen = false;
                    }
                }
            } elseif ($node->localName === 'tbl') {
                if ($clauseOpen) {
                    $html .= '</div>';
                    $clauseOpen = false;
                }
                $html .= (str_contains(mb_strtoupper($node->textContent), 'DISCRIMINAÇÃO')
                    || ($model === 'services' && str_contains(mb_strtoupper($node->textContent), 'DISRIMINAÇÃO')))
                    ? $this->products($products, $productsTotal, $model)
                    : $this->signatures($node, $xpath, $fields);
            }
        }

        return $html.($clauseOpen ? '</div>' : '').($signaturesOpen ? '</div>' : '').'</body></html>';
    }

    private function text(DOMElement $node, DOMXPath $xpath): string
    {
        $text = '';
        foreach ($xpath->query('.//w:t|.//w:br|.//w:tab[parent::w:r]', $node) as $part) {
            $text .= $part->localName === 't' ? $part->textContent : ($part->localName === 'br' ? "\n" : ' ');
        }

        return $text;
    }

    private function paragraph(DOMElement $node, DOMXPath $xpath, array $fields): string
    {
        $text = $this->text($node, $xpath);
        if (trim($text) === '') {
            return '<div class="gap"></div>';
        }
        preg_match_all('/#[\p{L}_][\p{L}\p{N}_]*/u', $text, $markers, PREG_OFFSET_CAPTURE);
        foreach ($markers[0] as [$marker]) {
            if (! array_key_exists($marker, $fields)) {
                throw new RuntimeException('Campo não configurado no modelo do contrato: '.$marker);
            }
        }

        // Keep inline emphasis, joining split Word runs before matching template fields.
        $runs = [];
        $offset = 0;
        foreach ($xpath->query('.//w:r', $node) as $run) {
            $value = $this->text($run, $xpath);
            $runs[] = ['start' => $offset, 'end' => $offset + strlen($value),
                'bold' => $xpath->query('./w:rPr/w:b[not(@w:val="0") and not(@w:val="false")]', $run)->length > 0,
                'italic' => $xpath->query('./w:rPr/w:i[not(@w:val="0") and not(@w:val="false")]', $run)->length > 0];
            $offset += strlen($value);
        }
        $content = '';
        foreach (preg_split('/(#[\p{L}_][\p{L}\p{N}_]*)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_OFFSET_CAPTURE) as [$part, $start]) {
            if ($part === '') {
                continue;
            }
            if (str_starts_with($part, '#') && array_key_exists($part, $fields)) {
                $style = collect($runs)->first(fn ($run) => $start >= $run['start'] && $start < $run['end']) ?? [];
                $content .= $this->styled($fields[$part], $style);

                continue;
            }
            foreach ($runs as $run) {
                $from = max($start, $run['start']);
                $to = min($start + strlen($part), $run['end']);
                if ($to > $from) {
                    $content .= $this->styled(substr($text, $from, $to - $from), $run);
                }
            }
        }
        $alignment = $xpath->evaluate('string(./w:pPr/w:jc/@w:val)', $node);
        $alignment = ['center' => 'center', 'right' => 'right', 'both' => 'justify'][$alignment] ?? 'left';
        $size = (int) $xpath->evaluate('string((./w:pPr/w:rPr/w:sz/@w:val | ./w:r/w:rPr/w:sz/@w:val)[1])', $node);
        $size = $size > 0 ? max(8, min(14, $size / 2)) : 10;
        $clause = preg_match('/^(?:\d+\s*[-–]\s*)?CLÁUSULA/u', trim($text)) ? ' class="clause"' : '';

        return '<p'.$clause.' style="text-align:'.$alignment.';font-size:'.$size.'pt;">'.$content.'</p>';
    }

    private function styled(string $text, array $style): string
    {
        $html = nl2br($this->escape($text));
        if ($style['bold'] ?? false) {
            $html = '<strong>'.$html.'</strong>';
        }

        return ($style['italic'] ?? false) ? '<em>'.$html.'</em>' : $html;
    }

    private function products(array $products, string $total, string $model): string
    {
        if ($products === []) {
            return '<p>Produtos não informados no contrato.</p>';
        }
        $html = '<table class="products"><thead><tr>';
        $columns = $model === 'services' ? ['Item' => 6, 'Discriminação' => 55]
            : ['Item' => 6, 'Cod' => 7, 'Discriminação' => $model === 'property-lease' ? 48 : 39];
        if ($model === 'supply') {
            $columns['Marca'] = 9;
        }
        $columns += ['UND' => 7, 'QTD' => 8, 'VL_Unit' => 11, 'VL_Total' => 13];
        foreach ($columns as $label => $width) {
            $html .= '<th style="width:'.$width.'%">'.$label.'</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($products as $row) {
            // Dompdf cannot split a single table cell over pages. Use continuation rows for long descriptions.
            foreach ($this->textParts($row['description'], 2000) as $index => $description) {
                $html .= '<tr>';
                foreach (['item', 'code', 'description', 'brand', 'unit', 'quantity', 'unit_price', 'total'] as $field) {
                    if (($field === 'brand' && $model !== 'supply') || ($field === 'code' && $model === 'services')) {
                        continue;
                    }
                    $value = $field === 'description' ? $description : (string) ($row[$field] ?? '');
                    if ($index > 0 && $field !== 'description') {
                        $value = $field === 'item' ? $value.' (cont.)' : '';
                    } elseif ($field === 'total') {
                        $value = str_replace('R$ ', '', Contract::moneyLabel($value));
                    } elseif (in_array($field, ['quantity', 'unit_price'], true)) {
                        [$integer, $fraction] = array_pad(explode('.', $value), 2, '');
                        $integer = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $integer);
                        $value = $integer.','.str_pad(rtrim($fraction, '0'), 2, '0');
                    }
                    $class = $field === 'description' ? 'description' : (in_array($field, ['quantity', 'unit_price', 'total'], true) ? 'number' : '');
                    $html .= '<td'.($class !== '' ? ' class="'.$class.'"' : '').'>'.nl2br($this->escape($value)).'</td>';
                }
                $html .= '</tr>';
            }
        }
        $html .= '<tr class="products-total"><td colspan="'.(count($columns) - 1).'"><strong>TOTAL GERAL</strong></td><td class="number"><strong>'
            .$this->escape(str_replace('R$ ', '', Contract::moneyLabel($total))).'</strong></td></tr></tbody></table>';

        return $html;
    }

    private function textParts(string $description, int $limit = 700): array
    {
        $parts = [];
        $current = '';
        foreach (preg_split('/(?<=\s)/u', $description) as $word) {
            foreach (mb_str_split($word, $limit) as $piece) {
                if (mb_strlen($current.$piece) > $limit || substr_count($current.$piece, "\n") > 40) {
                    $parts[] = $current;
                    $current = '';
                }
                $current .= $piece;
            }
        }
        $parts[] = $current;

        return $parts;
    }

    private function budgets(array $records): string
    {
        if ($records === []) {
            return '<p>Dotação orçamentária e valor por ficha: não informados nos registros conferidos do contrato.</p>';
        }
        $html = '<table class="budget-lines"><tbody>';
        $metrics = (new Dompdf)->getFontMetrics();
        $font = $metrics->getFont('DejaVu Sans');
        $lines = [];
        $maxLabel = 0;
        $maxAmount = 0;
        foreach ($records as ['ficha' => $ficha, 'allocation' => $allocation, 'amount' => $amount]) {
            $label = filled($allocation) ? (string) $allocation : 'Dotação não informada';
            if (filled($ficha) && ! str_contains($label, (string) $ficha)) {
                $label .= ' ('.$ficha.')';
            }
            $value = Contract::moneyLabel($amount);
            $lines[] = [$label, $value];
            $maxLabel = max($maxLabel, $metrics->getTextWidth($label, $font, 9));
            $maxAmount = max($maxAmount, $metrics->getTextWidth($value, $font, 9));
        }
        // All rows share column widths: reserve both maxima, even when they occur on different fichas.
        $size = min(9, 9 * 508 / max(1, $maxLabel + $maxAmount));
        $labelWidth = $maxLabel * $size / 9 + 3;
        $amountWidth = $maxAmount * $size / 9 + 3;
        $leaderWidth = max(0, 514.28 - $labelWidth - $amountWidth);
        $dots = str_repeat('.', (int) floor($leaderWidth / max(.01, $metrics->getTextWidth('.', $font, $size))));
        foreach ($lines as [$label, $value]) {
            $html .= '<tr style="font-size:'.$size.'pt"><td class="budget-allocation" style="width:'.$labelWidth.'pt">'.$this->escape($label).'</td>'
                .'<td class="budget-leader" style="width:'.$leaderWidth.'pt">'.$dots.'</td><td class="budget-amount" style="width:'.$amountWidth.'pt">'.$this->escape($value).'</td></tr>';
        }

        return $html.'</tbody></table>';
    }

    private function signatures(DOMElement $table, DOMXPath $xpath, array $fields): string
    {
        $html = '<table class="signatures">';
        foreach ($xpath->query('./w:tr', $table) as $row) {
            $cells = [];
            $isParty = false;
            $witnessClass = 'witness';
            foreach ($xpath->query('./w:tc', $row) as $cell) {
                $content = '';
                foreach ($xpath->query('./w:p', $cell) as $paragraph) {
                    // Ignore Word's layout-only empty paragraphs, including text-box fallbacks.
                    $text = $this->text($paragraph, $xpath);
                    if (preg_replace('/\s+/u', '', $text) !== '') {
                        if (preg_match('/^\s*[12]\.\s*_+/u', $text)) {
                            $witnessClass = 'witness witness-signature';
                        } elseif (preg_match('/^\s*CPF\s*_+/u', $text)) {
                            $witnessClass = 'witness witness-document';
                        }
                        $content .= $this->paragraph($paragraph, $xpath, $fields);
                        $isParty = $isParty || str_contains($text, '#NOME_GESTOR') || str_contains($text, '#FORNECEDOR')
                            || str_contains($text, '#NomeGestor') || str_contains($text, '#NomeLocador');
                    }
                }
                if ($content !== '') {
                    $cells[] = $content;
                }
            }
            if ($cells !== []) {
                $html .= '<tr>';
                foreach ($cells as $content) {
                    $html .= '<td'.(count($cells) === 1 ? ' colspan="2" class="'.$witnessClass.'"' : '').'>'
                        .($isParty ? '<div class="signature-space"></div>' : '').$content.'</td>';
                }
                $html .= '</tr>';
            }
        }

        return $html.'</table>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
