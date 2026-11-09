<?php

namespace Tests\Feature;

use App\Infrastructure\Atas\AtaNumbering;
use App\Infrastructure\Atas\AtaProcessEvidenceReader;
use App\Infrastructure\Atas\ReconcileAtaProcessValidity;
use App\Models\ActivityEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ReconcileAtaProcessValidityTest extends TestCase
{
    use RefreshDatabase;

    private array $entry;

    protected function setUp(): void
    {
        parent::setUp();
        $source = storage_path('app/private/process-fixture-'.bin2hex(random_bytes(8)).'.pdf');
        file_put_contents($source, 'synthetic documentary source');
        $this->entry = ['ATA' => '003/2025', 'DATA_ATA' => '2025-06-13', 'PROCESSO' => '2025003415', 'N_LICIT' => '003/2025',
            'PERIODO_ATA' => '1 (um) ano', 'FINAL_ATA' => '2026-06-13', 'FORNECEDOR' => 'Empresa fictícia', 'CNPJ_FORNECEDOR' => '00.000.000/0000-03',
            'FUNDO' => 'FUNDO MUNICIPAL FICTÍCIO', 'CNPJ_FUNDO' => '00.000.000/0000-01', 'OBJETO DA ATA' => 'Material fictício', 'pages' => [3, 4],
            'source' => ['file' => $source, 'sha256' => hash_file('sha256', $source), 'size' => filesize($source), 'pages' => [3, 4], 'kind' => 'copia_ata_original_em_processo']];
    }

    public function test_missing_original_is_registered_with_original_term_and_repeat_is_idempotent(): void
    {
        $service = $this->service();
        $this->assertSame(1, $service->run('/fixture')['inserted']);
        $this->assertDatabaseCount('tb_ATA', 0);
        $this->assertSame(1, $service->run('/fixture', true)['inserted']);
        $this->assertDatabaseHas('tb_ATA', ['ATA' => '003/2025', 'DATA_ATA' => '2025-06-13', 'INICIO_ATA' => '2025-06-13', 'FINAL_ATA' => '2026-06-13']);
        $this->assertDatabaseHas('tb_N_ATA', ['ANO' => 2025, 'NUMERO' => 3]);
        $this->assertDatabaseCount('tb_ADITIVOS_ATA', 0);
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 0);
        $this->assertDatabaseCount('tb_FUNDOS_ATA', 1);
        $this->assertSame(1, $service->run('/fixture', true)['confirmed']);
        $this->assertDatabaseCount('activity_events', 1);
    }

    public function test_existing_dates_are_corrected_without_replacing_products_or_personal_data(): void
    {
        $service = $this->service();
        $service->run('/fixture', true);
        DB::table('tb_ATA')->update(['INICIO_ATA' => null, 'FINAL_ATA' => null, 'validity_basis' => null, 'EMAIL' => 'preservado@example.test']);
        $this->assertSame(1, $service->run('/fixture', true)['updated']);
        $this->assertDatabaseHas('tb_ATA', ['FINAL_ATA' => '2026-06-13', 'EMAIL' => 'preservado@example.test', 'version' => 2]);
        $this->assertDatabaseCount('activity_events', 2);
    }

    public function test_confirmed_signed_extension_is_not_shortened_by_original_copy(): void
    {
        $service = $this->service();
        $service->run('/fixture', true);
        $id = DB::table('tb_ATA')->value('ID');
        DB::table('tb_ATA')->update(['FINAL_ATA' => '2027-06-12']);
        DB::table('tb_ADITIVOS_ATA')->insert(['ID_ATA' => $id, 'ATA' => '003/2025', 'NUMERO' => 1, 'source_path' => 'signed.pdf',
            'source_sha256' => str_repeat('a', 64), 'source_size' => 10, 'extraction' => 'pdf_text', 'link_status' => 'vinculado', 'issues' => '[]']);
        $this->assertSame(1, $service->run('/fixture', true)['preserved_signed_extension']);
        $this->assertDatabaseHas('tb_ATA', ['FINAL_ATA' => '2027-06-12']);
    }

    public function test_audit_failure_rolls_back_ata_fund_and_annual_counter(): void
    {
        ActivityEvent::creating(fn () => throw new RuntimeException('audit unavailable'));
        try {
            $this->service()->run('/fixture', true);
            $this->fail('Expected failure');
        } catch (RuntimeException $error) {
            $this->assertSame('audit unavailable', $error->getMessage());
        } finally {
            ActivityEvent::flushEventListeners();
        }
        $this->assertDatabaseCount('tb_ATA', 0);
        $this->assertDatabaseCount('tb_FUNDOS_ATA', 0);
        $this->assertDatabaseCount('tb_N_ATA', 0);
    }

    public function test_changed_process_document_is_not_accepted(): void
    {
        file_put_contents($this->entry['source']['file'], 'changed');
        $this->expectExceptionMessage('Origem do processo alterada');
        $this->service()->run('/fixture', true);
    }

    public function test_copy_parser_uses_original_signature_and_period_and_ignores_request_and_draft(): void
    {
        $pages = ['PEDIDO DE ADITIVO. Niquelândia - GO, 10 de junho de 2026.',
            "ATA DE REGISTRO DE PREÇOS Nº 003/2025\nPREGÃO ELETRÔNICO 003/2025\nPROCESSO ADMINISTRATIVO N. 2025003415\nO FUNDO MUNICIPAL FICTÍCIO, pessoa jurídica, CNPJ 00.000.000/0000-01, considerando o julgamento. FORNECEDOR: Empresa fictícia, com sede na rua fictícia, CNPJ 00.000.000/0000-03. A listagem do cadastro de reserva.",
            'A validade da Ata de Registro de Preços será de 1 (um) ano, contado a partir da divulgação no PNCP.',
            'Niquelândia — Goiás, 13 de junho de 2025.',
            'TERMO ADITIVO 1. A vigência termina em 12/06/2027. Niquelândia - GO, 11 de junho de 2026.'];
        $entry = app(AtaProcessEvidenceReader::class)->parse($pages, 'ATA_003-25_Processo_2026008956');
        $this->assertSame('2025-06-13', $entry['DATA_ATA']);
        $this->assertSame('2026-06-13', $entry['FINAL_ATA']);
        $this->assertSame('2025003415', $entry['PROCESSO']);
        $this->assertSame([2, 3, 4], $entry['pages']);
        $this->assertSame('Empresa fictícia', $entry['FORNECEDOR']);
    }

    private function service(): ReconcileAtaProcessValidity
    {
        $reader = new class($this->entry) extends AtaProcessEvidenceReader
        {
            public function __construct(private array $entry) {}

            public function read(string $directory): array
            {
                return ['entries' => [$this->entry], 'pending' => []];
            }
        };

        return new ReconcileAtaProcessValidity($reader, app(AtaNumbering::class));
    }
}
