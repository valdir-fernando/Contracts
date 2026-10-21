<?php

namespace Tests\Feature;

use App\Infrastructure\Documents\SupplyContractTemplate;
use DOMDocument;
use Dompdf\Dompdf;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

class SupplyContractTemplateTest extends TestCase
{
    public static function closingLayouts(): array
    {
        return [[0, false, 0], [280, false, 0], [500, true, 0], [0, true, 120]];
    }

    #[DataProvider('closingLayouts')]
    public function test_all_signatures_and_witnesses_render_on_one_page_with_clear_header(int $spacer, bool $longNames, int $productCount): void
    {
        $template = new SupplyContractTemplate;
        $zip = new ZipArchive;
        $zip->open($template->path(), ZipArchive::RDONLY);
        try {
            $document = new DOMDocument;
            $document->loadXML($zip->getFromName('word/document.xml'));
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $source = '';
            foreach ($xpath->query('//w:p') as $paragraph) {
                $source .= $paragraph->textContent."\n";
            }
            preg_match_all('/#[\p{L}_][\p{L}\p{N}_]*/u', $source, $markers);
        } finally {
            $zip->close();
        }
        $fields = array_fill_keys($markers[0], 'Dado fictício');
        $fields['#ADM'] = '2025/2028';
        $fields['#FUNDO'] = 'MUNICIPIO DE NIQUELÂNDIA';
        $fields['#NOME_GESTOR'] = 'GESTOR ASSINANTE'.($longNames ? str_repeat(' NOME EXTENSO', 18) : '');
        $fields['#FORNECEDOR'] = 'EMPRESA ASSINANTE'.($longNames ? str_repeat(' RAZÃO SOCIAL', 18) : '');
        $products = [];
        $budgets = [];
        for ($item = 1; $item <= $productCount; $item++) {
            $products[] = ['item' => (string) $item, 'code' => (string) $item, 'description' => 'MATERIAL '.$item.' '.str_repeat('Descrição completa do produto. ', 8),
                'brand' => 'Marca', 'unit' => 'UND', 'quantity' => '1.0000', 'unit_price' => '10.0000', 'total' => '10.0000'];
        }
        for ($ficha = 1; $ficha <= ($productCount > 0 ? 35 : 0); $ficha++) {
            $budgets[] = ['ficha' => (string) $ficha, 'allocation' => '01.02.03.4.5.'.$ficha, 'amount' => '10.0000'];
        }
        $html = $template->render($fields, $products, $budgets, '1200.0000');
        $html = str_replace('<div class="signature-block">', '<div style="height:'.$spacer.'pt"></div><div class="signature-block">', $html);
        $pdf = new Dompdf(['isRemoteEnabled' => false, 'isPhpEnabled' => false]);
        $texts = [];
        $headers = [];
        $signatureSpaces = [];
        $printed = [];
        $pdf->setCallbacks([['event' => 'begin_frame', 'f' => function ($frame, $canvas) use (&$texts, &$headers, &$printed, &$signatureSpaces) {
            $node = $frame->get_node();
            if ($node instanceof \DOMElement && $node->tagName === 'header') {
                $headers[] = $frame->get_border_box();
            }
            if ($node instanceof \DOMElement && $node->getAttribute('class') === 'signature-space') {
                $signatureSpaces[] = $frame->get_border_box();
            }
            if (! $node instanceof \DOMText || trim($node->textContent) === '') {
                return;
            }
            $printed[] = $node->textContent;
            for ($parent = $node->parentNode; $parent instanceof \DOMElement; $parent = $parent->parentNode) {
                if ($parent->getAttribute('class') === 'signature-block') {
                    $texts[] = ['text' => $node->textContent, 'page' => $canvas->get_page_number(), 'box' => $frame->get_border_box(),
                        'alignment' => $frame->get_style()->text_align];
                    break;
                }
            }
        }]]);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();
        $this->assertNotEmpty($texts);
        $this->assertCount(1, array_unique(array_column($texts, 'page')), 'Assinaturas, data e testemunhas devem permanecer juntas.');
        $this->assertSame($pdf->getCanvas()->get_page_count(), $texts[0]['page'], 'Não deve haver página vazia depois das assinaturas.');
        $closing = implode(' ', array_column($texts, 'text'));
        foreach (['CONTRATANTE', 'CONTRATADA', 'GESTOR ASSINANTE', 'EMPRESA ASSINANTE', 'TESTEMUNHAS:', '1.', '2.', 'NIQUELÂNDIA-GO'] as $text) {
            $this->assertStringContainsString($text, $closing);
        }
        $this->assertSame(2, substr_count($closing, 'CPF ___'));
        $date = collect($texts)->first(fn ($text) => str_contains($text['text'], 'NIQUELÂNDIA-GO'));
        $this->assertSame('right', $date['alignment']);
        $this->assertCount(2, $signatureSpaces);
        foreach ($signatureSpaces as $box) {
            $this->assertGreaterThanOrEqual(60, $box[3], 'Reservar altura para a assinatura digital de cada parte.');
        }
        $witnesses = array_values(array_filter($texts, fn ($text) => preg_match('/^[12]\.\s*_/', $text['text'])));
        $documents = array_values(array_filter($texts, fn ($text) => str_starts_with($text['text'], 'CPF ___')));
        $this->assertCount(2, $witnesses);
        $this->assertCount(2, $documents);
        foreach ($witnesses as $index => $witness) {
            $gap = $documents[$index]['box'][1] - ($witness['box'][1] + $witness['box'][3]);
            $this->assertGreaterThanOrEqual(0, $gap);
            $this->assertLessThan(10, $gap, 'CPF próximo da linha da própria testemunha.');
        }
        $this->assertGreaterThanOrEqual(38, $witnesses[1]['box'][1] - ($documents[0]['box'][1] + $documents[0]['box'][3]));
        if ($productCount > 0) {
            $all = implode(' ', $printed);
            $this->assertStringContainsString('MATERIAL 120', $all);
            $this->assertStringContainsString('01.02.03.4.5.35', $all);
            $this->assertGreaterThan(1, substr_count($all, 'Discriminação'), 'As colunas devem se repetir nas páginas de produtos.');
        }
        foreach ($texts as $text) {
            $this->assertGreaterThanOrEqual(110, $text['box'][1]);
            $this->assertLessThan(752, $text['box'][1] + $text['box'][3], 'Texto não pode invadir o rodapé.');
        }
        $this->assertNotEmpty($headers);
        foreach ($headers as $box) {
            $this->assertEqualsWithDelta(100.6, $box[1] + $box[3], .1);
        }
    }

