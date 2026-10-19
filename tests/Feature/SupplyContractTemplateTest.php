<?php

namespace Tests\Feature;

use App\Infrastructure\Documents\SupplyContractTemplate;
use DOMDocument;
use DOMXPath;
use Tests\TestCase;
use ZipArchive;

class SupplyContractTemplateTest extends TestCase
{
    public function test_all_body_paragraphs_preserve_the_model_text_including_split_runs_and_punctuation(): void
    {
        $template = new SupplyContractTemplate;
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($template->path(), ZipArchive::RDONLY));
        try {
            $source = new DOMDocument;
            $this->assertTrue($source->loadXML($zip->getFromName('word/document.xml')));
        } finally {
            $zip->close();
        }
        $xpath = new DOMXPath($source);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $paragraphs = [];
        $fields = ['#ADM' => '2025/2028'];
        foreach ($xpath->query('/w:document/w:body/w:p') as $node) {
            $text = '';
            foreach ($xpath->query('.//w:t|.//w:br|.//w:tab[parent::w:r]', $node) as $part) {
                $text .= $part->localName === 't' ? $part->textContent : ' ';
            }
            preg_match_all('/#[\p{L}_][\p{L}\p{N}_]*/u', $text, $matches);
            foreach ($matches[0] as $marker) {
                $fields[$marker] = 'Valor fictício';
            }
            if (trim($text) !== '' && trim($text) !== '#FICHA' && ! str_contains($text, '#DOTAÇÃO')) {
                $paragraphs[] = $text;
            }
        }
        $html = $template->render($fields, [], []);
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
