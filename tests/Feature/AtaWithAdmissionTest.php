<?php

namespace Tests\Feature;

use App\Infrastructure\Atas\AtaWithAdmissionPdf;
use App\Infrastructure\Atas\AtaWithAdmissionTemplate;
use App\Infrastructure\Atas\AtaWritePolicy;
use App\Infrastructure\Documents\DocumentSettings;
use App\Infrastructure\Security\LockActor;
use App\Models\AccessScope;
use App\Models\User;
use Closure;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AtaWithAdmissionTest extends TestCase
{
    use RefreshDatabase;

    private int $ata;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->actingAs(User::create(['name' => 'Emissão', 'username' => 'ATA-ADESAO', 'password' => 'SyntheticPassword123',
            'user_type' => 'Administrador'])->fresh());
        $this->ata = DB::table('tb_ATA')->insertGetId([
            'ATA' => '002/2025', 'PROCESSO' => 'PROC-TESTE', 'DATA_ATA' => '2025-06-02', 'LICITACAO' => 'Pregão Eletrônico',
            'N_LICIT' => '001/25', 'FUNDO' => 'Fundo Gerenciador', 'CNPJ_FUNDO' => '04252011000110',
            'ADMITIR_ADESAO' => 'SIM', 'OBJETO DA ATA' => 'Materiais de teste', 'PERIODO_ATA' => 'Doze meses',
            'INICIO_ATA' => '2025-06-03', 'FINAL_ATA' => '2026-06-02', 'source_files' => '[]',
            'quality_issues' => '[]', 'import_hash' => str_repeat('c', 64), 'version' => 1,
        ], 'ID');
        DB::table('document_settings')->where('id', 1)->update(['administration_start_year' => 2031, 'administration_end_year' => 2034]);
    }

    public function test_saved_fields_products_participants_and_manual_period_are_projected_into_the_model(): void
    {
        $this->updateAta(['QTD_ESPECIAL_NOME' => 'QTD Rural', 'FORNECEDOR' => '<Fornecedor teste>', 'CNPJ_FORNECEDOR' => '11222333000181',
            'END_FUNDO' => 'Rua do Fundo', 'MATRICULA_GESTOR' => '1234', 'SOCIO' => 'Representante teste']);
        $this->participant('Gerenciador duplicado', '04.252.011/0001-10');
        $this->participant('Fundo participante', '11222333000181');
        $this->participant('Fundo removido', '19131243000197', ['deleted_at' => now()]);
        $this->product('Produto cadastrado');
        $this->product('Produto de aditivo', ['ADITIVO' => 1]);
        $this->product('Produto excluído', ['ITEM' => '2', 'deleted_at' => now()]);

        $renderer = $this->renderer();
        $this->issue()->assertOk()->assertHeader('Content-Type', 'application/pdf');
        foreach (['2031/2034', '002/2025 - Processo: PROC-TESTE',
            'Rua do Fundo', '1234', '&lt;Fornecedor teste&gt;', '11.222.333/0001-81', 'Representante teste',
            'Fundo participante', 'QTD Rural', '>QTD<', 'Produto cadastrado', '1.234,5678', '4,0000',
            'Doze meses, iniciando-se em 03/06/2025 e término em 02/06/2026', '02 de junho de 2025'] as $expected) {
            $this->assertStringContainsString($expected, $renderer->html);
        }
        foreach (['Gerenciador duplicado', 'Fundo removido', 'Produto de aditivo', 'Produto excluído', '#DATA_ATA', '#TABELA DOS PRODUTOS'] as $excluded) {
            $this->assertStringNotContainsString($excluded, $renderer->html);
        }
        $this->assertDatabaseHas('activity_events', ['action' => 'ata_with_admission_generated']);
    }

    public function test_inpc_period_no_participants_and_no_special_quantity(): void
    {
        $this->updateAta(['VIGENCIA_ANO_INPC' => true]);
        $this->participant('Fundo Gerenciador', '04252011000110', ['papel' => 'gerenciador']);
        $renderer = $this->renderer();
        $this->issue()->assertOk();
        $this->assertStringContainsString('1 (um) ano, contado a partir do primeiro dia útil subsequente à data de divulgação no PNCP', $renderer->html);
        $this->assertStringContainsString('3.2 Além do gerenciador, não há participantes neste registro de preços', strip_tags($renderer->html));
        $this->assertStringContainsString('colspan="8"', $renderer->html);
        $this->assertStringNotContainsString('Doze meses', $renderer->html);
    }

    public function test_real_pdf_and_both_panel_buttons(): void
    {
        $this->product('Produto cadastrado');
        $response = $this->issue()->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $html = $this->get(route('atas.create'))->assertOk()->getContent();
        $this->assertSame(2, substr_count($html, 'data-ata-document-url='));
    }

    public function test_header_order_and_word_emphasis_are_preserved(): void
    {
        $this->updateAta(['FORNECEDOR' => 'Fornecedor teste', 'CNPJ_FORNECEDOR' => '11222333000181', 'SOCIO' => 'Representante teste']);
        $renderer = $this->renderer();
        $this->issue()->assertOk();

        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$renderer->html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);
        $this->assertSame('ATA: 002/2025 - Processo: PROC-TESTE', $xpath->evaluate('string(//header/div[@data-ata-pagination])'));
        $this->assertSame(1, $xpath->query('//header/div[@data-ata-pagination]/following-sibling::div[@class="separator"]')->length);
        $this->assertSame('ATA DE REGISTRO DE PREÇOS Nº 002/2025', $xpath->evaluate('string(//h1/strong)'));
        $this->assertSame('PROCESSO ADMINISTRATIVO N.', $xpath->evaluate('string(//p[strong="PROCESSO ADMINISTRATIVO N."]/strong)'));
        $this->assertSame('RESOLVE', $xpath->evaluate('string(//p/strong[.="RESOLVE"])'));
        $this->assertSame(1, $xpath->query('//p/u/strong[.="Dos limites para Adesões"]')->length);
        $this->assertSame(2, $xpath->query('//p[contains(@class,"supplier")]/strong')->length);
        $this->assertSame(2, $xpath->query('//table[@class="signatures"]/tbody/tr/td')->length);
    }

    public function test_ineligible_stale_and_changed_ata_do_not_emit(): void
    {
        $renderer = $this->renderer();
        $this->issue(2)->assertConflict();
        $this->updateAta(['ADMITIR_ADESAO' => 'NAO']);
        $this->issue()->assertUnprocessable();
        $this->assertSame('', $renderer->html);
        $this->updateAta(['ADMITIR_ADESAO' => 'SIM']);
        $renderer->during = fn () => $this->participant('Fundo novo', '11222333000181');
        $this->issue()->assertConflict()->assertDontSee('%PDF-');
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_fund_scope_and_supplier_contact_permissions_apply_to_the_pdf(): void
    {
        $fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo', 'name_key' => 'FUNDO-ATA', 'raw_payload' => '{}']);
        $this->updateAta(['fund_id' => $fund, 'EMAIL' => 'fornecedor@example.test', 'TELEFONE1' => '62999999999',
            'RG_SOCIO' => '123456789', 'CPF_SOCIO' => '52998224725']);
        $issuer = User::create(['name' => 'Emissor', 'username' => 'ATA-EMISSOR', 'password' => 'SyntheticPassword123',
            'user_type' => 'Usuario']);
        $issuer->forceFill(['catalog_permissions' => ['contract-issuance']])->save();
        $this->actingAs($issuer);
        $renderer = $this->renderer();
        $this->issue()->assertForbidden();
        $this->assertSame('', $renderer->html);
        $scope = AccessScope::create(['scope_key' => 'ATA-ADESAO-FUNDO', 'fund_id' => $fund, 'fund' => 'Fundo']);
        $issuer->scopes()->attach($scope);
        $this->issue()->assertOk();
        foreach (['62999999999', 'fornecedor@example.test', '123456789', '529.982.247-25', '52998224725'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $renderer->html);
        }
    }

    private function issue(int $version = 1)
    {
        return $this->getJson(route('atas.document', ['ata' => $this->ata, 'version' => $version]));
    }

    private function updateAta(array $fields): void
    {
        DB::table('tb_ATA')->where('ID', $this->ata)->update($fields);
    }

    private function participant(string $name, string $document, array $fields = []): void
    {
        DB::table('tb_FUNDOS_ATA')->insert($fields + ['ID_ATA' => $this->ata, 'ATA' => '002/2025',
            'CNPJ_FUNDO' => $document, 'FUNDO' => $name, 'papel' => 'participante']);
    }

    private function product(string $description, array $fields = []): void
    {
        DB::table('tb_PRODUTOS_ATA')->insert($fields + ['ID_ATA' => $this->ata, 'ATA' => '002/2025', 'PROCESSO' => 'PROC-TESTE',
            'ITEM' => '1', 'COD_PROD' => 'TESTE', 'LICITACAO' => 'Pregão', 'N_LICIT' => '001/25', 'DISCRIMINACAO' => $description,
            'UND' => 'UN', 'QTD' => '2.0000', 'QTD_ESPECIAL' => '4.0000', 'VL_UNIT' => '1234.5678', 'VL_TOTAL' => '2469.1356',
            'MARCA' => 'Marca teste', 'ADITIVO' => 0, 'source_row' => '{}']);
    }

    private function renderer(): object
    {
        $renderer = new class(app(LockActor::class), app(AtaWritePolicy::class), app(DocumentSettings::class), app(AtaWithAdmissionTemplate::class)) extends AtaWithAdmissionPdf
        {
            public string $html = '';

            public ?Closure $during = null;

            protected function render(string $html): string
            {
                $this->html = $html;
                ($this->during ?? fn () => null)();

                return '%PDF-test';
            }
        };
        $this->app->instance(AtaWithAdmissionPdf::class, $renderer);

        return $renderer;
    }
}
