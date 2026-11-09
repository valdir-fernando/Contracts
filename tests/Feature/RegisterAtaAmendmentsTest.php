<?php

namespace Tests\Feature;

use App\Infrastructure\Atas\RegisterAtaAmendments;
use App\Models\ActivityEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class RegisterAtaAmendmentsTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('app/private/register-'.bin2hex(random_bytes(8)));
        mkdir($this->directory, 0700, true);
    }

    public function test_preview_then_register_preserves_pdf_bytes_and_repeat_is_a_noop(): void
    {
        $review = $this->review();
        $service = new RegisterAtaAmendments;
        $this->assertSame(1, $service->run($this->directory, [$review])['inserted']);
        $this->assertDatabaseCount('tb_ADT_ATA', 0);
        $result = $service->run($this->directory, [$review], true);
        $this->assertSame(1, $result['attached']);
        $pdf = DB::table('tb_ADT_ATA')->value('Doc_ADT');
        $this->assertSame($review['source_sha256'], hash('sha256', $pdf));
        $repeat = $service->run($this->directory, [$review], true);
        $this->assertSame(0, $repeat['inserted']);
        $this->assertSame(0, $repeat['attached']);
        $this->assertDatabaseCount('activity_events', 1);
        $this->assertDatabaseCount('tb_ADITIVOS_ATA', 0);
        $this->assertDatabaseCount('tb_ATA', 0);
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 0);
        $event = ActivityEvent::first();
        $this->assertSame($review['source_sha256'], $event->metadata['changes'][0]['source_sha256']);
        $this->assertStringNotContainsString('%PDF-', json_encode($event->metadata));
    }

    public function test_only_one_party_is_registered_without_pdf_then_two_parties_can_attach_once(): void
    {
        $review = $this->review();
        $oneParty = $review;
        $oneParty['signatures']['contracted'] = null;
        $service = new RegisterAtaAmendments;
        $result = $service->run($this->directory, [$oneParty], true);
        $this->assertSame(1, $result['without_attachment']);
        $this->assertNull(DB::table('tb_ADT_ATA')->value('Doc_ADT'));
        $result = $service->run($this->directory, [$review], true);
        $this->assertSame(1, $result['attached']);
        $this->assertDatabaseCount('tb_ADT_ATA', 1);
        $this->assertDatabaseCount('activity_events', 2);
    }

    public function test_unsigned_instrument_is_registered_without_attachment(): void
    {
        $review = $this->review();
        $review['signatures'] = ['contracting' => null, 'contracted' => null, 'method' => 'absent', 'page' => 1];
        (new RegisterAtaAmendments)->run($this->directory, [$review], true);
        $this->assertDatabaseHas('tb_ADT_ATA', ['ATA' => '104/2025', 'Doc_ADT' => null]);
    }

    public function test_excluded_folder_is_rejected_even_with_both_signatures(): void
    {
        $review = $this->review();
        config(['atas.unsigned_amendment_directories' => [$this->directory]]);
        $this->expectExceptionMessage('pasta excluída');
        (new RegisterAtaAmendments)->run($this->directory, [$review], true);
    }

    public function test_changed_pdf_is_rejected_before_any_writes(): void
    {
        $review = $this->review();
        file_put_contents($this->directory.'/'.$review['source_path'], '%PDF-changed');
        try {
            (new RegisterAtaAmendments)->run($this->directory, [$review], true);
            $this->fail('A alteração deveria ter sido recusada.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('alterado', $error->getMessage());
        }
        $this->assertDatabaseCount('tb_ADT_ATA', 0);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_conflict_rolls_back_preceding_inserts_without_overwriting_existing_data(): void
    {
        $review = $this->review();
        DB::table('tb_ADT_ATA')->insert([...$review['fields'], 'Proc_ADT' => 'different']);
        $first = $review;
        $first['fields']['ATA'] = '103/2025';
        try {
            (new RegisterAtaAmendments)->run($this->directory, [$first, $review], true);
            $this->fail('O conflito deveria ter sido recusado.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('divergente', $error->getMessage());
        }
        $this->assertDatabaseCount('tb_ADT_ATA', 1);
        $this->assertDatabaseHas('tb_ADT_ATA', ['Proc_ADT' => 'different']);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_audit_failure_rolls_back_binary_and_registration(): void
    {
        $review = $this->review();
        ActivityEvent::creating(fn () => throw new RuntimeException('audit failed'));
        try {
            (new RegisterAtaAmendments)->run($this->directory, [$review], true);
            $this->fail('A falha de auditoria deveria ser propagada.');
        } catch (RuntimeException $error) {
            $this->assertSame('audit failed', $error->getMessage());
        } finally {
            ActivityEvent::flushEventListeners();
        }
        $this->assertDatabaseCount('tb_ADT_ATA', 0);
    }

    public function test_duplicate_identity_in_review_is_rejected(): void
    {
        $review = $this->review();
        $this->expectExceptionMessage('duplicado');
        (new RegisterAtaAmendments)->run($this->directory, [$review, $review], true);
    }

    public function test_an_existing_pdf_is_never_replaced(): void
    {
        $review = $this->review();
        DB::table('tb_ADT_ATA')->insert([...$review['fields'], 'Doc_ADT' => '%PDF-existing']);
        $this->expectExceptionMessage('PDF existente divergente');
        (new RegisterAtaAmendments)->run($this->directory, [$review], true);
    }

    public function test_review_cannot_read_a_file_outside_the_source_directory(): void
    {
        $review = $this->review();
        mkdir($this->directory.'/child');
        $review['source_path'] = '../'.$review['source_path'];
        $this->expectExceptionMessage('fora da pasta');
        (new RegisterAtaAmendments)->run($this->directory.'/child', [$review], true);
    }

    private function review(): array
    {
        $pdf = "%PDF-1.7\n".random_bytes(131072)."\x00\xff\n%%EOF";
        file_put_contents($this->directory.'/Assinado_Assinado.pdf', $pdf);

        return ['fields' => ['ATA' => '104/2025', 'ADT' => 1, 'Proc_ATA' => '2025005312',
            'Data_ADT' => '2026-10-20', 'Proc_ADT' => '2026015174', 'Tipo_ADT' => 'Prorrogação de vigência',
            'INICIO' => '2026-10-21', 'FINAL' => '2027-10-20', 'Período' => '12 meses'],
            'source_path' => 'Assinado_Assinado.pdf', 'source_sha256' => hash('sha256', $pdf),
            'signatures' => ['contracting' => 'Representante da contratante', 'contracted' => 'Representante da contratada', 'method' => 'visual', 'page' => 3]];
    }
}
