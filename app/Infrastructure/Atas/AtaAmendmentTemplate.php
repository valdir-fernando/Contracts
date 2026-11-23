<?php

namespace App\Infrastructure\Atas;

use App\Domain\Shared\DecimalAmount;
use App\Domain\Shared\MoneyWords;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use RuntimeException;
use ZipArchive;

final class AtaAmendmentTemplate extends AtaExtractTemplate
{
    private const NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const MODELS = [
        SaveAtaAmendment::EQUAL_QUANTITY => 'Aditivo_Prorrogacao_Igual_Periodo_Valor.docx',
        SaveAtaAmendment::EXTENSION => 'Aditivo_Prorrogacao_Saldo.docx',
    ];

    public function fingerprint(): string
    {
        return hash('sha256', implode('', array_map(fn ($file) => hash_file('sha256', resource_path('documents/atas/'.$file)), self::MODELS))
            .hash_file('sha256', resource_path(AtaWithAdmissionTemplate::PRODUCTS_VIEW)));
    }

    public function render(array $fields): string
    {
        $amendment = $fields['related']['amendment'] ?? null;
        abort_unless($amendment, 404, 'Aditivo ativo não encontrado nesta ATA.');
        $model = self::MODELS[$amendment['Tipo_ADT']] ?? null;
        abort_unless($model, 422, 'Este tipo de aditivo ainda não tem modelo para emissão.');
        $balance = $amendment['Tipo_ADT'] === SaveAtaAmendment::EXTENSION;
        abort_unless(in_array($amendment['VANTAJOSIDADE'], SaveAtaAmendment::ADVANTAGE_OPTIONS, true), 422,
            'Selecione e salve a Vantajosidade antes de emitir o aditivo.');
        $products = $balance ? $this->balanceRows($fields['related']['balances']) : $fields['related']['products'];
        abort_if($products === [], 422, $balance ? 'Informe e salve ao menos um saldo positivo antes de emitir o aditivo.' : 'O aditivo não tem produtos ativos para emissão.');
        abort_if(blank($amendment['Proc_ADT']) || blank($amendment['Período']) || blank($amendment['Data_ADT'])
            || blank($amendment['INICIO']) || blank($amendment['FINAL']), 422, 'Preencha os dados do aditivo antes de emitir o documento.');

        $tables = (new AtaWordDocument)->productTables([
            'presentation' => $fields['presentation'],
            'special_quantity_name' => $fields['special_quantity'],
            'products' => array_map(fn ($product) => [
                'item' => $product['ITEM'], 'code' => $product['COD_PROD'], 'description' => $product['DISCRIMINACAO'],
                'unit' => $product['UND'], 'brand' => $product['MARCA'], 'quantity' => $this->productDecimal($product['QTD']),
                'special_quantity' => $this->productDecimal($product['QTD_ESPECIAL']),
                'unit_price' => $this->productDecimal($product['VL_UNIT']), 'total' => $this->productDecimal($product['VL_TOTAL']),
            ], $products),
        ]);
        $tokens = [
            '#ADT' => str_pad((string) $amendment['ADT'], 3, '0', STR_PAD_LEFT),
            '#ATA' => $fields['number'], '#ADM' => 'ADM: '.$fields['administration'],
            '#PROC_ADT' => $amendment['Proc_ADT'], '#PROCESSO_ADITIVO' => $amendment['Proc_ADT'],
            '#VANTAJOSIDADE' => $amendment['VANTAJOSIDADE'],
            '#PROC_ATA' => $fields['process'], '#LICITAÇÃO' => $fields['bidding'], '#LICIT' => $fields['bidding'],
            '#N_LICIT' => $fields['bidding_number'], '#FUNDO' => $fields['fund'],
            '#CNPJ_FUNDO' => $fields['fund_document'], '#END_FUNDO' => $fields['fund_address'],
            '#CARGO_GESTOR' => $fields['position'], '#GESTOR' => $fields['manager'], '#GESTOR_FUNDO' => $fields['manager'],
            '#DOCUMENTOS_GESTOR' => $fields['manager_identification'], 'CPF: #CPF_GESTOR' => $fields['manager_identification'],
            '#FORNECEDOR' => $fields['supplier'], '#CNPJ_FORNECEDOR' => $fields['supplier_document'],
            '#END_FORNECEDOR' => $fields['supplier_address'], '#SOCIO' => $fields['representative'],
            '#DOCUMENTOS_SOCIO' => $fields['representative_identification'], '#CPF_SOCIO' => $fields['representative_document'],
            '#PERÍODO' => $amendment['Período'], '#PERIODO' => $amendment['Período'],
            '#INÍCIO' => (new DateTimeImmutable($amendment['INICIO']))->format('d/m/Y'),
            '#FINAL' => (new DateTimeImmutable($amendment['FINAL']))->format('d/m/Y'),
            '#DATA_ATA' => $fields['related']['signature_date'],
        ];
        if ($balance) {
            abort_if(blank($fields['related']['original_start']) || blank($fields['related']['previous_end']), 422,
                'Confira a data inicial e o vencimento anterior da vigência da ATA antes de emitir o aditivo.');
            $tokens += [
                '#DATA_INÍCIO_ATA' => (new DateTimeImmutable($fields['related']['original_start']))->format('d/m/Y'),
                '#DATA_FINAL_ATA' => (new DateTimeImmutable($fields['related']['previous_end']))->format('d/m/Y'),
                '#DATA_INICIAL' => $tokens['#INÍCIO'], '#DATA_FINAL' => $tokens['#FINAL'],
                'vantajosidade da manutenção dos preços registrados' => 'vantajosidade da manutenção dos preços registrados, conforme '.$amendment['VANTAJOSIDADE'].',',
            ];
        } else {
            $total = BigDecimal::zero();
            foreach ($products as $product) {
                $value = DecimalAmount::normalize((string) $product['VL_TOTAL']);
                abort_if($value === null || str_starts_with($value, '-'), 422, 'Confira os valores dos produtos do aditivo.');
                $total = $total->plus($value);
            }
            $amount = (string) $total->toScale(2, RoundingMode::HalfUp);
            $tokens += ['#VL_ADT' => $this->decimal($amount), '#EXTENSO_ADT' => MoneyWords::reais($amount)];
        }
        if ($fields['supplier_document_label'] === 'CPF') {
            $tokens['a empresa #FORNECEDOR, inscrita no CNPJ/MF sob o nº #CNPJ_FORNECEDOR, com sede no(a) #END_FORNECEDOR, neste ato representada por seu sócio-administrador, Sr,(a) #SOCIO, #DOCUMENTOS_SOCIO'] =
                "o(a) fornecedor(a) {$fields['supplier']}, {$fields['representative_identification']}, com endereço no(a) ".($fields['supplier_address'] ?: 'Não informado');
            $tokens['CNPJ: #CNPJ_FORNECEDOR'] = 'CPF: '.$fields['supplier_document'];
        }

        $path = tempnam(sys_get_temp_dir(), 'ata-amendment-');
        if ($path === false) {
            throw new RuntimeException('Não foi possível preparar o documento.');
        }
        try {
            if (! copy(resource_path('documents/atas/'.$model), $path)) {
                throw new RuntimeException('Não foi possível copiar o modelo do aditivo.');
            }
            $zip = new ZipArchive;
            if ($zip->open($path) !== true) {
                throw new RuntimeException('Modelo do aditivo inválido.');
            }
            try {
                foreach (['word/document.xml', 'word/header1.xml'] as $part) {
                    $partTokens = $part === 'word/document.xml'
                        ? array_replace($tokens, ['#ADT' => $amendment['ADT'].'º TERMO ADITIVO'])
                        : $tokens;
                    $xml = new DOMDocument;
                    if (! $xml->loadXML($zip->getFromName($part), LIBXML_NONET)) {
                        throw new RuntimeException('Não foi possível ler o modelo do aditivo.');
                    }
                    $xpath = new DOMXPath($xml);
                    $xpath->registerNamespace('w', self::NS);
                    foreach (iterator_to_array($xpath->query('//w:p')) as $paragraph) {
                        $nodes = iterator_to_array($xpath->query('.//w:t[count(ancestor::w:p) = '.($xpath->query('ancestor::w:p', $paragraph)->length + 1).']', $paragraph));
                        $text = implode('', array_map(fn ($node) => $node->textContent, $nodes));
                        if (in_array(trim($text), ['#TABELA DOS PRODUTOS ADITIVO', '#TABELA DOS PRODUTOS – SALDO'], true)) {
                            foreach ($tables->documentElement->childNodes as $block) {
                                $paragraph->parentNode->insertBefore($xml->importNode($block, true), $paragraph);
                            }
                            $paragraph->parentNode->removeChild($paragraph);
                        } elseif ($fields['supplier_document_label'] === 'CPF' && in_array(trim($text), ['#SOCIO', 'CPF: #CPF_SOCIO'], true)) {
                            $paragraph->parentNode->removeChild($paragraph);
                        } else {
                            $this->replaceTokens($nodes, $text, $partTokens);
                        }
                    }
                    if (! $zip->addFromString($part, $xml->saveXML())) {
                        throw new RuntimeException('Não foi possível gravar o documento do aditivo.');
                    }
                }
            } finally {
                $closed = $zip->close();
            }
            $bytes = $closed ? file_get_contents($path) : false;
            if ($bytes === false) {
                throw new RuntimeException('Não foi possível concluir o documento do aditivo.');
            }

            return $bytes;
        } finally {
            unlink($path);
        }
    }

