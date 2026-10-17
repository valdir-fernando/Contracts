<?php

namespace App\Infrastructure\Documents;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/** Render the trusted publication Word model and replace its # fields. */
final class PublicationDocumentTemplate
{
    private const WORD = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public function path(): string
    {
        return resource_path('documents/extracts/publication.docx');
    }

    public function render(array $fields): string
    {
        $zip = new ZipArchive;
        if ($zip->open($this->path(), ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Modelo de publicação indisponível.');
        }
        try {
            $xml = $zip->getFromName('word/document.xml');
        } finally {
            $zip->close();
        }
        if (! is_string($xml) || strlen($xml) > 2_000_000 || stripos($xml, '<!DOCTYPE') !== false) {
            throw new RuntimeException('Modelo de publicação inválido.');
        }
        $document = new DOMDocument;
        if (! $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
            throw new RuntimeException('Modelo de publicação inválido.');
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', self::WORD);
        $html = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><style>
            @page { size:A4; margin:36pt 48pt; }
            body { margin:0; font-family:DejaVu Sans,sans-serif; font-size:8pt; line-height:1.12; color:#000; }
            p { margin:0 0 2pt; }
            .pub-fund { font-size:11pt !important; font-weight:bold; color:#000; margin-bottom:1pt; }
            .pub-admin { color:#000; margin-bottom:5pt; }
            .pub-title { background:#fff; color:#000; font-size:11pt !important; font-weight:bold; padding:3pt 5pt; margin:0 0 5pt; }
            .pub-total { background:#fff; padding:0; margin:4pt 0 6pt; text-align:left !important; }
            .pub-object-label { font-weight:bold; margin:5pt 0 2pt; }
            .pub-object { text-align:justify; }
            .pub-signature { text-align:center !important; margin-bottom:1pt; }
            .pub-gap { height:3pt; }
            </style></head><body>';
        foreach ($xpath->query('/w:document/w:body/*') as $node) {
            if ($node->localName !== 'p') {
                continue;
            }
            $rendered = $this->paragraph($node, $xpath, $fields);
            if ($rendered !== '') {
                $html .= $rendered;
            } elseif ($node->textContent === '') {
                $html .= '<div class="pub-gap"></div>';
            }
        }

        return $html.'</body></html>';
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
                throw new RuntimeException('Campo não configurado no modelo de publicação: '.$marker);
            }
        }
        $original = $text;
        $text = strtr($text, $fields);
        $alignment = $xpath->evaluate('string(./w:pPr/w:jc/@w:val)', $paragraph);
        $alignment = ['center' => 'center', 'right' => 'right', 'both' => 'justify'][$alignment] ?? 'left';
        $size = (int) $xpath->evaluate('string((.//w:rPr/w:sz/@w:val)[1])', $paragraph);
        $size = round(($size > 0 ? max(7, min(14, $size / 2)) : 9) * 0.85, 1);
        $bold = $xpath->query('.//w:rPr/w:b[not(@w:val="0") and not(@w:val="false")]', $paragraph)->length > 0;
        $class = str_starts_with($original, '#FUNDO') ? 'pub-fund'
            : (str_starts_with($original, 'ADM:') ? 'pub-admin'
                : (str_starts_with($original, 'EXTRATO DE CONTRATO') ? 'pub-title'
                    : (str_starts_with($original, 'Objeto:') ? 'pub-object-label'
                        : (str_starts_with($original, '#OBJETO') ? 'pub-object'
                            : (str_starts_with($original, 'VALOR TOTAL:') ? 'pub-total'
                                : (str_starts_with($original, '#NOME_GESTOR') || str_starts_with($original, 'CPF: #CPF_GESTOR') || str_starts_with($original, '#CARGO_GESTOR')
                                    ? 'pub-signature' : ''))))));

        $safeText = nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        if ($class === 'pub-total') {
            $safeText = preg_replace('/^VALOR TOTAL:/u', '<strong>VALOR TOTAL:</strong>', $safeText);
        }

        return '<p'.($class !== '' ? ' class="'.$class.'"' : '').' style="text-align:'.$alignment.';font-size:'.$size.'pt;'.($bold ? 'font-weight:bold;' : '').'">'
            .$safeText.'</p>';
    }
}
