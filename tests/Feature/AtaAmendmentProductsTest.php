<?php

namespace Tests\Feature;

use App\Infrastructure\Atas\AtaAmendmentProductParser;
use App\Infrastructure\Atas\AtaAmendmentProductReader;
use App\Infrastructure\Atas\AtasOverviewQuery;
use App\Infrastructure\Atas\ImportAtaAmendmentProducts;
use App\Models\ActivityEvent;
use App\Models\User;
use FPDF;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AtaAmendmentProductsTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_apply_repeat_preserve_originals_and_amendment_identification(): void
    {
        $ata = $this->ata();
        $aditivo = $this->amendment($ata);
        DB::table('tb_PRODUTOS_ATA')->insert([...$this->product(), 'source_row' => '{}', 'ID_ATA' => $ata,
            'ATA' => '007/2025', 'PROCESSO' => '2025000001', 'LICITACAO' => 'PREGÃO ELETRÔNICO', 'N_LICIT' => '001/2025']);
        $service = $this->service();
        $this->assertSame(1, $service->run('/unused')['prepared']);
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 1);
        $this->assertDatabaseCount('activity_events', 0);
        $this->assertSame(1, $service->run('/unused', true)['inserted_products']);
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ID_ATA' => $ata, 'ID_ADITIVO' => $aditivo, 'ITEM' => '1',
            'ADITIVO' => 1, 'DATA_ADT' => '2026-06-11', 'PROC_ADT' => '2026000002', 'VL_TOTAL' => '10.0000']);
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ID_ADITIVO' => null, 'ADITIVO' => 0, 'DATA_ADT' => null, 'PROC_ADT' => null]);
        $this->assertSame(1, $service->run('/unused', true)['existing_products']);
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 2);
        $this->assertDatabaseCount('activity_events', 1);
        $this->assertDatabaseHas('tb_ATA', ['ID' => $ata, 'FINAL_ATA' => '2027-06-12', 'version' => 1]);
        $actor = User::create(['name' => 'Leitor fictício', 'username' => 'leitor-atas', 'email' => 'leitor@example.test', 'password' => 'testing-password']);
        $panel = app(AtasOverviewQuery::class)->for($actor, null);
        $this->assertSame('10.0000', $panel['total']);
        $this->assertSame(1, $panel['itemCount']);
    }

    public function test_missing_original_does_not_prevent_import_and_later_link_does_not_duplicate(): void
    {
        $aditivo = $this->amendment();
        $service = $this->service();
        $this->assertSame(1, $service->run('/unused', true)['without_original_ata']);
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ID_ATA' => null, 'ID_ADITIVO' => $aditivo, 'ATA' => '007/2025']);
        $ata = $this->ata();
        DB::table('tb_ADITIVOS_ATA')->where('ID', $aditivo)->update(['ID_ATA' => $ata, 'link_status' => 'vinculado']);
        $result = $service->run('/unused', true);
        $this->assertSame(1, $result['linked_products']);
        $this->assertSame(0, $result['inserted_products']);
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ID_ADITIVO' => $aditivo, 'ID_ATA' => $ata]);
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 1);
        $this->assertDatabaseCount('activity_events', 2);
    }

    public function test_failure_of_audit_rolls_back_products_and_manifest(): void
    {
        $this->amendment($this->ata());
        ActivityEvent::creating(fn () => throw new RuntimeException('audit unavailable'));
        try {
            $this->service()->run('/unused', true);
            $this->fail('Expected failure');
        } catch (RuntimeException $error) {
            $this->assertSame('audit unavailable', $error->getMessage());
        } finally {
            ActivityEvent::flushEventListeners();
        }
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 0);
        $this->assertNull(DB::table('tb_ADITIVOS_ATA')->value('products_import'));
    }

    public function test_edited_amendment_product_is_never_overwritten(): void
    {
        $this->amendment($this->ata());
        $service = $this->service();
        $service->run('/unused', true);
        DB::table('tb_PRODUTOS_ATA')->update(['VL_UNIT' => '9.0000']);
        $this->expectExceptionMessage('Produto existente divergente');
        $service->run('/unused', true);
    }

    public function test_deleted_product_is_not_restored_by_reimport(): void
    {
        $this->amendment($this->ata());
        $service = $this->service();
        $service->run('/unused', true);
        DB::table('tb_PRODUTOS_ATA')->update(['deleted_at' => now()]);
        $this->expectExceptionMessage('Produto do aditivo excluído');
        $service->run('/unused', true);
    }

    public function test_document_without_discriminated_products_is_reported_without_inventing_items(): void
    {
        $this->amendment();
        $entry = $this->entry();
        $entry['products'] = [];
        $entry['total'] = '0.0000';
        $entry['has_item_header'] = false;
        $result = $this->service($entry)->run('/unused', true);
        $this->assertCount(1, $result['without_item_table']);
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 0);
        $this->assertDatabaseCount('activity_events', 1);
    }

    public function test_parser_handles_wrapped_headers_repeated_pages_and_keeps_exact_amounts(): void
    {
        $doc = ['extraction' => 'pdf_grid', 'pages' => [
            ['page' => 1, 'text' => 'Niquelândia-GO,15desetembrode2026.', 'tables' => [[
                ['', '', '', '', '', 'V. UNIT.', 'V. TOTAL'],
                ['ITEM', 'DESCRIÇÃO', 'MARCA', 'UND.', 'QTD.', null, null],
                [null, null, null, null, null, '(R$)', '(R$)'],
                ['1', "Descrição\ncompleta", 'Marca', 'UNIDADE', '1.000', 'R$ 0,1234', 'R$ 123,40'],
            ]]],
            ['page' => 2, 'text' => '', 'tables' => [[
                ['ITEM', 'DESCRIÇÃO', 'MARCA', 'UND.', 'QTD.', 'V. UNIT. (R$)', 'V. TOTAL (R$)'],
                ['2', 'Outro produto', 'Marca', 'UNIDADE', '2', '3,00', '6,00'],
                ['VALOR GLOBAL ESTIMADO:', null, null, null, null, '-', '129,40'],
            ]]],
        ]];
        $entry = app(AtaAmendmentProductParser::class)->parse($doc, ['source_sha256' => str_repeat('a', 64), 'PROCESSO_ADITIVO' => '2026000002', 'PROCESSO_ORIGEM' => '2025000001']);
        $this->assertSame('2026-09-15', $entry['DATA_ADT']);
        $this->assertSame('129.4000', $entry['total']);
        $this->assertSame('0.1234', $entry['products'][0]['VL_UNIT']);
        $this->assertSame('Descrição completa', $entry['products'][0]['DISCRIMINACAO']);
        $this->assertSame([], $entry['issues']);
    }

    public function test_real_pdf_extraction_checks_document_hash_and_preserves_cells(): void
    {
        $directory = storage_path('app/private/product-reader-'.bin2hex(random_bytes(6)));
        mkdir($directory, 0700, true);
        $path = $directory.'/aditivo.pdf';
        $pdf = new FPDF('L');
        $pdf->AddPage();
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(250, 8, mb_convert_encoding('Niquelândia-GO, 11 de junho de 2026.', 'Windows-1252', 'UTF-8'), 0, 1);
        foreach ([['ITEM', 'DESCRIÇÃO', 'MARCA', 'UND.', 'QTD.', 'V. UNIT.', 'V. TOTAL'],
            ['1', 'Material ficticio', 'Marca', 'UND', '2', '5,00', '10,00']] as $row) {
            foreach ([12, 90, 30, 25, 25, 35, 35] as $index => $width) {
                $pdf->Cell($width, 8, mb_convert_encoding($row[$index], 'Windows-1252', 'UTF-8'), 1);
            }
            $pdf->Ln();
        }
        $pdf->Output('F', $path);
        $metadata = ['source_path' => 'aditivo.pdf', 'source_sha256' => hash_file('sha256', $path), 'extraction' => 'pdf_text',
            'PROCESSO_ADITIVO' => '2026000002', 'PROCESSO_ORIGEM' => '2025000001'];
        $reader = app(AtaAmendmentProductReader::class);
        $entry = $reader->read($directory, $metadata);
        $this->assertSame('10.0000', $entry['total']);
        $this->assertSame('2026-06-11', $entry['DATA_ADT']);
        $this->assertSame('Material ficticio', $entry['products'][0]['DISCRIMINACAO']);
        (new Process(['pdftoppm', '-f', '1', '-l', '1', '-scale-to', '2400', '-png', $path, $directory.'/scan']))->setTimeout(60)->mustRun();
        $scanned = new FPDF('L');
        $scanned->AddPage();
        $scanned->Image($directory.'/scan-1.png', 0, 0, 297, 210);
        $scanPath = $directory.'/scanned.pdf';
        $scanned->Output('F', $scanPath);
        $scanMetadata = [...$metadata, 'source_path' => 'scanned.pdf', 'source_sha256' => hash_file('sha256', $scanPath), 'extraction' => 'ocr_por'];
        $scanEntry = $reader->read($directory, $scanMetadata);
        $this->assertSame('10.0000', $scanEntry['total']);
        $this->assertSame('2.0000', $scanEntry['products'][0]['QTD']);
        file_put_contents($path, 'changed');
        $this->expectExceptionMessage('Origem do aditivo ausente ou alterada');
        $reader->read($directory, $metadata);
    }

    public function test_wrong_ata_link_is_rejected_before_product_insertion(): void
    {
        $ata = $this->ata();
        $this->amendment($ata);
        DB::table('tb_ATA')->where('ID', $ata)->update(['PROCESSO' => '9999999999']);
        $this->expectExceptionMessage('A ata vinculada diverge');
        $this->service()->run('/unused', true);
    }

    public function test_product_reader_rejects_unsigned_source_before_pdf_processing(): void
    {
        $directory = storage_path('app/private/unsigned-products-'.bin2hex(random_bytes(6)));
        mkdir($directory, 0700, true);
        $path = $directory.'/unsigned.pdf';
        file_put_contents($path, 'not a signed amendment');
        config(['atas.unsigned_amendment_directories' => [$directory]]);
        $this->expectExceptionMessage('Aditivo desconsiderado por ausência de assinatura');
        app(AtaAmendmentProductReader::class)->read($directory, ['source_path' => 'unsigned.pdf', 'source_sha256' => hash_file('sha256', $path)]);
    }

    private function service(?array $entry = null): ImportAtaAmendmentProducts
    {
        $reader = new class($entry ?? $this->entry()) extends AtaAmendmentProductReader
        {
            public function __construct(private array $entry) {}

            public function read(string $directory, array $metadata): array
            {
                return $this->entry;
            }
        };

        return new ImportAtaAmendmentProducts($reader);
    }

    private function product(): array
    {
        return ['ITEM' => '1', 'DISCRIMINACAO' => 'Produto fictício', 'MARCA' => null, 'UND' => 'UNIDADE',
            'QTD' => '2.0000', 'VL_UNIT' => '5.0000', 'VL_TOTAL' => '10.0000', 'source_row' => ['page' => 1, 'cells' => ['1', 'Produto fictício']]];
    }

    private function entry(): array
    {
        return ['DATA_ADT' => '2026-06-11', 'PROC_ADT' => '2026000002', 'products' => [$this->product()], 'total' => '10.0000', 'issues' => [], 'has_item_header' => true];
    }

    private function ata(): int
    {
        return DB::table('tb_ATA')->insertGetId(['ATA' => '007/2025', 'PROCESSO' => '2025000001', 'LICITACAO' => 'PREGÃO ELETRÔNICO', 'N_LICIT' => '001/2025',
            'CNPJ_FUNDO' => '00.000.000/0000-01', 'source_files' => '[]', 'import_hash' => str_repeat('a', 64), 'quality_issues' => '[]',
            'DATA_ATA' => '2025-06-13', 'INICIO_ATA' => '2025-06-13', 'FINAL_ATA' => '2027-06-12'], 'ID');
    }

    private function amendment(?int $ata = null): int
    {
        return DB::table('tb_ADITIVOS_ATA')->insertGetId(['ID_ATA' => $ata, 'ATA' => '007/2025', 'NUMERO' => 1,
            'PROCESSO_ORIGEM' => '2025000001', 'PROCESSO_ADITIVO' => '2026000002', 'N_LICIT' => '001/2025',
            'source_path' => 'aditivo.pdf', 'source_sha256' => str_repeat('a', 64), 'source_size' => 100, 'extraction' => 'pdf_text',
            'link_status' => $ata ? 'vinculado' : 'ata_ausente', 'issues' => '[]'], 'ID');
    }
}
