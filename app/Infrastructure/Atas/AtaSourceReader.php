<?php

namespace App\Infrastructure\Atas;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

final class AtaSourceReader
{
    public function docx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Não foi possível abrir o DOCX.');
        }
        try {
            $xml = $zip->getFromName('word/document.xml');
        } finally {
            $zip->close();
        }
        $dom = new DOMDocument;
        if (! is_string($xml) || ! $dom->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException('DOCX sem documento XML válido.');
        }
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $paragraphs = [];
        $tables = [];
        foreach ($xpath->query('//w:body/*') as $node) {
            if ($node->localName === 'p') {
                $paragraphs[] = $this->text($xpath, $node);
            } elseif ($node->localName === 'tbl') {
                $rows = [];
                foreach ($xpath->query('./w:tr', $node) as $row) {
                    $cells = [];
                    foreach ($xpath->query('./w:tc', $row) as $cell) {
                        $cells[] = $this->text($xpath, $cell);
                    }
                    $rows[] = $cells;
                    $paragraphs[] = implode(' | ', $cells);
                }
                $tables[] = $rows;
            }
        }

        return ['text' => implode("\n", $paragraphs), 'tables' => $tables];
    }

    public function pdf(string $path): string
    {
        $process = new Process(['pdftotext', '-layout', '-enc', 'UTF-8', $path, '-']);
        $process->setTimeout(60);
        $process->mustRun();
        $text = $process->getOutput();
        if (trim($text) === '') {
            throw new RuntimeException('PDF sem texto extraível; requer conferência/OCR antes da importação.');
        }

        return $text;
    }

    /** Numeric table columns, including cells whose total wraps onto a new line. */
    public function pdfTableNumbers(string $path): array
    {
        $process = new Process(['pdftotext', '-bbox', '-enc', 'UTF-8', $path, '-']);
        $process->setTimeout(60);
        $process->mustRun();
        $dom = new DOMDocument;
        if (! $dom->loadXML($process->getOutput(), LIBXML_NONET)) {
            throw new RuntimeException('Coordenadas do PDF inválidas.');
        }
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('h', 'http://www.w3.org/1999/xhtml');
        $started = false;
        $minimumX = null;
        $quantityRight = null;
        $priceLeft = null;
        $numbers = [];
        foreach ($xpath->query('//h:page') as $page) {
            $words = $xpath->query('h:word', $page);
            $minimumY = 0;
            $maximumY = null;
            foreach ($words as $word) {
                if ($word->textContent === 'Página' && (float) $word->getAttribute('yMin') < 100) {
                    $minimumY = (float) $word->getAttribute('yMax') + 2;
                }
            }
            if ($minimumX === null) {
                foreach ($words as $word) {
                    if (in_array($word->textContent, ['QTD', 'QTDE', 'QUNT.'], true)) {
                        $minimumX = (float) $word->getAttribute('xMin') - 25;
                        $quantityRight = (float) $word->getAttribute('xMax') + 20;
                        $minimumY = (float) $word->getAttribute('yMax');
                        foreach ($words as $headerWord) {
                            if ((float) $headerWord->getAttribute('xMin') > (float) $word->getAttribute('xMax')
                                && abs((float) $headerWord->getAttribute('yMin') - (float) $word->getAttribute('yMin')) < 20
                                && in_array($headerWord->textContent, ['VL_UNIT', 'VL.', 'VL', 'VALOR'], true)) {
                                $priceLeft = (float) $headerWord->getAttribute('xMin') - 25;
                                break;
                            }
                        }
                        $started = true;
                        break;
                    }
                }
            }
            foreach ($words as $index => $word) {
                $endsTable = $word->textContent === 'FORNECEDOR:'
                    || ($word->textContent === 'listagem' && $index > 0 && $words->item($index - 1)->textContent === 'A');
                if ($started && $endsTable && (float) $word->getAttribute('yMin') > $minimumY) {
                    $maximumY = (float) $word->getAttribute('yMin');
                    break;
                }
            }
            foreach ($words as $word) {
                $value = $word->textContent;
                $numericColumn = $minimumX !== null && ($priceLeft === null
                    ? (float) $word->getAttribute('xMin') >= $minimumX
                    : ((float) $word->getAttribute('xMin') >= $priceLeft
                        || ((float) $word->getAttribute('xMax') >= $minimumX + 23 && (float) $word->getAttribute('xMax') <= $quantityRight)));
                if ($started && $numericColumn
                    && (float) $word->getAttribute('yMin') > $minimumY
                    && ($maximumY === null || (float) $word->getAttribute('yMin') < $maximumY)
                    && preg_match('/^\d[\d.,]*$/D', $value)) {
                    $numbers[] = $value;
                }
            }
            if ($started && $maximumY !== null) {
                break;
            }
        }

        return $numbers;
    }

    private function text(DOMXPath $xpath, DOMElement $node): string
    {
        $text = '';
        foreach ($xpath->query('.//w:t | .//w:br | .//w:tab', $node) as $part) {
            $text .= $part->localName === 't' ? $part->textContent : ' ';
        }

        return trim(preg_replace('/\s+/u', ' ', $text));
    }
}
