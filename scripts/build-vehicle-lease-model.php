<?php

// Standalone document drafting utility. No Laravel bootstrap, database or runtime model registration.
require dirname(__DIR__).'/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$root = dirname(__DIR__);
$source = $root.'/resources/documents/contracts/services.docx';
$markdown = $root.'/docs/modelo-contrato-locacao-veiculo.md';
$output = $root.'/build/vehicle-lease-contract-model';
$target = $root.'/Reference/Modelos_Doc/Contratos/Modelo_Loc_Veiculo.docx';
$originalHash = hash_file('sha256', $source);
$word = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
$text = file_get_contents($markdown);
if (! is_string($text) || ! mb_check_encoding($text, 'UTF-8')) {
    throw new RuntimeException('Texto da minuta inválido.');
}
if (! is_dir($output) && ! mkdir($output, 0750, true)) {
    throw new RuntimeException('Não foi possível preparar a saída.');
}

// Parse only headings, single-line paragraphs and simple tables authored in this repository.
$blocks = [];
$table = [];
$flushTable = static function () use (&$table, &$blocks): void {
    if ($table !== []) {
        $blocks[] = ['type' => 'table', 'rows' => $table];
        $table = [];
    }
};
foreach (preg_split('/\R/u', $text) as $line) {
    $line = trim($line);
    if (str_starts_with($line, '|')) {
        $cells = array_map('trim', explode('|', trim($line, '|')));
        if (! preg_match('/^\|[\s|:\-]+\|$/', $line)) {
            $table[] = $cells;
        }

        continue;
    }
    $flushTable();
    if ($line === '') {
        continue;
    }
    $blocks[] = ['type' => str_starts_with($line, '## ') ? 'h2' : (str_starts_with($line, '# ') ? 'h1' : 'p'),
        'text' => preg_replace('/^#{1,2} /', '', $line)];
}
$flushTable();

if (! copy($source, $target)) {
    throw new RuntimeException('Não foi possível criar o Word.');
}
$zip = new ZipArchive;
if ($zip->open($target) !== true) {
    throw new RuntimeException('Não foi possível abrir o Word.');
}
$document = new DOMDocument;
if (! $document->loadXML($zip->getFromName('word/document.xml'), LIBXML_NONET)) {
    throw new RuntimeException('Estrutura do modelo original inválida.');
}
$xpath = new DOMXPath($document);
$xpath->registerNamespace('w', $word);
$body = $xpath->query('/w:document/w:body')->item(0);
$section = $xpath->query('w:sectPr', $body)->item(0)->cloneNode(true);
while ($body->firstChild) {
    $body->removeChild($body->firstChild);
}
$element = static function (DOMNode $parent, string $name, array $attributes = []) use ($document, $word): DOMElement {
    $node = $document->createElementNS($word, 'w:'.$name);
    foreach ($attributes as $attribute => $value) {
        $node->setAttributeNS($word, 'w:'.$attribute, (string) $value);
    }
    $parent->appendChild($node);

    return $node;
};
$paragraph = static function (DOMNode $parent, string $value, string $kind = 'p') use ($element, $document): void {
    $p = $element($parent, 'p');
    $properties = $element($p, 'pPr');
    $heading = in_array($kind, ['h1', 'h2', 'th'], true);
    $signature = str_starts_with($value, 'NIQUELÂNDIA-GO,') || preg_match('/^(CONTRATANTE \/|CONTRATADA \/|TESTEMUNHA [12]:)/u', $value);
    $element($properties, 'spacing', ['before' => $kind === 'h2' ? 160 : 0, 'after' => $signature ? 35 : 90, 'line' => $signature ? 240 : 264, 'lineRule' => 'auto']);
    $element($properties, 'jc', ['val' => $kind === 'h1' ? 'center' : ($kind === 'p' ? 'both' : 'left')]);
    $element($properties, 'widowControl');
    $element($properties, 'keepLines');
    if ($heading || ($signature && ! str_starts_with($value, 'TESTEMUNHA 2:'))) {
        $element($properties, 'keepNext');
    }
    if ($kind === 'h2' && str_starts_with($value, 'ANEXO ')) {
        $element($properties, 'pageBreakBefore');
    }
    $run = $element($p, 'r');
    $runProperties = $element($run, 'rPr');
    $element($runProperties, 'rFonts', ['ascii' => 'Arial', 'hAnsi' => 'Arial']);
    $element($runProperties, 'sz', ['val' => $kind === 'h1' ? 24 : ($signature || in_array($kind, ['td', 'th'], true) ? 18 : 21)]);
    $element($runProperties, 'lang', ['val' => 'pt-BR']);
    if ($heading) {
        $element($runProperties, 'b');
    }
    $content = $element($run, 't');
    $content->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
    $content->appendChild($document->createTextNode($value));
};
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$htmlBody = '';
$tableCount = 0;
$signatureOpen = false;
foreach ($blocks as $block) {
    if ($block['type'] !== 'table') {
        if ($signatureOpen && $block['type'] === 'h2') {
            $htmlBody .= '</div>';
            $signatureOpen = false;
        }
        if (str_starts_with($block['text'], 'NIQUELÂNDIA-GO,')) {
            $htmlBody .= '<div class="signature-block">';
            $signatureOpen = true;
        }
        $paragraph($body, $block['text'], $block['type']);
        $class = $block['type'] === 'h2' && str_starts_with($block['text'], 'ANEXO ') ? ' class="annex"' : '';
        $htmlBody .= '<'.$block['type'].$class.'>'.$escape($block['text']).'</'.$block['type'].'>';

        continue;
    }
    $tableCount++;
    $tbl = $element($body, 'tbl');
    $properties = $element($tbl, 'tblPr');
    $element($properties, 'tblW', ['w' => 10260, 'type' => 'dxa']);
    $element($properties, 'tblLayout', ['type' => 'fixed']);
    $borders = $element($properties, 'tblBorders');
    foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $border) {
        $element($borders, $border, ['val' => 'single', 'sz' => 4, 'color' => '64748B']);
    }
    $columns = count($block['rows'][0]);
    $weights = $columns === 7 ? [0.04, 0.34, 0.08, 0.07, 0.15, 0.16, 0.16] : [0.30, 0.70];
    $grid = $element($tbl, 'tblGrid');
    foreach ($weights as $weight) {
        $element($grid, 'gridCol', ['w' => (int) round(10260 * $weight)]);
    }
    $htmlBody .= '<table><thead>';
    foreach ($block['rows'] as $index => $row) {
        if (count($row) !== $columns) {
            throw new RuntimeException('Tabela com número inconsistente de colunas.');
        }
        $tr = $element($tbl, 'tr');
        $trProperties = $element($tr, 'trPr');
        $element($trProperties, 'cantSplit');
        if ($index === 0) {
            $element($trProperties, 'tblHeader');
        }
        $htmlBody .= '<tr>';
        foreach ($row as $column => $cell) {
            $tc = $element($tr, 'tc');
            $tcProperties = $element($tc, 'tcPr');
            $element($tcProperties, 'tcW', ['w' => (int) round(10260 * $weights[$column]), 'type' => 'dxa']);
            if ($index === 0) {
                $element($tcProperties, 'shd', ['fill' => 'E9EFF7']);
            }
            $paragraph($tc, $cell, $index === 0 ? 'th' : 'td');
            $tag = $index === 0 ? 'th' : 'td';
            $htmlBody .= '<'.$tag.'>'.$escape($cell).'</'.$tag.'>';
        }
        $htmlBody .= '</tr>';
        if ($index === 0) {
            $htmlBody .= '</thead><tbody>';
        }
    }
    $htmlBody .= '</tbody></table>';
    $paragraph($body, '');
}
$body->appendChild($section);
$zip->addFromString('word/document.xml', $document->saveXML());