    public static function models(): array
    {
        return [['supply'], ['property-lease'], ['services']];
    }

    #[DataProvider('models')]
    public function test_all_body_paragraphs_preserve_the_model_text_including_split_runs_and_punctuation(string $model): void
    {
        $template = new SupplyContractTemplate;
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($template->path($model), ZipArchive::RDONLY));
        try {
            $source = new DOMDocument;
            $this->assertTrue($source->loadXML($zip->getFromName('word/document.xml')));
        } finally {
            $zip->close();
        }
        $xpath = new DOMXPath($source);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $paragraphs = [];
        $fields = ['#ADM' => '2025/2028', '#FUNDO' => 'Fundo de teste', '#CONTRATO' => '001/26', '#PROCESSO' => '2026001'];
        foreach ($xpath->query('//w:p') as $node) {
            preg_match_all('/#[\p{L}_][\p{L}\p{N}_]*/u', $node->textContent, $markers);
            foreach ($markers[0] as $marker) {
                $fields[$marker] ??= 'Valor fictício';
            }
        }
        foreach ($xpath->query('/w:document/w:body/w:p') as $node) {
            $text = '';
            foreach ($xpath->query('.//w:t|.//w:br|.//w:tab[parent::w:r]', $node) as $part) {
                $text .= $part->localName === 't' ? $part->textContent : ' ';
            }
            preg_match_all('/#[\p{L}_][\p{L}\p{N}_]*/u', $text, $matches);
            foreach ($matches[0] as $marker) {
                $fields[$marker] = 'Valor fictício';
            }
            if (trim($text) !== '' && trim($text) !== '#FICHA' && ! str_contains($text, '#DOTAÇÃO') && ! str_contains($text, '#Dotacao')) {
                $paragraphs[] = $text;
            }
        }
        $html = $template->render($fields, [], [], '0.0000', $model);
        $rendered = new DOMDocument;
        @$rendered->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $normalize = static fn ($value) => trim(preg_replace('/\s+/u', ' ', $value));
        $actual = [];
        foreach ($rendered->getElementsByTagName('p') as $node) {
            $actual[] = $normalize($node->textContent);
        }
        $this->assertGreaterThan(100, count($paragraphs));
        foreach ($paragraphs as $text) {
            $this->assertContains($normalize(strtr($text, $fields)), $actual);
        }
    }
}
