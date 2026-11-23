<?php

namespace App\Infrastructure\Atas;

use DOMDocument;
use DOMElement;
use DOMNode;
use RuntimeException;
use ZipArchive;

/** Converts only the local ATA template into editable WordprocessingML. */
final class AtaWordDocument
{
    private const NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public function productTables(array $fields): DOMDocument
    {
        $html = view('atas.document-products', ['fields' => $fields])->render();
        $dom = new DOMDocument;
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $body = $dom->getElementsByTagName('body')->item(0);
        $word = new DOMDocument;
        if (! $body || ! $word->loadXML('<w:body xmlns:w="'.self::NS.'">'.$this->blocks($body).'</w:body>', LIBXML_NONET)) {
            throw new RuntimeException('Não foi possível gerar a tabela de produtos.');
        }

        return $word;
    }

    public function render(string $html): string
    {
        $dom = new DOMDocument;
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $header = $dom->getElementsByTagName('header')->item(0);
        $footer = $dom->getElementsByTagName('footer')->item(0);
        $body = $dom->getElementsByTagName('body')->item(0);
        if (! $header || ! $footer || ! $body) {
            throw new RuntimeException('Modelo de ATA incompleto.');
        }
        $parts = [];
        foreach (['header' => $header, 'footer' => $footer] as $name => $node) {
            $image = $node->getElementsByTagName('img')->item(0);
            $data = $image ? base64_decode(substr($image->getAttribute('src'), strlen('data:image/png;base64,')), true) : false;
            if ($data === false) {
                throw new RuntimeException('Imagem institucional inválida.');
            }
            $parts["word/media/{$name}.png"] = $data;
            $size = getimagesizefromstring($data);
            if ($size === false) {
                throw new RuntimeException('Imagem institucional inválida.');
            }
            $imageXml = $this->image($name === 'header' ? 162.75 : 512.9, $name === 'header' ? 51.95 : 512.9 * $size[1] / $size[0], $name === 'header');
            $text = $name === 'header' ? $this->blocks($node) : '';
            $tag = $name === 'header' ? 'hdr' : 'ftr';
            $parts["word/{$name}1.xml"] = $this->xml('<w:'.$tag.' xmlns:w="'.self::NS.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'.$imageXml.$text.'</w:'.$tag.'>');
            $parts["word/_rels/{$name}1.xml.rels"] = $this->relationships([
                ['image', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image', "media/{$name}.png"],
            ]);
        }
        $section = '<w:sectPr><w:headerReference w:type="default" r:id="header"/><w:footerReference w:type="default" r:id="footer"/>'
            .'<w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1960" w:right="566" w:bottom="1620" w:left="1080" w:header="440" w:footer="180"/></w:sectPr>';
        $parts['word/document.xml'] = $this->xml('<w:document xmlns:w="'.self::NS.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:body>'.$this->blocks($body).$section.'</w:body></w:document>');
        $parts['word/styles.xml'] = $this->xml('<w:styles xmlns:w="'.self::NS.'"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/><w:sz w:val="20"/><w:lang w:val="pt-BR"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="0" w:line="230" w:lineRule="exact"/></w:pPr></w:pPrDefault></w:docDefaults></w:styles>');
        $parts['_rels/.rels'] = $this->relationships([['document', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument', 'word/document.xml']]);
        $parts['word/_rels/document.xml.rels'] = $this->relationships(array_map(
            fn ($name, $type, $target) => [$name, 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/'.$type, $target],
            ['header', 'footer', 'styles'], ['header', 'footer', 'styles'], ['header1.xml', 'footer1.xml', 'styles.xml'],
        ));
        $types = '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="png" ContentType="image/png"/>';
        foreach (['document' => 'document.main', 'header1' => 'header', 'footer1' => 'footer', 'styles' => 'styles'] as $name => $type) {
            $types .= '<Override PartName="/word/'.$name.'.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.'.$type.'+xml"/>';
        }
        $parts['[Content_Types].xml'] = $this->xml('<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'.$types.'</Types>');

        return $this->archive($parts);
    }

    private function blocks(DOMNode $parent): string
    {
        $xml = '';
        $witnesses = false;
        foreach ($parent->childNodes as $node) {
            if (! $node instanceof DOMElement || in_array($node->tagName, ['header', 'footer', 'img'], true)) {
                continue;
            }
            $witnesses = $witnesses || $node->getAttribute('class') === 'witnesses';
            if ($witnesses && $node->nextElementSibling) {
                $node->setAttribute('class', $node->getAttribute('class').' keep-next');
            }
            if ($node->tagName === 'table') {
                $xml .= $this->table($node);
            } elseif ($node->getAttribute('class') === 'separator') {
                $xml .= '<w:p><w:pPr><w:pBdr><w:top w:val="single" w:sz="6"/></w:pBdr><w:spacing w:line="1" w:lineRule="exact"/></w:pPr></w:p>';
            } elseif (in_array($node->tagName, ['p', 'h1', 'h2', 'li'], true)
                || ($node->tagName === 'div' && ! $node->getElementsByTagName('div')->length)) {
                if (trim($node->textContent) !== '') {
                    $xml .= $this->paragraph($node);
                }
            } else {
                $xml .= $this->blocks($node);
            }
        }

        return $xml;
    }

    private function paragraph(DOMElement $node, bool $small = false): string
    {
        $class = explode(' ', $node->getAttribute('class'));
        $align = in_array('center', $class, true) ? 'center' : (count(array_intersect($class, ['number', 'closing-date', 'details', 'fund', 'administration'])) ? 'right' : 'both');
        if ($node->tagName === 'th') {
            $align = 'center';
        }
        if (in_array('left', $class, true)) {
            $align = 'left';
        }
        if (in_array('description', $class, true)) {
            $align = 'left';
        }
        $properties = '<w:jc w:val="'.$align.'"/>';
        $before = in_array('spaced', $class, true) ? 230 : 0;
        $properties .= '<w:spacing w:before="'.$before.'" w:after="0"/>';
        $size = $small ? 16 : ($node->tagName === 'h1' ? 24 : 20);
        $font = 'Arial';
        if (in_array('closing-date', $class, true)) {
            $properties .= '<w:spacing w:before="460" w:after="460"/>';
            $size = 22;
        }
        if (in_array('witnesses', $class, true)) {
            $properties .= '<w:spacing w:before="230"/>';
        }
        if ($small) {
            $properties .= '<w:spacing w:line="190" w:lineRule="exact"/>';
        }
        if (count(array_intersect($class, ['fund', 'administration', 'details']))) {
            $properties .= '<w:ind w:left="3340"/>';
            if (in_array('fund', $class, true)) {
                $size = 28;
                $properties .= '<w:spacing w:line="300" w:lineRule="exact"/>';
            } elseif (in_array('administration', $class, true)) {
                $size = 24;
                $font = 'Times New Roman';
                $properties .= '<w:spacing w:line="280" w:lineRule="exact" w:after="260"/>';
            } else {
                $size = 18;
                $properties .= '<w:spacing w:line="200" w:lineRule="exact"/>';
            }
        }
        if (in_array('supplier', $class, true)) {
            $properties .= '<w:pBdr><w:top w:val="single" w:sz="6" w:space="1"/><w:bottom w:val="single" w:sz="6" w:space="1"/></w:pBdr>';
        }
        if (in_array('subclause', $class, true) || in_array('subclause-deep', $class, true)) {
            $properties .= '<w:ind w:left="'.(in_array('subclause-deep', $class, true) ? 1134 : 567).'"/>';
        }
        if ($node->tagName === 'li') {
            $properties .= '<w:ind w:left="600" w:hanging="180"/>';
        }
        if (in_array($node->tagName, ['h1', 'h2'], true) || in_array('witnesses', $class, true) || in_array('keep-next', $class, true)
            || preg_match('/^[12]\. _/u', $node->textContent)) {
            $properties .= '<w:keepNext/>';
        }

        $runs = $this->runs($node, ['size' => $size, 'font' => $font, 'bold' => $node->tagName === 'th' || in_array('fund', $class, true),
            'color' => count(array_intersect($class, ['fund', 'administration'])) ? '404040' : '000000']);
        if ($node->tagName === 'li') {
            $runs = '<w:r><w:t xml:space="preserve">• </w:t></w:r>'.$runs;
        }
        if (in_array('details', $class, true)) {
            $runs .= '<w:r><w:rPr><w:sz w:val="18"/></w:rPr><w:t xml:space="preserve"> | Pág. </w:t></w:r><w:fldSimple w:instr="PAGE"><w:r><w:rPr><w:b/><w:sz w:val="18"/></w:rPr><w:t>1</w:t></w:r></w:fldSimple><w:r><w:rPr><w:sz w:val="18"/></w:rPr><w:t xml:space="preserve"> de </w:t></w:r><w:fldSimple w:instr="NUMPAGES"><w:r><w:rPr><w:b/><w:sz w:val="18"/></w:rPr><w:t>1</w:t></w:r></w:fldSimple>';
        }

        $spacer = in_array('signature-line', $class, true) ? '<w:p><w:pPr><w:spacing w:line="1240" w:lineRule="exact"/></w:pPr></w:p>' : '';

        return $spacer.'<w:p><w:pPr>'.$properties.'</w:pPr>'.$runs.'</w:p>';
    }

    private function runs(DOMNode $parent, array $style = []): string
    {
        $xml = '';
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement) {
                if ($node->tagName === 'br') {
                    $xml .= '<w:r><w:br/></w:r>';
                } else {
                    $childStyle = $style;
                    if (in_array($node->tagName, ['strong', 'b', 'th'], true)) {
                        $childStyle['bold'] = true;
                    }
                    if (in_array($node->tagName, ['em', 'i'], true)) {
                        $childStyle['italic'] = true;
                    }
                    if ($node->tagName === 'u') {
                        $childStyle['underline'] = true;
                    }
                    $xml .= $this->runs($node, $childStyle);
                }
            } elseif ($node->nodeType === XML_TEXT_NODE) {
                $text = preg_replace('/\s+/u', ' ', $node->textContent);
                $properties = '<w:rFonts w:ascii="'.($style['font'] ?? 'Arial').'" w:hAnsi="'.($style['font'] ?? 'Arial').'"/><w:sz w:val="'.($style['size'] ?? 20).'"/>';
                $properties .= ! empty($style['bold']) ? '<w:b/>' : '';
                $properties .= ! empty($style['italic']) ? '<w:i/>' : '';
                $properties .= ! empty($style['underline']) ? '<w:u w:val="single"/>' : '';
                $properties .= '<w:color w:val="'.($style['color'] ?? '000000').'"/>';
                $xml .= '<w:r><w:rPr>'.$properties.'</w:rPr><w:t xml:space="preserve">'.htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</w:t></w:r>';
            }
        }

        return $xml;
    }

    private function table(DOMElement $table): string
    {
        $classes = explode(' ', $table->getAttribute('class'));
        $products = in_array('products', $classes, true);
        $parallel = in_array('parallel-item', $classes, true);
        $borders = '';
        if ($products) {
            foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $side) {
                $borders .= '<w:'.$side.' w:val="single" w:sz="4"/>';
            }
        }
        $grid = '';
        $firstRow = $table->getElementsByTagName('tr')->item(0);
        $widths = [];
        if ($firstRow) {
            foreach ($firstRow->childNodes as $cell) {
                if (! $cell instanceof DOMElement) {
                    continue;
                }
                $percent = preg_match('/width:\s*(\d+)%/', $cell->getAttribute('style'), $match) ? (int) $match[1] : null;
                $widths[] = $percent;
            }
        }
        $remaining = max(1, 100 - array_sum($widths));
        $unspecified = count(array_filter($widths, fn ($width) => $width === null));
        foreach ($widths as $percent) {
            $grid .= '<w:gridCol w:w="'.(int) round(10260 * ($percent ?? ($remaining / max(1, $unspecified))) / 100).'"/>';
        }
        $gap = $parallel && ! in_array('parallel-first', $classes, true) ? 60 : 230;
        $xml = $products ? '<w:p><w:pPr><w:spacing w:line="'.$gap.'" w:lineRule="exact"/></w:pPr></w:p>' : '';
        $xml .= '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/><w:tblInd w:w="40" w:type="dxa"/><w:tblBorders>'.$borders.'</w:tblBorders><w:tblLayout w:type="fixed"/><w:tblCellMar><w:top w:w="40" w:type="dxa"/><w:left w:w="40" w:type="dxa"/><w:bottom w:w="40" w:type="dxa"/><w:right w:w="40" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>'.$grid.'</w:tblGrid>';
        foreach ($table->getElementsByTagName('tr') as $row) {
            $xml .= '<w:tr><w:trPr><w:cantSplit/>'.($row->parentNode->nodeName === 'thead' ? '<w:tblHeader/>' : '').'</w:trPr>';
            foreach ($row->childNodes as $cell) {
                if (! $cell instanceof DOMElement) {
                    continue;
                }
                $span = max(1, (int) $cell->getAttribute('colspan'));
                if ($parallel && ($row->parentNode->nodeName === 'thead' || $row->getAttribute('class') === 'item-values')) {
                    $cell->setAttribute('class', $cell->getAttribute('class').' keep-next');
                }
                $content = $cell->getElementsByTagName('p')->length ? $this->blocks($cell) : $this->paragraph($cell, $products);
                $shading = $parallel && $cell->tagName === 'th' ? '<w:shd w:val="clear" w:fill="E7E6E6"/>' : '';
                $xml .= '<w:tc><w:tcPr><w:gridSpan w:val="'.$span.'"/>'.$shading.'<w:vAlign w:val="top"/></w:tcPr>'.$content.'</w:tc>';
            }
            $xml .= '</w:tr>';
        }

        return $xml.'</w:tbl><w:p><w:pPr><w:spacing w:line="1" w:lineRule="exact"/></w:pPr></w:p>';
    }

    private function image(float $width, float $height, bool $floating): string
    {
        $cx = (int) round($width * 12700);
        $cy = (int) round($height * 12700);

        $tag = 'anchor';
        $position = ' distT="0" distB="0" distL="0" distR="0" simplePos="0" relativeHeight="0" behindDoc="1" locked="0" layoutInCell="1" allowOverlap="1"';
        $anchor = '<wp:simplePos x="0" y="0"/><wp:positionH relativeFrom="column"><wp:posOffset>0</wp:posOffset></wp:positionH><wp:positionV relativeFrom="'.($floating ? 'paragraph' : 'page').'"><wp:posOffset>'.($floating ? 0 : (int) round((841.89 - 9 - $height) * 12700)).'</wp:posOffset></wp:positionV>';
        $wrap = '<wp:wrapNone/>';
        $paragraph = '<w:pPr><w:spacing w:line="1" w:lineRule="exact" w:after="0"/></w:pPr>';

        return '<w:p>'.$paragraph.'<w:r><w:drawing><wp:'.$tag.' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'.$position.'>'.$anchor.'<wp:extent cx="'.$cx.'" cy="'.$cy.'"/>'.$wrap.'<wp:docPr id="'.($floating ? 1 : 2).'" name="Institucional"/><a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:nvPicPr><pic:cNvPr id="0" name="Institucional"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="image"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$cx.'" cy="'.$cy.'"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:'.$tag.'></w:drawing></w:r></w:p>';
    }

    private function relationships(array $items): string
    {
        return $this->xml('<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.implode('', array_map(
            fn ($item) => '<Relationship Id="'.$item[0].'" Type="'.$item[1].'" Target="'.$item[2].'"/>', $items,
        )).'</Relationships>');
    }

    private function xml(string $xml): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.$xml;
    }

    private function archive(array $parts): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ata-word-');
        if ($path === false) {
            throw new RuntimeException('Não foi possível preparar o Word.');
        }
        try {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Não foi possível criar o Word.');
            }
            foreach ($parts as $name => $content) {
                if (! $zip->addFromString($name, $content)) {
                    throw new RuntimeException('Não foi possível escrever o Word.');
                }
            }
            if (! $zip->close() || ($bytes = file_get_contents($path)) === false) {
                throw new RuntimeException('Não foi possível concluir o Word.');
            }

            return $bytes;
        } finally {
            unlink($path);
        }
    }
}