    private function balanceRows(array $balances): array
    {
        return array_map(function ($product) {
            $price = DecimalAmount::normalize($product['unit_price']);
            abort_if($price === null || str_starts_with($price, '-'), 422, 'Confira os preços registrados dos produtos do aditivo.');

            return [
                'ITEM' => $product['item'], 'COD_PROD' => $product['code'], 'DISCRIMINACAO' => $product['description'],
                'UND' => $product['unit'], 'MARCA' => $product['brand'], 'QTD' => $product['balance_quantity'],
                'QTD_ESPECIAL' => $product['special_quantity'] ?? null, 'VL_UNIT' => $price, 'VL_TOTAL' => bcmul($product['balance_quantity'], $price, 4),
            ];
        }, $balances);
    }

    private function replaceTokens(array $nodes, string $text, array $tokens): void
    {
        $pattern = implode('|', array_map(fn ($token) => preg_quote($token, '~'), array_keys($tokens)));
        preg_match_all('~(?:'.$pattern.')(?![\p{L}\p{N}_])~u', $text, $matches, PREG_OFFSET_CAPTURE);
        $offsets = [];
        $offset = 0;
        foreach ($nodes as $index => $node) {
            $offsets[$index] = $offset;
            $offset += strlen($node->textContent);
        }
        // Word pode dividir um marcador entre vários trechos com formatação própria.
        foreach (array_reverse($matches[0]) as [$token, $start]) {
            $end = $start + strlen($token);
            foreach ($nodes as $index => $node) {
                $offset = $offsets[$index];
                $limit = $offsets[$index + 1] ?? strlen($text);
                if ($limit <= $start || $offset >= $end || $offset === $limit) {
                    continue;
                }
                $before = $offset <= $start ? substr($node->textContent, 0, $start - $offset) : '';
                $after = $limit >= $end ? substr($node->textContent, $end - $offset) : '';
                $replacement = $offset <= $start ? (filled($tokens[$token]) ? (string) $tokens[$token] : 'Não informado') : '';
                $node->textContent = $before.$replacement.$after;
                $node->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
            }
        }
    }

    private function productDecimal(string|int|float|null $value): string
    {
        $decimal = DecimalAmount::normalize((string) $value);

        return $decimal === null ? '—' : $this->decimal($decimal);
    }

    private function decimal(string $value): string
    {
        [$whole, $fraction] = explode('.', $value);

        return preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $whole).','.$fraction;
    }
}
