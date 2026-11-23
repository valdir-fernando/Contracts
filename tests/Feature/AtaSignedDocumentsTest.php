<?php

namespace Tests\Feature;

use App\Models\User;
use FPDF;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtaSignedDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private int $ata;

    private string $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->actingAs(User::create(['name' => 'Admin', 'username' => 'ATA-PDF', 'password' => 'SyntheticPassword123',
            'user_type' => 'Administrador'])->fresh());
        $this->ata = DB::table('tb_ATA')->insertGetId([
            'ATA' => '001/2026', 'PROCESSO' => 'PROC-TESTE', 'LICITACAO' => 'Pregão', 'N_LICIT' => '001/26',
            'CNPJ_FUNDO' => '04252011000110', 'FORNECEDOR' => 'Comércio / Sul: ME',
            'source_files' => '[]', 'quality_issues' => '[]', 'import_hash' => str_repeat('b', 64), 'version' => 1,
        ], 'ID');
        $pdf = new FPDF;
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->Text(20, 20, 'CPF DOCUMENTO SINTETICO');
        $this->original = $pdf->Output('S');
    }

    public function test_review_stores_original_and_rasterized_copy_and_downloads_both_privately(): void
    {
        $this->get(route('atas.create'))->assertOk()->assertSee('id="ata-signed-file"', false)
            ->assertSee('REMOÇÃO DE DOCUMENTOS PDF - Nº ATA');
        $preview = $this->postJson($this->url('preview'), $this->input())->assertOk()
            ->assertJsonPath('sha256', hash('sha256', $this->original))->assertJsonCount(1, 'pages');
        $this->assertStringStartsWith('data:image/jpeg;base64,', $preview->json('pages.0'));
        $this->assertNull(DB::table('tb_ATA')->where('ID', $this->ata)->value('Doc_ATA'));

        $this->postJson($this->url('store'), $this->review())->assertCreated()->assertJsonPath('version', 2);
        $this->getJson(route('atas.lookup', ['number' => '001/2026']))->assertOk()
            ->assertJsonPath('has_signed_document_copies', true)->assertJsonMissingPath('Doc_ATA');
        $download = fn ($copy, $suffix) => $this->get($this->url('download', ['copy' => $copy]))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Content-Disposition',
                "attachment; filename=Ata_001_2026_Comercio_Sul_ME{$suffix}.pdf; filename*=utf-8''Ata_001_2026_Com%C3%A9rcio_Sul_ME{$suffix}.pdf")
            ->getContent();
        $this->assertSame($this->original, $download('original', '_Assinado'));
        $redacted = $download('redacted', '_Assinado_Tarjado');
        $this->assertStringStartsWith('%PDF-', $redacted);
        $images = new Process(['pdfimages', '-list', '-']);
        $images->setInput($redacted)->mustRun();
        $this->assertMatchesRegularExpression('/\bimage\s+\d+\s+3508\s+rgb\s+3\s+8\s+image\b/', $images->getOutput());
        $text = new Process(['pdftotext', '-nopgbrk', '-', '-']);
        $text->setInput($redacted)->mustRun();
        $this->assertSame('', trim($text->getOutput()));
        $image = new Process(['pdftoppm', '-singlefile', '-scale-to', '1800', '-png']);
        $image->setInput($redacted)->mustRun();
        $page = imagecreatefromstring($image->getOutput());
        $this->assertSame(0, imagecolorat($page, (int) (imagesx($page) * .1), (int) (imagesy($page) * .07)) & 0xFFFFFF);
        $this->assertSame(0xFFFFFF, imagecolorat($page, (int) (imagesx($page) * .8), (int) (imagesy($page) * .7)) & 0xFFFFFF);
        $this->assertDatabaseHas('activity_events', ['action' => 'ata_signed_documents_uploaded']);
        $this->postJson($this->url('store'), $this->review(['version' => 2]))->assertConflict();

        DB::table('ata_signed_document_copies')->where('ata_id', $this->ata)->update(['redacted_pdf' => 'corrupted']);
        $this->get($this->url('download', ['copy' => 'redacted']))->assertConflict();
        DB::table('tb_ATA')->where('ID', $this->ata)->update(['deleted_at' => now()]);
        $this->get($this->url('download', ['copy' => 'original']))->assertNotFound();
    }

    public function test_invalid_file_review_version_and_permissions_leave_no_copy(): void
    {
        $this->postJson($this->url('preview'), $this->input(['pdf' => UploadedFile::fake()->createWithContent('fake.pdf', 'not a PDF')]))
            ->assertUnprocessable()->assertJsonValidationErrors('pdf');
        $this->postJson($this->url('store'), $this->review(['sha256' => str_repeat('a', 64)]))->assertConflict();
        $this->postJson($this->url('store'), $this->review(['pages' => 2]))->assertUnprocessable()->assertJsonValidationErrors('pdf');
        $this->postJson($this->url('store'), $this->review(['version' => 2]))->assertConflict();

        $this->actingAs(User::create(['name' => 'Usuário', 'username' => 'ATA-PDF-USER', 'password' => 'SyntheticPassword123',
            'user_type' => 'Usuario'])->fresh());
        $this->postJson($this->url('preview'), $this->input())->assertForbidden();
        $this->postJson($this->url('store'), $this->review())->assertForbidden();
        $this->deleteJson($this->url('destroy'), ['version' => 1])->assertForbidden();
        $this->get($this->url('download', ['copy' => 'original']))->assertForbidden();
        $this->assertDatabaseCount('ata_signed_document_copies', 0);
        $this->assertDatabaseMissing('activity_events', ['action' => 'ata_signed_documents_uploaded']);
        $this->assertNull(DB::table('tb_ATA')->where('ID', $this->ata)->value('Doc_ATA'));
    }

    public function test_removal_blocks_both_downloads_and_preserves_history_after_replacement(): void
    {
        $this->postJson($this->url('store'), $this->review())->assertCreated();
        $previous = DB::table('ata_signed_document_copies')->where('ata_id', $this->ata)->first();
        $this->deleteJson($this->url('destroy'), ['version' => 1])->assertConflict();
        $this->deleteJson($this->url('destroy'), ['version' => 2, 'copy' => 'invalid'])->assertUnprocessable();
        $this->deleteJson($this->url('destroy'), ['version' => 2, 'copy' => 'original'])->assertOk()
            ->assertJsonPath('version', 3)->assertJsonPath('signed_document_copies', ['redacted']);
        $this->get($this->url('download', ['copy' => 'original']))->assertNotFound();
        $this->get($this->url('download', ['copy' => 'redacted']))->assertOk();
        $this->getJson(route('atas.lookup', ['number' => '001/2026']))->assertOk()
            ->assertJsonPath('signed_document_copies', ['redacted']);
        $this->deleteJson($this->url('destroy'), ['version' => 3, 'copy' => 'original'])->assertNotFound();
        $this->deleteJson($this->url('destroy'), ['version' => 3, 'copy' => 'redacted'])->assertOk()
            ->assertJsonPath('version', 4)->assertJsonPath('signed_document_copies', []);
        foreach (['original', 'redacted'] as $copy) {
            $this->get($this->url('download', ['copy' => $copy]))->assertNotFound();
        }
        $this->getJson(route('atas.lookup', ['number' => '001/2026']))->assertOk()
            ->assertJsonPath('has_signed_document_copies', false);
        $this->assertDatabaseHas('activity_events', ['action' => 'ata_signed_documents_removed']);
        $this->assertSame($this->original, DB::table('tb_ATA')->where('ID', $this->ata)->value('Doc_ATA'));

        $this->postJson($this->url('store'), $this->review(['version' => 4, 'rectangles' => []]))
            ->assertCreated()->assertJsonPath('version', 5);
        $archive = DB::table('ata_signed_document_history')->where('ata_id', $this->ata)->first();
        $this->assertSame($this->original, $archive->original_pdf);
        $this->assertSame($previous->redacted_pdf, $archive->redacted_pdf);
        $this->assertNotSame($previous->redacted_sha256,
            DB::table('ata_signed_document_copies')->where('ata_id', $this->ata)->value('redacted_sha256'));
        $this->get($this->url('download', ['copy' => 'redacted']))->assertOk();
    }

    public function test_original_can_be_saved_alone_and_redacted_later_without_uploading_it_again(): void
    {
        $this->postJson($this->url('store'), $this->input(['mode' => 'original']))->assertCreated()
            ->assertJsonPath('version', 2)->assertJsonPath('signed_document_copies', ['original'])
            ->assertJsonPath('can_create_redacted_copy', true);
        $this->get($this->url('download', ['copy' => 'redacted']))->assertNotFound();
        $this->getJson(route('atas.lookup', ['number' => '001/2026']))->assertOk()
            ->assertJsonPath('can_create_redacted_copy', true);
        $this->postJson($this->url('preview'), ['version' => 2, 'mode' => 'stored'])
            ->assertOk()->assertJsonCount(1, 'pages')->assertJsonPath('sha256', hash('sha256', $this->original));

        $review = $this->review(['version' => 2, 'mode' => 'stored']);
        unset($review['pdf']);
        $this->postJson($this->url('store'), array_replace($review, ['sha256' => str_repeat('a', 64)]))->assertConflict();
        $this->postJson($this->url('store'), $review)->assertCreated()->assertJsonPath('version', 3)
            ->assertJsonPath('signed_document_copies', ['original', 'redacted'])->assertJsonPath('can_create_redacted_copy', false);
        $this->assertSame($this->original, $this->get($this->url('download', ['copy' => 'original']))->assertOk()->getContent());
        $this->get($this->url('download', ['copy' => 'redacted']))->assertOk();
        $this->postJson($this->url('preview'), ['version' => 3, 'mode' => 'stored'])->assertConflict();
        $this->assertDatabaseHas('activity_events', ['action' => 'ata_signed_original_uploaded']);
        $this->assertDatabaseHas('activity_events', ['action' => 'ata_signed_redacted_copy_created']);
    }

    public function test_removing_the_redacted_pdf_allows_a_new_copy_from_the_stored_original(): void
    {
        $this->postJson($this->url('store'), $this->review())->assertCreated();
        $previous = DB::table('ata_signed_document_copies')->where('ata_id', $this->ata)->first();
        $this->deleteJson($this->url('destroy'), ['version' => 2, 'copy' => 'redacted'])->assertOk()
            ->assertJsonPath('version', 3)->assertJsonPath('signed_document_copies', ['original'])
            ->assertJsonPath('can_create_redacted_copy', true);
        $this->get($this->url('download', ['copy' => 'redacted']))->assertNotFound();
        $this->getJson(route('atas.lookup', ['number' => '001/2026']))->assertOk()
            ->assertJsonPath('can_create_redacted_copy', true);
        $this->postJson($this->url('preview'), ['version' => 3, 'mode' => 'stored'])
            ->assertOk()->assertJsonPath('sha256', hash('sha256', $this->original));

        $review = $this->review(['version' => 3, 'mode' => 'stored', 'rectangles' => []]);
        unset($review['pdf']);
        $this->postJson($this->url('store'), $review)->assertCreated()->assertJsonPath('version', 4)
            ->assertJsonPath('signed_document_copies', ['original', 'redacted'])
            ->assertJsonPath('can_create_redacted_copy', false);
        $this->assertSame($this->original, $this->get($this->url('download', ['copy' => 'original']))->assertOk()->getContent());
        $this->assertNotSame($previous->redacted_pdf, $this->get($this->url('download', ['copy' => 'redacted']))->assertOk()->getContent());
        $archived = DB::table('ata_signed_document_history')->where('ata_id', $this->ata)->sole();
        $this->assertSame($previous->redacted_pdf, $archived->redacted_pdf);
        $this->assertSame($previous->redacted_sha256, $archived->redacted_sha256);
    }

    public function test_removing_an_original_without_a_copy_allows_a_new_upload_and_preserves_the_original(): void
    {
        $this->postJson($this->url('store'), $this->input(['mode' => 'original']))->assertCreated();
        $this->deleteJson($this->url('destroy'), ['version' => 2, 'copy' => 'redacted'])->assertNotFound();
        $this->deleteJson($this->url('destroy'), ['version' => 2, 'copy' => 'original'])->assertOk()
            ->assertJsonPath('signed_document_copies', []);
        $this->postJson($this->url('store'), $this->input(['mode' => 'original', 'version' => 3]))->assertCreated();
        $archived = DB::table('ata_signed_document_history')->where('ata_id', $this->ata)->first();
        $this->assertSame($this->original, $archived->original_pdf);
        $this->assertNull($archived->redacted_pdf);
    }

    public function test_amendment_pdf_control_can_file_original_later_redact_and_remove_only_its_copy(): void
    {
        $amendment = DB::table('tb_ADT_ATA')->insertGetId(['ATA' => '001/26', 'Proc_ATA' => 'PROC-TESTE', 'ADT' => 3,
            'Doc_ADT' => $this->original], 'ID');
        $url = fn ($action, $arguments = []) => route('atas.amendments.signed-document.'.$action,
            ['ata' => $this->ata, 'amendment' => $amendment] + $arguments);
        $this->get(route('atas.create'))->assertOk()
            ->assertSeeInOrder(['data-amendment-signed-downloads', 'ata-amend-actions', 'VL_Atualizado_ATA', 'Aditivo assinado (PDF)', '>Aditivo</span>'], false);
        $this->postJson($url('store'), $this->input(['mode' => 'original']))->assertCreated()
            ->assertJsonPath('signed_document_copies', ['original'])->assertJsonPath('version', 2);
        $this->getJson(route('atas.lookup', ['number' => '001/2026']))->assertOk()
            ->assertJsonPath('amendment_signed_documents.3.id', $amendment)
            ->assertJsonPath('amendment_signed_documents.3.copies', ['original'])->assertJsonPath('signed_document_copies', []);
        $this->postJson($url('preview'), ['mode' => 'stored', 'version' => 2])->assertOk()
            ->assertJsonPath('sha256', hash('sha256', $this->original))->assertJsonCount(1, 'pages');
        $review = $this->review(['mode' => 'stored', 'version' => 2]);
        unset($review['pdf']);
        $this->postJson($url('store'), $review)->assertCreated()->assertJsonPath('version', 3)
            ->assertJsonPath('signed_document_copies', ['original', 'redacted']);
        $this->assertSame($this->original, $this->get($url('download', ['copy' => 'original']))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Content-Disposition', "attachment; filename=Aditivo_3_ATA_001_2026_Comercio_Sul_ME_Assinado.pdf; filename*=utf-8''Aditivo_3_ATA_001_2026_Com%C3%A9rcio_Sul_ME_Assinado.pdf")
            ->getContent());
        $redacted = $this->get($url('download', ['copy' => 'redacted']))->assertOk()->getContent();
        $this->assertStringStartsWith('%PDF-', $redacted);
        $this->assertNotSame($redacted, $this->original);
        $this->deleteJson($url('destroy'), ['copy' => 'redacted', 'version' => 3])->assertOk()
            ->assertJsonPath('signed_document_copies', ['original'])->assertJsonPath('can_create_redacted_copy', true);
        $this->get($url('download', ['copy' => 'redacted']))->assertNotFound();
        $this->assertSame($redacted, DB::table('ata_amendment_signed_documents')->where('removed_copy', 'redacted')->value('redacted_pdf'));
        $this->assertSame($this->original, DB::table('tb_ADT_ATA')->where('ID', $amendment)->value('Doc_ADT'));
        $this->assertNull(DB::table('tb_ATA')->where('ID', $this->ata)->value('Doc_ATA'));
        $this->assertDatabaseHas('activity_events', ['action' => 'ata_amendment_signed_documents_stored']);
        $this->assertDatabaseHas('activity_events', ['action' => 'ata_amendment_signed_documents_removed']);
    }

    public function test_amendment_files_require_current_ata_and_an_authorized_active_matching_amendment(): void
    {
        $amendment = DB::table('tb_ADT_ATA')->insertGetId(['ATA' => '001/26', 'Proc_ATA' => 'OUTRO', 'ADT' => 3], 'ID');
        $url = route('atas.amendments.signed-document.store', ['ata' => $this->ata, 'amendment' => $amendment]);
        $this->postJson($url, $this->input(['mode' => 'original']))->assertNotFound();
        DB::table('tb_ADT_ATA')->where('ID', $amendment)->update(['Proc_ATA' => 'PROC-TESTE']);
        $this->postJson($url, $this->input(['mode' => 'original', 'version' => 2]))->assertConflict();
        DB::table('tb_ADT_ATA')->where('ID', $amendment)->update(['deleted_at' => now()]);
        $this->postJson($url, $this->input(['mode' => 'original']))->assertNotFound();
        $this->actingAs(User::create(['name' => 'Usuário', 'username' => 'AMENDMENT-PDF-USER', 'password' => 'SyntheticPassword123',
            'user_type' => 'Usuario'])->fresh());
        $this->postJson($url, $this->input(['mode' => 'original']))->assertForbidden();
        $this->assertDatabaseCount('ata_amendment_signed_documents', 0);
    }

    private function input(array $overrides = []): array
    {
        return $overrides + ['version' => 1, 'pdf' => UploadedFile::fake()->createWithContent('assinada.pdf', $this->original)];
    }

    private function review(array $overrides = []): array
    {
        return $this->input($overrides + [
            'reviewed' => '1', 'sha256' => hash('sha256', $this->original), 'pages' => 1,
            'rectangles' => [['page' => 1, 'left' => .08, 'top' => .05, 'right' => .5, 'bottom' => .1]],
        ]);
    }

    private function url(string $action, array $arguments = []): string
    {
        return route('atas.signed-document.'.$action, ['ata' => $this->ata] + $arguments);
    }
}
