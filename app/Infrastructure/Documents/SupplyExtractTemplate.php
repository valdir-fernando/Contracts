<?php

namespace App\Infrastructure\Documents;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/** Render the trusted Word model, joining split Word runs before replacing its # fields. */
final class SupplyExtractTemplate
{
    private const WORD = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public function path(string $model = 'supply'): string
    {
        return resource_path('documents/extracts/'.match ($model) {
            'supply' => 'supply.docx',
            'property-lease' => 'property-lease.docx',
            default => throw new RuntimeException('Modelo de extrato não configurado.'),
        });
    }

    public function render(array $fields, string $model = 'supply'): string
    {
        $zip = new ZipArchive;
        if ($zip->open($this->path($model), ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Modelo do extrato indisponível.');
        }
        try {
            $xml = $zip->getFromName('word/document.xml');
            $logo = $zip->getFromName('word/media/image1.png');
        } finally {
            $zip->close();
        }
        if (! is_string($xml) || strlen($xml) > 2_000_000 || stripos($xml, '<!DOCTYPE') !== false) {
            throw new RuntimeException('Modelo de extrato invÃ¡lido.');
        }
        $document = new DOMDocument;
        if (! $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
            throw new RuntimeException('Modelo de extrato invÃ¡lido.');
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', self::WORD);
        $html = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><style>
            @page { size: A4; margin: 42pt 48pt 42pt 48pt; }
            body { margin:0; font-family:DejaVu Sans,sans-serif; font-size:8pt; line-height:1.12; color:#243247; }
            .emblem { text-align:center; margin:0 0 2pt; } .emblem img { width:60pt; height:45pt; }
            table { width:100%; border-collapse:collapse; table-layout:fixed; }
            td { border:.5pt solid #d4dce5; padding:3pt 4pt; vertical-align:middle; overflow-wrap:anywhere; }
            p { margin:0 0 2pt; } .full-row { border:.5pt solid #d4dce5; padding:5pt 7pt; color:#334155; }
            .organization td { background:#fff; border-color:#d4dce5; }
            .organization p { text-align:left !important; color:#334155; }
            .fund-row td { border-top:.5pt solid #d4dce5; padding-bottom:0; }
            .fund-row .emblem { float:right; text-align:right; margin:0 2pt 0 8pt; }
            .fund-row p { margin-bottom:2pt; }
            .fund-state p { margin-bottom:2pt; }
            .administration-row td { padding-top:0; }
            .extract-title td { background:#1e3a5f; border-color:#1e3a5f; }
            .extract-title p { color:#fff !important; font-size:11pt !important; margin:0; }
            .extract-total td { background:#f8fafc; }
            .extract-total p { color:#1e3a5f; }
            .extract-signature td { padding-top:6pt; padding-bottom:6pt; }
            </style></head><body>';
        $logoPending = is_string($logo) ? base64_encode($logo) : null;
        foreach ($xpath->query('/w:document/w:body/*') as $node) {
            if ($node->localName === 'tbl') {
                $html .= $this->table($node, $xpath, $fields, $logoPending);
                $logoPending = null;
            } elseif ($node->localName === 'p') {
                $html .= $this->paragraph($node, $xpath, $fields);
            }
        }

        return $html.'</body></html>';
    }

    private function table(DOMElement $table, DOMXPath $xpath, array $fields, ?string $logo = null): string
    {
        $columns = [];
        foreach ($xpath->query('./w:tblGrid/w:gridCol', $table) as $column) {
            $columns[] = max(1, (int) $column->getAttributeNS(self::WORD, 'w'));
        }
        $opening = '<table><colgroup>';
        foreach ($columns as $width) {
            $opening .= '<col style="width:'.($width / array_sum($columns) * 100).'%">';
        }
        $opening .= '</colgroup><tr aria-hidden="true">';
        // Dompdf needs individual cells to establish a grid used only by merged Word cells.
        foreach ($columns as $width) {
            $opening .= '<td style="width:'.($width / array_sum($columns) * 100).'%;height:0;padding:0;border:0;font-size:0;line-height:0"></td>';
        }
        $opening .= '</tr>';
        $rows = [];
        foreach ($xpath->query('./w:tr', $table) as $row) {
            $cells = [];
            $position = 0;
            foreach ($xpath->query('./w:tc', $row) as $cell) {
                $span = max(1, (int) $xpath->evaluate('string(./w:tcPr/w:gridSpan/@w:val)', $cell));
                $merge = $xpath->query('./w:tcPr/w:vMerge', $cell)->item(0);
                $cells[] = ['node' => $cell, 'position' => $position, 'span' => $span,
                    'continue' => $merge !== null && $merge->getAttributeNS(self::WORD, 'val') !== 'restart'];
                $position += $span;
            }
            $rows[] = $cells;
        }
        $html = $opening;
        $organizationContent = '';
        foreach ($rows as $index => $cells) {
            // Dompdf cannot split table cells across pages. Let long object text flow as a block.
            if (count($cells) === 1 && (str_contains($cells[0]['node']->textContent, '#Objeto') || str_contains($cells[0]['node']->textContent, '#EndImovel'))) {
                $content = '';
                foreach ($xpath->query('./w:p', $cells[0]['node']) as $paragraph) {
                    $content .= $this->paragraph($paragraph, $xpath, $fields);
                }
                $html .= '</table><div class="full-row">'.$content.'</div>'.$opening;

                continue;
            }
            $rowText = '';
            foreach ($cells as $rowCell) {
                $rowText .= ' '.$rowCell['node']->textContent;
            }
            $normalizedRow = mb_strtoupper($rowText);
            if (str_contains($normalizedRow, 'ESTADO DE GOI')) {
                foreach ($cells as $cell) {
                    foreach ($xpath->query('./w:p', $cell['node']) as $paragraph) {
                        $organizationContent .= $this->paragraph($paragraph, $xpath, $fields);
                    }
                }
                $organizationContent = '<div class="fund-state">'.$organizationContent.'</div>';

                continue;
            }
            $rowClass = str_contains($normalizedRow, 'EXTRATO CONTRATO') ? 'extract-title'
                : (str_contains($normalizedRow, '#FUNDO') ? 'fund-row'
                    : (str_contains($normalizedRow, 'ADM:') ? 'administration-row'
                        : (str_contains($normalizedRow, 'VL_TOTAL') || str_contains($normalizedRow, 'VL_MENSAL') ? 'extract-total'
                            : (str_contains($normalizedRow, 'NOMEGESTOR') ? 'extract-signature' : ''))));
            $html .= '<tr'.($rowClass !== '' ? ' class="'.$rowClass.'"' : '').'>';
            foreach ($cells as $cell) {
                if ($cell['continue']) {
                    continue;
                }
                $node = $cell['node'];
                $content = '';
                foreach ($xpath->query('./w:p', $node) as $paragraph) {
                    $content .= $this->paragraph($paragraph, $xpath, $fields);
                }
                if ($logo !== null && (str_contains($node->textContent, '#Fundo') || str_contains($node->textContent, '#FUNDO'))) {
                    $content = '<div class="emblem"><img alt="Brasao do municipio" src="data:image/png;base64,'.$logo.'"></div>'.$organizationContent.$content;
                    $rowClass = 'fund-row';
                }
                $rowspan = 1;
                for ($next = $index + 1; $next < count($rows); $next++) {
                    $continuation = false;
                    foreach ($rows[$next] as $candidate) {
                        if ($candidate['position'] === $cell['position'] && $candidate['continue']) {
                            $continuation = true;
                            break;
                        }
                    }
                    if (! $continuation) {
                        break;
                    }
                    $rowspan++;
                }
                $width = array_sum(array_slice($columns, $cell['position'], $cell['span'])) / array_sum($columns) * 100;
                $style = 'width:'.$width.'%;';
                foreach ($xpath->query('./w:tcPr/w:tcBorders/*', $node) as $border) {
                    if (in_array($border->localName, ['top', 'bottom', 'left', 'right'], true)
                        && in_array($border->getAttributeNS(self::WORD, 'val'), ['nil', 'none'], true)) {
                        if ($border->localName === 'top' && (str_contains($node->textContent, '#Fundo') || str_contains($node->textContent, '#FUNDO'))) {
                            continue;
                        }
                        $style .= 'border-'.$border->localName.':none;';
                    }
                }
                $html .= '<td colspan="'.$cell['span'].'" rowspan="'.$rowspan.'" style="'.$style.'">'.$content.'</td>';
            }
            $html .= '</tr>';
        }

        return $html.'</table>';
    }

    private function paragraph(DOMElement $paragraph, DOMXPath $xpath, array $fields): string
    {
        $text = '';
        foreach ($xpath->query('.//w:t|.//w:br|.//w:tab', $paragraph) as $part) {
            $text .= $part->localName === 't' ? $part->textContent : ($part->localName === 'br' ? "\n" : ' ');
        }
        if (trim($text) === '') {
            return '';
        }
        preg_match_all('/#[\p{L}_][\p{L}\p{N}_]*/u', $text, $matches);
        foreach ($matches[0] as $marker) {
            if (! array_key_exists($marker, $fields)) {
                throw new RuntimeException('Campo nÃ£o configurado no modelo: '.$marker);
            }
        }
        $alignment = $xpath->evaluate('string(./w:pPr/w:jc/@w:val)', $paragraph);
        $alignment = ['center' => 'center', 'right' => 'right', 'both' => 'justify'][$alignment] ?? 'left';
        $size = (int) $xpath->evaluate('string((.//w:rPr/w:sz/@w:val)[1])', $paragraph);
        $size = $size > 0 ? max(7, min(14, $size / 2)) : 9;
        $size = round($size * 0.85, 1);
        $bold = $xpath->query('.//w:rPr/w:b[not(@w:val="0") and not(@w:val="false")]', $paragraph)->length > 0;
        $text = strtr($text, $fields);

        return '<p style="text-align:'.$alignment.';font-size:'.$size.'pt;'.($bold ? 'font-weight:bold;' : '').'">'
            .nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')).'</p>';
    }
}
