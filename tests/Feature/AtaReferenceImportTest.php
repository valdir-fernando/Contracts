<?php

namespace Tests\Feature;

use App\Infrastructure\Atas\AtaDocumentParser;
use App\Infrastructure\Atas\AtaReferenceImport;
use App\Infrastructure\Atas\AtaSourceReader;
use App\Infrastructure\Atas\UpdateAtaValidity;
use App\Models\ActivityEvent;
use FPDF;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class AtaReferenceImportTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('app/private/ata-fixture-'.bin2hex(random_bytes(6)));
        mkdir($this->directory.'/PE_001-26', 0700, true);
        $this->workbook();
        $this->document();
        DB::table('contract_funds')->insert(['legacy_id' => 999, 'name' => 'Fundo fictício', 'name_key' => 'FUNDO FICTICIO',
            'document_key' => '00000000000001', 'raw_payload' => '{}']);
    }

    public function test_preview_import_and_repeat_preserve_documentary_values_and_links(): void
    {
        $service = app(AtaReferenceImport::class);
        $preview = $service->run($this->directory);
        $this->assertSame(1, $preview['checked']);
        $this->assertSame(0, $preview['inserted']);
        $this->assertDatabaseCount('tb_ATA', 0);
        $this->assertDatabaseCount('activity_events', 0);
        $result = $service->run($this->directory, true);
        $this->assertSame(1, $result['inserted']);
        $this->assertSame(2, $result['products']);
        $this->assertSame('443302.5000', $result['by_procurement']['001/2026']['total']);
        $ata = DB::table('tb_ATA')->first();
        $this->assertSame('2025-03-11', $ata->DATA_ATA);
        $this->assertNull($ata->INICIO_ATA);
        $this->assertNull($ata->FINAL_ATA);
        $this->assertNull($ata->Doc_ATA);
        $this->assertNotNull($ata->fund_id);
        $this->assertStringContainsString('ano_data_diverge_ano_ata', $ata->quality_issues);
        $this->assertSame('Sócio fictício', $ata->SOCIO);
        $this->assertStringNotContainsString('ITEM', $ata->{'OBJETO DA ATA'});
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ITEM' => '1', 'QTD' => '28600.0000', 'VL_TOTAL' => '443300.0000', 'MARCA' => null]);
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ITEM' => '2', 'QTD' => '0.5000', 'VL_UNIT' => '5.0000', 'VL_TOTAL' => '2.5000']);
        $this->assertDatabaseHas('tb_FUNDOS_ATA', ['ID_ATA' => $ata->ID, 'ATA' => $ata->ATA, 'papel' => 'participante', 'CNPJ_FUNDO' => '00.000.000/0000-02']);
        $sources = json_decode($ata->source_files, true);
        $this->assertSame(hash_file('sha256', $this->documentPath()), $sources[0]['sha256']);
        $this->assertSame(1, $service->run($this->directory, true)['existing']);
        $this->assertDatabaseCount('tb_ATA', 1);
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 2);
        $this->assertDatabaseCount('tb_FUNDOS_ATA', 2);
        $this->assertDatabaseCount('activity_events', 1);
        $this->assertDatabaseCount('contracts', 0);
        $this->assertDatabaseCount('suppliers', 0);
        $this->assertDatabaseHas('tb_N_ATA', ['ANO' => 2026, 'NUMERO' => 1]);
    }

    public function test_failure_of_audit_rolls_back_the_entire_import(): void
    {
        ActivityEvent::creating(fn () => throw new RuntimeException('audit unavailable'));
        try {
            app(AtaReferenceImport::class)->run($this->directory, true);
            $this->fail('Expected audit failure');
        } catch (RuntimeException $error) {
            $this->assertSame('audit unavailable', $error->getMessage());
        } finally {
            ActivityEvent::flushEventListeners();
        }
        foreach (['tb_ATA', 'tb_FUNDOS_ATA', 'tb_PRODUTOS_ATA', 'tb_N_ATA', 'activity_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_year_subdirectory_preserves_document_year_and_checks_all_pdf_versions(): void
    {
        $this->pairedPdf();
        $pdf = substr($this->documentPath(), 0, -5).'.pdf';
        copy($pdf, substr($pdf, 0, -4).'_assinado.pdf');
        rename($this->documentPath(), str_replace('_ATA_', '_', $this->documentPath()));
        mkdir($this->directory.'/2025');
        rename($this->directory.'/PE_001-26', $this->directory.'/2025/PE_001-26');
        $result = app(AtaReferenceImport::class)->run($this->directory.'/2025', true);
        $this->assertSame(1, $result['inserted']);
        $this->assertDatabaseHas('tb_ATA', ['ATA' => '001/2026']);
        $this->assertDatabaseHas('tb_N_ATA', ['ANO' => 2026, 'NUMERO' => 1]);
        $sources = json_decode(DB::table('tb_ATA')->value('source_files'), true);
        $this->assertCount(3, $sources);
        foreach ($sources as $source) {
            $this->assertStringStartsWith('2025/PE_001-26/', $source['file']);
        }
        $this->assertSame(1, app(AtaReferenceImport::class)->run($this->directory.'/2025', true)['existing']);
    }

    public function test_older_template_headers_document_punctuation_and_wrapped_email_are_read(): void
    {
        $document = app(AtaSourceReader::class)->docx($this->documentPath());
        $text = str_replace(['O FUNDO', '00.000.000/0000-03', 'e-mail:'], ['O(A) FUNDO', '00.000.000.0000-03', "e-\nmail:"], $document['text']);
        $tables = $document['tables'];
        $tables[0][0] = ['ID', 'DISCRIMINAÇÃO', 'MARCA', 'MEDIDA', 'QTD', 'VALOR UNT', 'VALOR TOTAL'];
        $entry = app(AtaDocumentParser::class)->parse($text, $tables);
        $this->assertSame('00.000.000/0000-03', $entry['ata']['CNPJ_FORNECEDOR']);
        $this->assertSame('teste@example.invalid', $entry['ata']['EMAIL']);
        $this->assertCount(2, $entry['products']);
        $this->assertSame('15.5000', $entry['products'][0]['VL_UNIT']);
    }

    public function test_written_weekday_followed_by_de_preserves_the_document_date(): void
    {
        $document = app(AtaSourceReader::class)->docx($this->documentPath());
        $text = str_replace('11 de março de 2025', 'terça-feira de 21 de outubro de 2025', $document['text']);
        $entry = app(AtaDocumentParser::class)->parse($text, $document['tables']);
        $this->assertSame('2025-10-21', $entry['ata']['DATA_ATA']);
        $this->assertNotContains('data_ata_nao_identificada', $entry['issues']);
    }

    public function test_pdf_numbers_exclude_description_and_brand_numbers_when_quantity_precedes_brand(): void
    {
        $pdf = new FPDF;
        $pdf->AddPage();
        $pdf->SetFont('Arial', '', 12);
        $pdf->Text(10, 30, 'FORNECEDOR: FICTICIO');
        foreach ([[10, 'ITEM'], [30, 'DESCRICAO'], [105, 'QTD'], [125, 'UND'], [145, 'MARCA'], [170, 'VL.'], [190, 'TOTAL']] as [$x, $label]) {
            $pdf->Text($x, 50, $label);
        }
        foreach ([[90, '30'], [105, '12'], [145, '2022'], [170, '8,50'], [190, '102,00']] as [$x, $label]) {
            $pdf->Text($x, 65, $label);
        }
        $pdf->Text(10, 85, 'A listagem do cadastro de reserva');
        $pdf->Text(170, 95, '999');
        $path = $this->directory.'/older-layout.pdf';
        $pdf->Output('F', $path);
        $this->assertSame(['12', '8,50', '102,00'], app(AtaSourceReader::class)->pdfTableNumbers($path));
    }

    public function test_reimport_preserves_validity_derived_by_authorized_maintenance(): void
    {
        $service = app(AtaReferenceImport::class);
        $service->run($this->directory, true);
        $amendments = $this->directory.'/amendments';
        mkdir($amendments);
        app(UpdateAtaValidity::class)->run($amendments, true);
        $pdf = "%PDF-1.7\n".random_bytes(131072)."\x00\xff\n%%EOF\n";
        DB::table('tb_ATA')->update(['Doc_ATA' => $pdf]);
        $this->assertSame(1, $service->run($this->directory, true)['existing']);
        $stored = DB::table('tb_ATA')->value('Doc_ATA');
        $this->assertSame(strlen($pdf), strlen($stored));
        $this->assertSame(hash('sha256', $pdf), hash('sha256', $stored));
        $this->assertDatabaseHas('tb_ATA', ['INICIO_ATA' => '2025-03-11', 'FINAL_ATA' => '2026-03-11', 'version' => 2]);
        $this->assertDatabaseCount('activity_events', 2);
    }

    public function test_pdf_migration_rejects_existing_paths_without_converting_them_to_document_bytes(): void
    {
        app(AtaReferenceImport::class)->run($this->directory, true);
        DB::table('tb_ATA')->update(['Doc_ATA' => 'atas/1/ata.pdf']);
        $migration = require database_path('migrations/2026_11_09_000009_store_ata_pdf_binary.php');
        $this->expectExceptionMessage('Doc_ATA contém referências existentes');
        $migration->up();
    }

    public function test_reimport_checks_original_products_separately_from_amendment_products(): void
    {
        $service = app(AtaReferenceImport::class);
        $service->run($this->directory, true);
        $ata = DB::table('tb_ATA')->first();
        $amendment = DB::table('tb_ADITIVOS_ATA')->insertGetId(['ID_ATA' => $ata->ID, 'ATA' => $ata->ATA, 'NUMERO' => 1,
            'source_path' => 'aditivo.pdf', 'source_sha256' => str_repeat('a', 64), 'source_size' => 100, 'extraction' => 'pdf_text', 'link_status' => 'vinculado', 'issues' => '[]'], 'ID');
        $product = (array) DB::table('tb_PRODUTOS_ATA')->where('ID_ATA', $ata->ID)->first();
        unset($product['ID']);
        DB::table('tb_PRODUTOS_ATA')->insert([...$product, 'ADITIVO' => 1, 'ID_ADITIVO' => $amendment, 'DATA_ADT' => '2026-11-09', 'PROC_ADT' => '2026000002']);
        $this->assertSame(1, $service->run($this->directory, true)['existing']);
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 3);
        $this->assertDatabaseCount('activity_events', 1);
    }

    public function test_edited_product_is_not_overwritten_or_duplicated(): void
    {
        $service = app(AtaReferenceImport::class);
        $service->run($this->directory, true);
        DB::table('tb_PRODUTOS_ATA')->where('ITEM', '1')->update(['DISCRIMINACAO' => 'Edição preservada']);
        try {
            $service->run($this->directory, true);
            $this->fail('Expected conflict');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Conflito', $error->getMessage());
        }
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ITEM' => '1', 'DISCRIMINACAO' => 'Edição preservada']);
        $this->assertDatabaseCount('activity_events', 1);
    }

    public function test_mysql_json_key_reordering_is_not_a_source_conflict(): void
    {
        $service = app(AtaReferenceImport::class);
        $service->run($this->directory, true);
        $sources = json_decode(DB::table('tb_ATA')->value('source_files'), true);
        foreach ($sources as &$source) {
            $source = array_reverse($source, true);
        }
        unset($source);
        DB::table('tb_ATA')->update(['source_files' => json_encode($sources)]);
        $this->assertSame(1, $service->run($this->directory, true)['existing']);
        $this->assertDatabaseCount('activity_events', 1);
    }

    public function test_changed_source_aborts_without_overwriting(): void
    {
        $service = app(AtaReferenceImport::class);
        $service->run($this->directory, true);
        $this->document('Outra empresa fictícia');
        $this->expectExceptionMessage('Conflito');
        $service->run($this->directory, true);
    }

    public function test_deleted_ata_is_not_resurrected(): void
    {
        $service = app(AtaReferenceImport::class);
        $service->run($this->directory, true);
        DB::table('tb_ATA')->update(['deleted_at' => now()]);
        $this->expectExceptionMessage('Conflito');
        $service->run($this->directory, true);
    }

    public function test_unknown_product_row_aborts_instead_of_skipping(): void
    {
        $this->document(invalidRow: true);
        $this->expectExceptionMessage('Linha de produto não reconhecida');
        app(AtaReferenceImport::class)->run($this->directory, true);
    }

    public function test_command_defaults_to_preview_and_reports_invalid_workbook(): void
    {
        $this->artisan('atas:import-reference', ['directory' => $this->directory])->assertSuccessful();
        $this->assertDatabaseCount('tb_ATA', 0);
        $book = new Spreadsheet;
        $book->getActiveSheet()->setCellValue('A1', 'estrutura incompatível');
        (new Xlsx($book))->save($this->directory.'/BD_ATAS.xlsx');
        $book->disconnectWorksheets();
        $this->artisan('atas:import-reference', ['directory' => $this->directory, '--apply' => true])->assertFailed();
        $this->assertDatabaseCount('tb_ATA', 0);
    }

    public function test_pdf_only_table_accepts_decimal_quantities_and_does_not_invent_brand(): void
    {
        $doc = app(AtaSourceReader::class)->docx($this->documentPath());
        $text = $doc['text'];
        $text = preg_replace('/ITEM \|.*?(?=FORNECEDOR:)/s', "ITEM DISCRIMINAÇÃO UND QTD VL_UNIT TOTAL\n1   Material fictício   T   26,10   3.100,00   80.910,00\n", $text);
        $entry = app(AtaDocumentParser::class)->parse($text);
        $this->assertSame('26.1000', $entry['products'][0]['QTD']);
        $this->assertSame('80910.0000', $entry['products'][0]['VL_TOTAL']);
        $this->assertNull($entry['products'][0]['MARCA']);
    }

    public function test_wrapped_document_number_is_read_without_changing_its_digits(): void
    {
        $doc = app(AtaSourceReader::class)->docx($this->documentPath());
        $text = str_replace('000.000.000-03', "000.000.000-\n03", $doc['text']);
        $entry = app(AtaDocumentParser::class)->parse($text, $doc['tables']);
        $this->assertSame('000.000.000-03', $entry['ata']['CPF_SOCIO']);
    }

    public function test_pdf_coordinate_reader_retains_wrapped_totals_and_split_item_header(): void
    {
        $pdf = new FPDF;
        $pdf->AddPage();
        $pdf->SetFont('Arial', '', 12);
        foreach ([[10, 'ITE'], [90, 'QTD'], [125, 'VL_UNIT'], [165, 'TOTAL']] as [$x, $label]) {
            $pdf->Text($x, 50, $label);
        }
        $pdf->Text(10, 55, 'M');
        $pdf->Text(90, 65, '26,1000');
        $pdf->Text(125, 65, '3.100,00');
        $pdf->Text(165, 72, '80.910,00');
        $pdf->Text(10, 85, 'FORNECEDOR: FICTICIO');
        $path = $this->directory.'/numbers.pdf';
        $pdf->Output('F', $path);
        $this->assertSame(['26,1000', '3.100,00', '80.910,00'], app(AtaSourceReader::class)->pdfTableNumbers($path));
    }

    public function test_paired_pdf_is_checked_before_any_write(): void
    {
        $this->pairedPdf();
        $result = app(AtaReferenceImport::class)->run($this->directory, true);
        $this->assertSame(1, $result['inserted']);
        $this->assertCount(2, json_decode(DB::table('tb_ATA')->value('source_files'), true));
    }

    public function test_disagreement_in_pdf_price_aborts_the_import(): void
    {
        $this->pairedPdf('16,50');
        try {
            app(AtaReferenceImport::class)->run($this->directory, true);
            $this->fail('Expected PDF disagreement');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Quantidades/preços/totais do PDF', $error->getMessage());
        }
        $this->assertDatabaseCount('tb_ATA', 0);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_error_in_later_file_leaves_no_partial_batch(): void
    {
        copy($this->documentPath(), $this->directory.'/PE_001-26/EMPRESA_ATA_002-26_PE_001-26_2026000001.docx');
        try {
            app(AtaReferenceImport::class)->run($this->directory, true);
            $this->fail('Expected filename mismatch');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Número da ata diverge', $error->getMessage());
        }
        $this->assertDatabaseCount('tb_ATA', 0);
    }

    private function pairedPdf(string $price = '15,50'): void
    {
        $doc = app(AtaSourceReader::class)->docx($this->documentPath());
        [$before, $rest] = explode('ITEM |', $doc['text'], 2);
        $after = 'FORNECEDOR:'.explode('FORNECEDOR:', $rest, 2)[1];
        $pdf = new FPDF;
        $pdf->AddPage();
        $pdf->SetFont('Arial', '', 8);
        $pdf->MultiCell(190, 4, mb_convert_encoding($before, 'Windows-1252', 'UTF-8'));
        $y = $pdf->GetY() + 5;
        foreach ([[10, 'ITEM'], [30, 'DISCRIMINACAO'], [90, 'QTD'], [125, 'VL_UNIT'], [165, 'TOTAL']] as [$x, $label]) {
            $pdf->Text($x, $y, $label);
        }
        foreach ([['28.600', $price, '443.300,00'], ['0,5000', '5,00', '2,50']] as $row) {
            $y += 6;
            foreach ([90, 125, 165] as $index => $x) {
                $pdf->Text($x, $y, $row[$index]);
            }
        }
        $pdf->SetY($y + 7);
        $pdf->MultiCell(190, 4, mb_convert_encoding($after, 'Windows-1252', 'UTF-8'));
        $pdf->Output('F', substr($this->documentPath(), 0, -5).'.pdf');
    }

    private function documentPath(): string
    {
        return $this->directory.'/PE_001-26/EMPRESA_ATA_001-26_PE_001-26_2026000001.docx';
    }

    private function document(string $supplier = 'Empresa fictícia', bool $invalidRow = false): void
    {
        $paragraphs = [
            'ATA DE REGISTRO DE PREÇOS Nº 001/2026', 'PREGÃO ELETRÔNICO 001/2026', 'PROCESSO ADMINISTRATIVO N. 2026000001',
            'O FUNDO MUNICIPAL FICTÍCIO, pessoa jurídica de direito público interno, inscrito no CNPJ nº. 00.000.000/0000-01, com endereço na Rua Fictícia, neste ato representado pelo Gestor, Senhor(a) Gestor fictício, nomeado(a) pelo Decreto nº 001/2025, de 01/01/2025, brasileiro(a), portador da matrícula funcional nº 123, CPF 000.000.000-01, RG 123 FICTICIO, residente nesta cidade, considerando o julgamento para REGISTRO DE PREÇOS nº 001/2026.',
            'DO OBJETO', 'A presente Ata tem por objeto material fictício, independentemente de transcrição.',
            'PREÇOS, ESPECIFICAÇÕES E QUANTITATIVOS',
        ];
        $p = fn ($text) => '<w:p><w:r><w:t>'.htmlspecialchars($text, ENT_XML1, 'UTF-8').'</w:t></w:r></w:p>';
        $body = implode('', array_map($p, $paragraphs));
        $rows = [['ITEM', 'DISCRIMINAÇÃO', 'MARCA', 'UND', 'QTD', 'VL_UNIT', 'VL_TOTAL'],
            ['1', 'Lavanderia fictícia', '', 'KG', '28.600', '15,50', '443.300,00'],
            ['2', 'Produto fracionário', '', 'KG', '0,5000', '5,00', '2,50']];
        if ($invalidRow) {
            $rows[] = ['linha inválida'];
        }
        // Some originals place the supplier inside a merged row of the product table.
        $rows[] = ['FORNECEDOR: '.$supplier.', com sede na Rua Fictícia, inscrita no CNPJ/MF sob o nº 00.000.000/0000-03, e-mail: teste@example.invalid, telefone para contato nº (00) 90000-0000 / (00) 90000-0001, neste ato representada por seu Sócio Administrador, Sr(a). Sócio fictício, portador da Carteira de Identidade nº 123 FICTICIO, CPF nº 000.000.000-03.'];
        $body .= '<w:tbl>';
        foreach ($rows as $row) {
            $body .= '<w:tr>'.implode('', array_map(fn ($cell) => '<w:tc>'.$p($cell).'</w:tc>', $row)).'</w:tr>';
        }
        $body .= '</w:tbl>';
        foreach (['A listagem do cadastro de reserva referente ao presente registro de preços consta como anexo.',
            'Além do gerenciador, são participantes do registro de preços:', '00.000.000/0000-02Fundo participante fictício',
            'ADESÃO À ATA', 'A validade da Ata de Registro de Preços será de 1 (um) ano, contado a partir do primeiro dia útil subsequente à data de divulgação no PNCP, podendo ser prorrogada por igual período, desde que comprovado o preço vantajoso.',
            'Niquelândia – Goiás, 11 de março de 2025.'] as $text) {
            $body .= $p($text);
        }
        $zip = new ZipArchive;
        $zip->open($this->documentPath(), ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'</w:body></w:document>');
        $zip->close();
    }

    private function workbook(): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $schema = [
            'tb_ATA' => 'ATA,DATA_ATA,PROCESSO,LICITACAO,N_LICIT,FORNECEDOR,END_FORNECEDOR,EMAIL,TELEFONE1,TELEFONE2,SOCIO,RG_SOCIO,CPF_SOCIO,CNPJ_FUNDO,FUNDO,END_FUNDO,GESTOR,CPF_GESTOR,RG_GESTOR,DECRETO_GESTOR,MATRICULA_GESTOR,OBJETO DA ATA,INICIO_ATA,FINAL_ATA,PERIODO_ATA',
            'tb_FUNDOS_ATA' => 'CNPJ_FUNDO,FUNDO',
            'tb_PRODUTOS_ATA' => 'ATA,PROCESSO,LICITACAO,N_LICIT,ITEM,DISCRIMINACAO,MARCA,UND,QTD,VL_UNIT,VL_TOTAL',
        ];
        $row = 1;
        foreach ($schema as $table => $columns) {
            $sheet->setCellValue('A'.$row++, $table);
            foreach (explode(',', $columns) as $index => $column) {
                $sheet->setCellValue('A'.$row, $index + 1);
                $sheet->setCellValue('B'.$row++, $column);
            }
        }
        (new Xlsx($book))->save($this->directory.'/BD_ATAS.xlsx');
        $book->disconnectWorksheets();
    }
}
