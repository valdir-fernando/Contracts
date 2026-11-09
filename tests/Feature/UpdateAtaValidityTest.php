<?php

namespace Tests\Feature;

use App\Infrastructure\Atas\AtaAmendmentReader;
use App\Infrastructure\Atas\UpdateAtaValidity;
use App\Models\ActivityEvent;
use FPDF;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class UpdateAtaValidityTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('app/private/amendments-'.bin2hex(random_bytes(8)));
        mkdir($this->directory, 0700, true);
    }

    public function test_preview_and_apply_use_ata_date_and_exact_amendment_end_with_repeatability(): void
    {
        $id = $this->ata('007/2026');
        $this->pdf('007/2026', '13/06/2027', '12/06/2028');
        $service = app(UpdateAtaValidity::class);
        $preview = $service->run($this->directory);
        $this->assertSame(1, $preview['linked']);
        $this->assertSame(1, $preview['updated_atas']);
        $this->assertDatabaseCount('tb_ADITIVOS_ATA', 0);
        $this->assertDatabaseHas('tb_ATA', ['ID' => $id, 'INICIO_ATA' => null, 'FINAL_ATA' => null, 'version' => 1]);
        $result = $service->run($this->directory, true);
        $this->assertSame(1, $result['updated_atas']);
        $this->assertDatabaseHas('tb_ATA', ['ID' => $id, 'DATA_ATA' => '2026-03-11', 'INICIO_ATA' => '2026-03-11', 'FINAL_ATA' => '2028-06-12', 'version' => 2]);
        $this->assertDatabaseHas('tb_ADITIVOS_ATA', ['ID_ATA' => $id, 'ATA' => '007/2026', 'link_status' => 'vinculado']);
        $this->assertSame(['ano_data_diverge_ano_ata'], json_decode(DB::table('tb_ATA')->value('quality_issues'), true));
        $this->assertSame(0, $service->run($this->directory, true)['updated_atas']);
        $this->assertDatabaseCount('activity_events', 1);
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 0);
        $this->assertDatabaseCount('contracts', 0);
    }

    public function test_without_amendment_uses_period_and_preserves_document_year_inconsistency(): void
    {
        $id = $this->ata('057/2026', ['DATA_ATA' => '2025-03-11']);
        app(UpdateAtaValidity::class)->run($this->directory, true);
        $this->assertDatabaseHas('tb_ATA', ['ID' => $id, 'DATA_ATA' => '2025-03-11', 'INICIO_ATA' => '2025-03-11', 'FINAL_ATA' => '2026-03-11']);
    }

    public function test_unsigned_directory_is_ignored_even_when_read_directly(): void
    {
        $id = $this->ata('007/2026');
        $unsigned = $this->directory.'/Nova pasta';
        mkdir($unsigned);
        $file = $this->pdf('007/2026');
        rename($file, $unsigned.'/'.basename($file));
        config(['atas.unsigned_amendment_directories' => [$unsigned]]);
        $service = app(UpdateAtaValidity::class);
        $this->assertSame(0, $service->run($this->directory, true)['documents']);
        $this->assertSame([], app(AtaAmendmentReader::class)->read($unsigned));
        $this->assertDatabaseCount('tb_ADITIVOS_ATA', 0);
        $this->assertDatabaseHas('tb_ATA', ['ID' => $id, 'FINAL_ATA' => '2027-03-11']);
        $this->assertFileExists($unsigned.'/'.basename($file));
    }

    public function test_previously_recorded_unsigned_amendment_is_preserved_and_its_extension_reversed(): void
    {
        $id = $this->ata('007/2026');
        $unsigned = $this->directory.'/Nova pasta';
        mkdir($unsigned);
        $file = $this->pdf('007/2026');
        rename($file, $unsigned.'/'.basename($file));
        config(['atas.unsigned_amendment_directories' => []]);
        $service = app(UpdateAtaValidity::class);
        $service->run($this->directory, true);
        $this->assertDatabaseHas('tb_ATA', ['ID' => $id, 'FINAL_ATA' => '2027-06-12']);
        config(['atas.unsigned_amendment_directories' => [$unsigned]]);
        $result = $service->run($this->directory, true);
        $this->assertSame(1, $result['ignored_unsigned']);
        $this->assertDatabaseHas('tb_ATA', ['ID' => $id, 'FINAL_ATA' => '2027-03-11']);
        $this->assertDatabaseHas('tb_ADITIVOS_ATA', ['ID_ATA' => $id, 'link_status' => 'nao_assinado']);
        $this->assertDatabaseCount('tb_ADITIVOS_ATA', 1);
        $this->assertDatabaseCount('activity_events', 2);
        $this->assertSame(0, $service->run($this->directory, true)['updated_atas']);
        $this->assertDatabaseCount('activity_events', 2);
    }

    public function test_different_year_is_pending_and_never_matches_only_by_sequence(): void
    {
        $id = $this->ata('007/2026');
        $this->pdf('007/2025', '13/06/2026', '12/06/2027');
        $result = app(UpdateAtaValidity::class)->run($this->directory, true);
        $this->assertSame(0, $result['linked']);
        $this->assertSame(1, $result['new_amendments']);
        $this->assertSame('ata_ausente', $result['pending_documents'][0]['reason']);
        $this->assertDatabaseHas('tb_ADITIVOS_ATA', ['ATA' => '007/2025', 'ID_ATA' => null]);
        $this->assertDatabaseHas('tb_ATA', ['ID' => $id, 'FINAL_ATA' => '2027-03-11']);
    }

    public function test_process_conflict_blocks_original_period_fallback(): void
    {
        $id = $this->ata('007/2026', ['PROCESSO' => '9999999999']);
        $this->pdf('007/2026');
        $result = app(UpdateAtaValidity::class)->run($this->directory, true);
        $this->assertSame(1, count($result['skipped_atas']));
        $this->assertSame('conferencia_necessaria', $result['pending_documents'][0]['reason']);
        $this->assertDatabaseHas('tb_ATA', ['ID' => $id, 'FINAL_ATA' => null, 'version' => 1]);
    }

    public function test_latest_ordinal_wins_and_missing_source_does_not_erase_recorded_extension(): void
    {
        $id = $this->ata('007/2026');
        $first = $this->pdf('007/2026', '11/03/2027', '10/03/2028', 1);
        $this->pdf('007/2026', '11/03/2028', '10/09/2028', 2);
        $service = app(UpdateAtaValidity::class);
        $service->run($this->directory, true);
        $this->assertDatabaseHas('tb_ATA', ['ID' => $id, 'FINAL_ATA' => '2028-09-10']);
        unlink($first);
        $this->assertSame(0, $service->run($this->directory, true)['updated_atas']);
        $this->assertDatabaseCount('tb_ADITIVOS_ATA', 2);
    }

    public function test_pending_amendment_is_linked_when_original_ata_is_later_registered(): void
    {
        $this->pdf('007/2026');
        $service = app(UpdateAtaValidity::class);
        $service->run($this->directory, true);
        $id = $this->ata('007/2026');
        $service->run($this->directory, true);
        $this->assertDatabaseHas('tb_ADITIVOS_ATA', ['ID_ATA' => $id, 'link_status' => 'vinculado']);
        $this->assertDatabaseHas('tb_ATA', ['ID' => $id, 'FINAL_ATA' => '2027-06-12']);
    }

    public function test_audit_failure_rolls_back_dates_amendments_and_version(): void
    {
        $id = $this->ata('007/2026');
        $this->pdf('007/2026');
        ActivityEvent::creating(fn () => throw new RuntimeException('audit unavailable'));
        try {
            app(UpdateAtaValidity::class)->run($this->directory, true);
            $this->fail('Expected audit failure');
        } catch (RuntimeException $error) {
            $this->assertSame('audit unavailable', $error->getMessage());
        } finally {
            ActivityEvent::flushEventListeners();
        }
        $this->assertDatabaseHas('tb_ATA', ['ID' => $id, 'INICIO_ATA' => null, 'FINAL_ATA' => null, 'version' => 1]);
        $this->assertDatabaseCount('tb_ADITIVOS_ATA', 0);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_deleted_atas_and_unknown_periods_are_preserved(): void
    {
        $deleted = $this->ata('007/2026', ['deleted_at' => now()]);
        $invalid = $this->ata('008/2026', ['PERIODO_ATA' => 'a definir']);
        $result = app(UpdateAtaValidity::class)->run($this->directory, true);
        $this->assertSame(1, count($result['skipped_atas']));
        foreach ([$deleted, $invalid] as $id) {
            $this->assertDatabaseHas('tb_ATA', ['ID' => $id, 'FINAL_ATA' => null, 'version' => 1]);
        }
    }

    public function test_manual_date_change_after_update_is_not_silently_overwritten(): void
    {
        $id = $this->ata('007/2026');
        $service = app(UpdateAtaValidity::class);
        $service->run($this->directory, true);
        DB::table('tb_ATA')->where('ID', $id)->update(['FINAL_ATA' => '2030-01-01']);
        $this->expectExceptionMessage('foram alteradas');
        $service->run($this->directory, true);
    }

    public function test_conflicting_ends_for_same_amendment_ordinal_leave_ata_untouched(): void
    {
        $id = $this->ata('007/2026');
        $file = $this->pdf('007/2026');
        copy($file, $this->directory.'/Alternative_ATA_007_2026.pdf');
        $this->pdf('007/2026', '13/06/2026', '12/07/2027');
        $result = app(UpdateAtaValidity::class)->run($this->directory, true);
        $this->assertSame('aditivos_do_mesmo_numero_com_finais_divergentes', $result['skipped_atas'][0]['reason']);
        $this->assertDatabaseHas('tb_ATA', ['ID' => $id, 'FINAL_ATA' => null, 'version' => 1]);
    }

    public function test_command_previews_by_default_and_changed_source_is_not_overwritten(): void
    {
        $this->ata('007/2026');
        $file = $this->pdf('007/2026');
        $this->artisan('atas:update-validity', ['directory' => $this->directory])->assertSuccessful();
        $this->assertDatabaseCount('tb_ADITIVOS_ATA', 0);
        $this->artisan('atas:update-validity', ['directory' => $this->directory, '--apply' => true])->assertSuccessful();
        file_put_contents($file, "\n% modified source", FILE_APPEND);
        $this->expectExceptionMessage('Origem do aditivo');
        app(UpdateAtaValidity::class)->run($this->directory, true);
    }

    public function test_parser_accepts_written_dates_and_rejects_hidden_conflicting_identifiers(): void
    {
        $reader = new AtaAmendmentReader;
        $text = $this->text('007/2026', '13 de junho de 2026', '12 de junho de 2027');
        $entry = $reader->parse($text, 'Termo_ATA_007_2026.pdf');
        $this->assertSame([], $entry['issues']);
        $this->assertSame('2027-06-12', $entry['FINAL_ADITIVO']);
        $finalOnly = str_replace(['PROCESSO ADMINISTRATIVO BASE', 'de 13 de junho de 2026 a 12 de junho de 2027'], ['PROCESSO DA ATA', 'por 12 meses, com termo final em 12 de junho de 2027'], $text);
        $finalEntry = $reader->parse($finalOnly, 'Termo_ATA_007_2026.pdf');
        $this->assertSame([], $finalEntry['issues']);
        $this->assertSame('1234567890', $finalEntry['PROCESSO_ORIGEM']);
        $this->assertSame('2027-06-12', $finalEntry['FINAL_ADITIVO']);
        $conflicting = $reader->parse($text.' ATA DE REGISTRO DE PREÇOS Nº 008/2026', 'Termo_ATA_007_2026.pdf');
        $this->assertContains('identificacao_da_ata_ambigua_ou_divergente', $conflicting['issues']);
    }

    public function test_scanned_pdf_is_read_by_ocr(): void
    {
        $file = $this->pdf('007/2026');
        $render = new Process(['pdftoppm', '-f', '1', '-l', '1', '-scale-to', '2200', '-png', $file, $this->directory.'/scan']);
        $render->mustRun();
        $scan = new FPDF;
        $scan->AddPage();
        $scan->Image($this->directory.'/scan-1.png', 0, 0, 210, 297);
        $scan->Output('F', $file);
        $entry = app(AtaAmendmentReader::class)->read($this->directory)[0];
        $this->assertSame('ocr_por', $entry['extraction']);
        $this->assertSame('007/2026', $entry['ATA']);
        $this->assertSame('2027-06-12', $entry['FINAL_ADITIVO']);
    }

    private function ata(string $number, array $values = []): int
    {
        return DB::table('tb_ATA')->insertGetId(array_replace(['ATA' => $number, 'DATA_ATA' => '2026-03-11', 'PROCESSO' => '1234567890',
            'LICITACAO' => 'PREGÃO ELETRÔNICO', 'N_LICIT' => '001/2026', 'CNPJ_FUNDO' => '00.000.000/0000-01', 'CNPJ_FORNECEDOR' => '00.000.000/0000-03',
            'PERIODO_ATA' => '12 (doze) meses', 'source_files' => '[]', 'quality_issues' => '["vigencia_pendente_publicacao_pncp","ano_data_diverge_ano_ata"]', 'import_hash' => str_repeat('0', 64)], $values), 'ID');
    }

    private function text(string $ata, string $start, string $end, int $ordinal = 1): string
    {
        return "TERMO ADITIVO $ordinal\nATA DE REGISTRO DE PREÇOS Nº $ata\nPREGÃO ELETRÔNICO Nº 001/2026\nPROCESSO ADMINISTRATIVO BASE Nº 1234567890\nPROCESSO DE PRORROGAÇÃO Nº 9876543210\nCNPJ GERENCIADOR: 00.000.000/0000-01\nCNPJ FORNECEDOR: 00.000.000/0000-03\nCLÁUSULA SEGUNDA - DA VIGÊNCIA\nA vigência fica prorrogada de $start a $end.\nCLÁUSULA TERCEIRA - DA RATIFICAÇÃO";
    }

    private function pdf(string $ata, string $start = '13/06/2026', string $end = '12/06/2027', int $ordinal = 1): string
    {
        $file = $this->directory.'/Termo_'.$ordinal.'_ATA_'.str_replace('/', '_', $ata).'.pdf';
        $pdf = new FPDF;
        $pdf->AddPage();
        $pdf->SetFont('Arial', '', 12);
        $pdf->MultiCell(190, 7, mb_convert_encoding($this->text($ata, $start, $end, $ordinal), 'Windows-1252', 'UTF-8'));
        $pdf->Output('F', $file);

        return $file;
    }
}