// Refresh page fields when opened in Word; never leave the source template's cached page total.
foreach (['word/header1.xml', 'word/footer1.xml'] as $part) {
    $partDocument = new DOMDocument;
    $partDocument->loadXML($zip->getFromName($part), LIBXML_NONET);
    $partXPath = new DOMXPath($partDocument);
    $partXPath->registerNamespace('w', $word);
    foreach ($partXPath->query('//w:fldSimple') as $field) {
        $field->setAttributeNS($word, 'w:dirty', 'true');
        if (str_contains($field->getAttributeNS($word, 'instr'), 'NUMPAGES')) {
            foreach ($partXPath->query('.//w:t', $field) as $cached) {
                $cached->nodeValue = '';
            }
        }
    }
    $clearPageTotal = false;
    foreach ($partXPath->query('//w:fldChar | //w:instrText | //w:t') as $fieldNode) {
        if ($fieldNode->localName === 'fldChar') {
            $fieldNode->setAttributeNS($word, 'w:dirty', 'true');
            if ($fieldNode->getAttributeNS($word, 'fldCharType') === 'end') {
                $clearPageTotal = false;
            }
        } elseif ($fieldNode->localName === 'instrText' && str_contains($fieldNode->textContent, 'NUMPAGES')) {
            $clearPageTotal = true;
        } elseif ($clearPageTotal && $fieldNode->localName === 't') {
            $fieldNode->nodeValue = '';
        }
    }
    $zip->addFromString($part, $partDocument->saveXML());
}
$settings = new DOMDocument;
$settings->loadXML($zip->getFromName('word/settings.xml'), LIBXML_NONET);
$settingsXPath = new DOMXPath($settings);
$settingsXPath->registerNamespace('w', $word);
$update = $settingsXPath->query('/w:settings/w:updateFields')->item(0);
if (! $update) {
    $update = $settings->createElementNS($word, 'w:updateFields');
    $settings->documentElement->appendChild($update);
}
$update->setAttributeNS($word, 'w:val', 'true');
$zip->addFromString('word/settings.xml', $settings->saveXML());
$zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>Minuta — Contrato de Locação de Veículo</dc:title><dc:creator>Contracts</dc:creator><dc:subject>Minuta para revisão jurídica — Lei 14.133/2021</dc:subject><cp:lastModifiedBy>Contracts</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">2026-10-29T00:00:00Z</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">2026-10-29T00:00:00Z</dcterms:modified></cp:coreProperties>');
$logo = $zip->getFromName('word/media/image1.png');
$footer = $zip->getFromName('word/media/image2.png');
$zip->close();
if (! is_string($logo) || ! is_string($footer)) {
    throw new RuntimeException('Imagens institucionais ausentes.');
}

