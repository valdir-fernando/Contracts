<?php

namespace Tests\Feature;

use App\Infrastructure\Atas\AtaWithAdmissionPdf;
use App\Infrastructure\Atas\AtaWithAdmissionTemplate;
use App\Infrastructure\Atas\AtaWithAdmissionWord;
use App\Infrastructure\Atas\AtaWritePolicy;
use App\Infrastructure\Documents\DocumentSettings;
use App\Infrastructure\Security\LockActor;
use App\Models\AccessScope;
use App\Models\ActivityEvent;
use App\Models\User;
use Closure;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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

    public function test_word_is_an_editable_docx_with_the_same_content_and_formatting(): void
    {
        $this->updateAta(['FORNECEDOR' => '<Fornecedor & teste>', 'QTD_ESPECIAL_NOME' => 'QTD Rural']);
        $this->product('Produto cadastrado');
        $this->product('Produto excluído', ['ITEM' => '2', 'deleted_at' => now()]);
        $this->participant('Fundo participante', '11222333000181');
        $response = $this->getJson(route('atas.document', ['ata' => $this->ata, 'version' => 1, 'format' => 'docx']))
            ->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')
            ->assertHeader('Content-Disposition', 'attachment; filename="ata-'.$this->ata.'.docx"')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $path = tempnam(sys_get_temp_dir(), 'test-word-');
        try {
            file_put_contents($path, $response->getContent());
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($path));
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (str_ends_with($name, '.xml') || str_ends_with($name, '.rels')) {
                    $xml = new DOMDocument;
                    $this->assertTrue($xml->loadXML($zip->getFromName($name)), $name);
                }
            }
            $xml = new DOMDocument;
            $xml->loadXML($zip->getFromName('word/document.xml'));
            $xpath = new DOMXPath($xml);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            foreach (['<Fornecedor & teste>', 'Produto cadastrado', 'QTD Rural', 'Fundo participante', '1.234,5678', '11. CONDIÇÕES GERAIS'] as $value) {
                $this->assertStringContainsString($value, $xml->textContent);
            }
            $this->assertStringNotContainsString('Produto excluído', $xml->textContent);
            $this->assertSame(1, $xpath->query('//w:r[w:t="RESOLVE"]/w:rPr/w:b')->length);
            $this->assertSame(1, $xpath->query('//w:r[w:t="Dos limites para Adesões"]/w:rPr/w:u')->length);
            $this->assertGreaterThan(0, $xpath->query('//w:pPr/w:jc[@w:val="center"]')->length);
            $this->assertGreaterThan(0, $xpath->query('//w:pPr/w:jc[@w:val="both"]')->length);
            $this->assertGreaterThan(0, $xpath->query('//w:rFonts[@w:ascii="Arial"]')->length);
            $this->assertSame(1, $xpath->query('//w:sectPr/w:pgMar[@w:top="1960"]')->length);
            $this->assertStringContainsString('PROC-TESTE', $zip->getFromName('word/header1.xml'));
            $this->assertNotFalse($zip->getFromName('word/media/header.png'));
            $this->assertNotFalse($zip->getFromName('word/media/footer.png'));
            $zip->close();
        } finally {
            unlink($path);
        }
        $this->assertDatabaseHas('activity_events', ['action' => 'ata_with_admission_word_generated']);
    }

    public function test_word_rejects_stale_ineligible_unauthorized_and_deleted_atas(): void
    {
        $url = fn ($version = 1, $format = 'docx') => route('atas.document', ['ata' => $this->ata, 'version' => $version, 'format' => $format]);
        $this->getJson($url(2))->assertConflict();
        $this->getJson($url(1, 'html'))->assertUnprocessable();
        $this->updateAta(['ADMITIR_ADESAO' => 'NAO']);
        $this->getJson($url())->assertUnprocessable();
        $this->updateAta(['ADMITIR_ADESAO' => 'SIM', 'deleted_at' => now()]);
        $this->getJson($url())->assertNotFound();
        $this->updateAta(['deleted_at' => null]);
        $this->actingAs(User::create(['name' => 'Sem permissão', 'username' => 'WORD-DENIED', 'password' => 'SyntheticPassword123', 'user_type' => 'Usuario']));
        $this->getJson($url())->assertForbidden();
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_parallel_pdf_has_one_header_and_full_width_description_per_item_without_changing_saved_data(): void
    {
        $this->updateAta(['QTD_ESPECIAL_NOME' => 'Veículos']);
        $this->product('Descrição <item> & objeto');
        $this->product('Segundo objeto', ['ITEM' => '2']);
        $this->product('Excluído', ['ITEM' => '3', 'deleted_at' => now()]);
        $before = (array) DB::table('tb_ATA')->where('ID', $this->ata)->first();
        $renderer = $this->renderer();
        $this->getJson(route('atas.document', ['ata' => $this->ata, 'version' => 1, 'products_layout' => 'parallel']))->assertOk();
        $dom = new DOMDocument;
        $dom->loadHTML('<?xml encoding="UTF-8">'.$renderer->html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);
        $tables = '//table[contains(@class,"parallel-item")]';
        $this->assertSame(2, $xpath->query($tables)->length);
        $this->assertSame(16, $xpath->query($tables.'/thead/tr/th')->length);
        $this->assertSame(2, $xpath->query($tables.'/tbody/tr/td[@class="description"][@colspan="8"]')->length);
        $this->assertSame('Descrição <item> & objeto', $xpath->evaluate('string(('.$tables.')[1]/tbody/tr[2]/td)'));
        $this->assertSame('Segundo objeto', $xpath->evaluate('string(('.$tables.')[2]/tbody/tr[2]/td)'));
        $this->assertSame(0, $xpath->query($tables.'/thead/tr/th[text()="Produto"]')->length);
        $this->assertSame(2, $xpath->query($tables.'/thead/tr/th[text()="Veículos"]')->length);
        $this->assertStringContainsString('1.234,5678', $renderer->html);
        $this->assertSame($before, (array) DB::table('tb_ATA')->where('ID', $this->ata)->first());
        $this->assertDatabaseHas('activity_events', ['action' => 'ata_with_admission_generated']);
        $this->assertSame('parallel', ActivityEvent::latest('id')->first()->metadata['presentation']['products_layout']);
        $this->issue()->assertOk();
        $this->assertStringNotContainsString('<table class="products parallel-item', $renderer->html);
        $this->assertStringContainsString('<th>Produto</th>', $renderer->html);
        $this->getJson(route('atas.document', ['ata' => $this->ata, 'version' => 1, 'products_layout' => 'invalid']))->assertUnprocessable();
    }

    public function test_parallel_word_uses_repeated_headers_merged_description_cells_and_narrow_spacing(): void
    {
        $this->product('Descrição do primeiro objeto');
        $this->product('Descrição do segundo objeto', ['ITEM' => '2']);
        $response = $this->getJson(route('atas.document', ['ata' => $this->ata, 'version' => 1, 'format' => 'docx', 'products_layout' => 'parallel']))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'parallel-word-');
        try {
            file_put_contents($path, $response->getContent());
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($path));
            $dom = new DOMDocument;
            $this->assertTrue($dom->loadXML($zip->getFromName('word/document.xml')));
            $zip->close();
            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $this->assertSame(2, $xpath->query('//w:tbl[w:tr/w:tc/w:p/w:r/w:t="Item"]')->length);
            $this->assertSame(2, $xpath->query('//w:tc[w:tcPr/w:gridSpan[@w:val="7"]]')->length);
            $this->assertSame(14, $xpath->query('//w:tcPr/w:shd[@w:fill="E7E6E6"]')->length);
            $this->assertSame(1, $xpath->query('//w:pPr/w:spacing[@w:line="60"]')->length);
            $this->assertStringContainsString('Descrição do primeiro objeto', $dom->textContent);
            $this->assertStringContainsString('Descrição do segundo objeto', $dom->textContent);
        } finally {
            unlink($path);
        }
        $this->assertSame('parallel', ActivityEvent::latest('id')->first()->metadata['presentation']['products_layout']);
    }

    public function test_real_parallel_documents_support_empty_products_and_special_quantities(): void
    {
        foreach (['pdf', 'docx'] as $format) {
            $response = $this->getJson(route('atas.document', ['ata' => $this->ata, 'version' => 1, 'format' => $format, 'products_layout' => 'parallel']))->assertOk();
            $this->assertStringStartsWith($format === 'pdf' ? '%PDF-' : 'PK', $response->getContent());
        }
        $this->updateAta(['QTD_ESPECIAL_NOME' => 'Veículos']);
        $this->product('Objeto do produto');
        foreach (['pdf', 'docx'] as $format) {
            $response = $this->getJson(route('atas.document', ['ata' => $this->ata, 'version' => 1, 'format' => $format, 'products_layout' => 'parallel']))->assertOk();
            $this->assertStringStartsWith($format === 'pdf' ? '%PDF-' : 'PK', $response->getContent());
        }
    }

    public function test_word_is_withheld_when_data_changes_during_rendering_or_audit_fails(): void
    {
        $renderer = new class(app(LockActor::class), app(AtaWritePolicy::class), app(DocumentSettings::class), app(AtaWithAdmissionTemplate::class)) extends AtaWithAdmissionWord
        {
            public ?Closure $during = null;

            protected function render(string $html): string
            {
                $bytes = parent::render($html);
                ($this->during ?? fn () => null)();

                return $bytes;
            }
        };
        $this->app->instance(AtaWithAdmissionWord::class, $renderer);
        $renderer->during = fn () => $this->product('Produto incluído durante emissão');
        $this->getJson(route('atas.document', ['ata' => $this->ata, 'version' => 1, 'format' => 'docx']))->assertConflict();
        $this->assertDatabaseCount('activity_events', 0);
        $renderer->during = null;
        Event::listen('eloquent.creating: '.ActivityEvent::class, fn () => throw new \RuntimeException('Audit unavailable'));
        try {
            $renderer->generate(auth()->user(), $this->ata, 1);
            $this->fail('Falha de auditoria deve impedir a entrega do Word.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.ActivityEvent::class);
        }
        $this->assertDatabaseCount('activity_events', 0);
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