$html = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Minuta — Locação de Veículo</title><style>
@page { size:A4; margin:110pt 30pt 90pt 51pt; }
body { margin:0; font-family:DejaVu Sans,sans-serif; font-size:10pt; line-height:1.25; color:#111; }
header { position:fixed; top:-90pt; left:0; right:0; height:80pt; border-bottom:.6pt solid #12377a; }
header img { width:205pt; height:auto; }
.fund { position:absolute; right:0; top:24pt; width:280pt; text-align:right; font-size:11pt; font-weight:bold; }
.reference { position:absolute; bottom:5pt; right:0; font-size:8pt; }
footer { position:fixed; bottom:-75pt; left:0; right:0; height:75pt; }
footer img { width:100%; height:auto; }
.administration { position:absolute; left:63%; top:64%; width:18%; height:22%; background:white; color:#082b87; font-size:8pt; text-align:center; line-height:13pt; }
h1 { font-size:12pt; text-align:center; margin:0 0 8pt; }
h2 { font-size:10.5pt; margin:10pt 0 5pt; page-break-after:avoid; }
p { margin:0 0 6pt; text-align:left; orphans:3; widows:3; overflow-wrap:anywhere; page-break-inside:avoid; }
table { width:100%; border-collapse:collapse; table-layout:fixed; margin:6pt 0 10pt; font-size:8pt; }
th,td { border:.5pt solid #64748b; padding:4pt; vertical-align:top; word-wrap:break-word; }
th { background:#e9eff7; text-align:left; } thead { display:table-header-group; } tr { page-break-inside:avoid; }
.annex { page-break-before:always; }
.signature-block { page-break-inside:avoid; margin-top:5pt; }
.signature-block p { font-size:9pt; line-height:1.15; margin-bottom:3pt; text-align:left; }
</style></head><body><header><img alt="Prefeitura de Niquelândia" src="data:image/png;base64,'.base64_encode($logo).'"><div class="fund">#FUNDO</div><div class="reference">Contrato: #CONTRATO — Processo: #PROCESSO</div></header><footer><img alt="Rodapé institucional" src="data:image/png;base64,'.base64_encode($footer).'"><div class="administration">Gestão #ADM</div></footer>'.$htmlBody.'</body></html>';
file_put_contents($output.'/Modelo_Locacao_Veiculo.html', $html);
$options = new Options;
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isPhpEnabled', false);
$renderer = new Dompdf($options);
$renderer->setPaper('A4');
$renderer->loadHtml($html, 'UTF-8');
$renderer->render();
$renderer->getCanvas()->page_text(425, 829, 'Minuta | {PAGE_NUM} / {PAGE_COUNT}', null, 8, [0.2, 0.2, 0.2]);
file_put_contents($output.'/Modelo_Locacao_Veiculo.pdf', $renderer->output());

// Artifact checks use only the generated package and source text.
$check = new ZipArchive;
if ($check->open($target, ZipArchive::CHECKCONS) !== true) {
    throw new RuntimeException('Pacote Word inconsistente.');
}
for ($i = 0; $i < $check->numFiles; $i++) {
    $name = $check->getNameIndex($i);
    if (str_ends_with($name, '.xml') || str_ends_with($name, '.rels')) {
        $xmlCheck = new DOMDocument;
        if (! $xmlCheck->loadXML($check->getFromIndex($i), LIBXML_NONET)) {
            throw new RuntimeException('XML inválido: '.$name);
        }
    }
}
$check->close();
if ($tableCount !== 3 || count(array_filter($blocks, fn ($b) => $b['type'] === 'h2')) !== 24) {
    throw new RuntimeException('Estrutura incompleta da minuta.');
}
if (hash_file('sha256', $source) !== $originalHash) {
    throw new RuntimeException('O modelo de referência foi alterado.');
}
file_put_contents($output.'/validation.json', json_encode([
    'source_sha256' => $originalHash, 'markdown_sha256' => hash_file('sha256', $markdown),
    'docx_sha256' => hash_file('sha256', $target), 'pdf_sha256' => hash_file('sha256', $output.'/Modelo_Locacao_Veiculo.pdf'),
    'clauses' => 21, 'annexes' => 3, 'tables' => $tableCount, 'pdf_pages' => $renderer->getCanvas()->get_page_count(),
    'database_access' => false, 'runtime_integration' => false,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
echo $target.PHP_EOL.$output.'/Modelo_Locacao_Veiculo.pdf'.PHP_EOL.'Páginas PDF: '.$renderer->getCanvas()->get_page_count().PHP_EOL;
