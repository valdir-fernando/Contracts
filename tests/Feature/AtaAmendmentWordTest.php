<?php

namespace Tests\Feature;

use App\Infrastructure\Atas\SaveAtaAmendment;
use App\Models\AccessScope;
use App\Models\ActivityEvent;
use App\Models\User;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use ZipArchive;

class AtaAmendmentWordTest extends TestCase
{
    use RefreshDatabase;

    private int $ata;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('document_settings')->where('id', 1)->update(['administration_start_year' => 2031, 'administration_end_year' => 2034]);
        $fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo Teste', 'name_key' => 'FUNDO TESTE', 'raw_payload' => '{}']);
        $this->ata = DB::table('tb_ATA')->insertGetId([
            'ATA' => '002/2025', 'PROCESSO' => 'ORIGINAL', 'DATA_ATA' => '2025-06-02', 'LICITACAO' => 'Pregão Eletrônico',
            'N_LICIT' => '001/25', 'fund_id' => $fund, 'FUNDO' => 'Fundo Teste', 'CNPJ_FUNDO' => '04252011000110',
            'FORNECEDOR' => 'Comércio & Filhos', 'CNPJ_FORNECEDOR' => '11222333000181',
            'SOCIO' => 'Representante Teste', 'CPF_SOCIO' => '11144477735', 'RG_SOCIO' => 'RG-REP-145',
            'GESTOR' => 'Gestor Teste', 'CPF_GESTOR' => '52998224725', 'source_files' => '[]', 'quality_issues' => '[]',
            'import_hash' => str_repeat('a', 64), 'version' => 2,
        ], 'ID');
        DB::table('tb_ADT_ATA')->insert([
            'ATA' => '002/25', 'Proc_ATA' => 'ORIGINAL', 'ADT' => 3, 'Data_ADT' => '2026-06-01', 'Proc_ADT' => 'PROCESSO-ADT',
            'Tipo_ADT' => SaveAtaAmendment::EQUAL_QUANTITY, 'INICIO' => '2026-06-03', 'FINAL' => '2027-06-02', 'Período' => '12 meses',
            'VANTAJOSIDADE' => 'Pesquisa de Preços',
        ]);
        foreach ([3 => 'Renovado <A>', 0 => 'Original', 2 => 'Outro aditivo', 4 => 'Excluído'] as $number => $description) {
            DB::table('tb_PRODUTOS_ATA')->insert([
                'ID_ATA' => $this->ata, 'ATA' => '002/2025', 'PROCESSO' => 'ORIGINAL', 'LICITACAO' => 'Pregão',
                'N_LICIT' => '001/25', 'ADITIVO' => $number === 4 ? 3 : $number, 'ITEM' => (string) ($number + 1), 'UND' => 'UN',
                'DISCRIMINACAO' => $description, 'QTD' => '2.0000', 'VL_UNIT' => '617.2525', 'VL_TOTAL' => '1234.5050',
                'deleted_at' => $number === 4 ? now() : null, 'source_row' => '{}',
            ]);
        }
        $actor = User::create(['name' => 'Emissão', 'username' => 'ADT-WORD', 'password' => 'SyntheticPassword123', 'user_type' => 'Administrador'])->fresh();
        $this->actingAs($actor)->withSession(['fund_id' => $fund]);
    }

    public function test_download_fills_the_word_template_from_the_saved_amendment_and_audits_it(): void
    {
        $response = $this->getJson($this->url())->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Content-Disposition', "attachment; filename=ADT_003_Comercio_Filhos_ATA_002-2025.docx; filename*=utf-8''ADT_003_Com%C3%A9rcio_Filhos_ATA_002-2025.docx");
        $path = tempnam(sys_get_temp_dir(), 'adt-test-');
        file_put_contents($path, $response->getContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        try {
            $body = $this->part($zip, 'word/document.xml');
            $paragraphs = new \DOMXPath($body);
            $paragraphs->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $headings = $paragraphs->query('//w:body/w:p[starts-with(normalize-space(.), "CLÁUSULA ")]');
            $this->assertCount(8, $headings);
            foreach ($headings as $heading) {
                $this->assertSame('', trim($paragraphs->evaluate('string(preceding-sibling::w:p[1])', $heading)));
                $this->assertNotSame('', trim($paragraphs->evaluate('string(preceding-sibling::w:p[2])', $heading)));
            }
            foreach (['3º TERMO ADITIVO', 'Comércio & Filhos', 'PROCESSO-ADT', '03/06/2026', '02/06/2027', '01 de junho de 2026',
                '1.234,51', 'cinquenta e um centavos', 'Renovado <A>'] as $value) {
                $this->assertStringContainsString($value, $body->textContent);
            }
            $clauseText = $paragraphs->query('//w:body/w:p[starts-with(normalize-space(.), "2.2")]/descendant::w:t');
            $this->assertStringContainsString('Conforme Pesquisa de Preços anexada', implode('', array_map(fn ($run) => $run->textContent, iterator_to_array($clauseText))));
            $this->assertSame(1, $body->getElementsByTagNameNS('*', 'tbl')->length);
            $this->assertSame(2, substr_count($body->textContent, 'CPF: 529.982.247-25'));
            $this->assertSame(2, substr_count($body->textContent, '11.222.333/0001-81'));
            $this->assertSame(2, substr_count($body->textContent, 'Representante Teste'));
            $this->assertSame(2, substr_count($body->textContent, 'CPF: 111.444.777-35'));
            $this->assertStringContainsString('RG: RG-REP-145', $body->textContent);
            $this->assertStringNotContainsString('Outro aditivo', $body->textContent);
            $this->assertStringNotContainsString('Excluído', $body->textContent);
            $this->assertStringNotContainsString('#TABELA', $body->textContent);
            $header = $this->part($zip, 'word/header1.xml')->textContent;
            $this->assertStringContainsString('Aditivo: 003', $header);
            $this->assertStringContainsString('ADM: 2031/2034', $header);
            $this->assertDoesNotMatchRegularExpression('/#\p{Lu}/u', $body->textContent);
        } finally {
            $zip->close();
            unlink($path);
        }
        $event = ActivityEvent::where('action', 'ata_amendment_word_generated')->sole();
        $this->assertSame(3, $event->metadata['presentation']['amendment_number']);
        $this->assertSame(hash('sha256', $response->getContent()), $event->metadata['sha256']);
    }

    public function test_word_uses_the_manager_fallback_in_both_places_and_identifies_an_individual_supplier(): void
    {
        $ata = DB::table('tb_ATA')->where('ID', $this->ata);
        $ata->update(['CPF_GESTOR' => '00000000000', 'DECRETO_GESTOR' => 'Decreto Municipal nº 42/2026',
            'CARGO_GESTOR' => 'Gestor', 'MATRICULA_GESTOR' => 'MAT-20', 'RG_GESTOR' => 'RG-GESTOR-42',
            'CNPJ_FORNECEDOR' => '11144477735', 'RG_FORNECEDOR' => 'RG-TITULAR-123']);

        foreach ([
            [[], 'Decreto Municipal: 42/2026'],
            [['CARGO_GESTOR' => null], 'Matrícula Funcional: MAT-20'],
            [['MATRICULA_GESTOR' => null], 'RG: RG-GESTOR-42'],
        ] as [$updates, $expected]) {
            if ($updates !== []) {
                $ata->update($updates);
            }
            $response = $this->getJson($this->url())->assertOk();
            $path = tempnam(sys_get_temp_dir(), 'adt-test-');
            file_put_contents($path, $response->getContent());
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path));
            try {
                $body = $this->part($zip, 'word/document.xml')->textContent;
                $this->assertSame(2, substr_count($body, $expected));
                $this->assertSame(2, substr_count($body, 'CPF: 111.444.777-35'));
                $this->assertStringContainsString('RG: RG-TITULAR-123', $body);
                $this->assertStringNotContainsString('Representante Teste', $body);
                $this->assertStringNotContainsString('RG-REP-145', $body);
            } finally {
                $zip->close();
                unlink($path);
            }
        }
        $ata->update(['RG_GESTOR' => null]);
        $this->getJson($this->url())->assertUnprocessable()->assertJsonValidationErrors('manager_document');
    }

    public function test_parallel_layout_uses_the_ata_word_table_with_one_header_and_full_width_description_per_item(): void
    {
        DB::table('tb_ATA')->where('ID', $this->ata)->update(['QTD_ESPECIAL_NOME' => 'Caixas']);
        DB::table('tb_ADT_ATA')->update(['VANTAJOSIDADE' => 'Declaração do Gestor']);
        $products = DB::table('tb_PRODUTOS_ATA');
        $second = (array) (clone $products)->where('ID_ATA', $this->ata)->where('ADITIVO', 3)->whereNull('deleted_at')
            ->sole(['ID_ATA', 'ATA', 'PROCESSO', 'LICITACAO', 'N_LICIT', 'ADITIVO', 'UND', 'QTD', 'VL_UNIT', 'VL_TOTAL', 'source_row']);
        $products->insert(array_replace($second, ['ITEM' => '9', 'DISCRIMINACAO' => 'Segundo item renovado', 'QTD_ESPECIAL' => '2.5000']));

        $this->getJson($this->url().'&products_layout=unknown')->assertUnprocessable();
        $response = $this->getJson($this->url().'&products_layout=parallel')->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'adt-test-');
        file_put_contents($path, $response->getContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        try {
            $body = $this->part($zip, 'word/document.xml');
            $xpath = new \DOMXPath($body);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $this->assertSame(2, $xpath->query('//w:tbl')->length);
            $this->assertSame(2, $xpath->query('//w:tblHeader')->length);
            $this->assertSame(2, $xpath->query('//w:gridSpan[@w:val="8"]')->length);
            $this->assertSame(16, $xpath->query('//w:shd[@w:fill="E7E6E6"]')->length);
            $this->assertStringContainsString('Caixas', $body->textContent);
            $this->assertStringContainsString('2,5000', $body->textContent);
            $this->assertStringContainsString('Segundo item renovado', $body->textContent);
            $this->assertStringContainsString('Declaração do Gestor', $body->textContent);
            $this->assertStringNotContainsString('Outro aditivo', $body->textContent);
        } finally {
            $zip->close();
            unlink($path);
        }
        $this->assertSame('parallel', ActivityEvent::sole()->metadata['presentation']['products_layout']);
    }

    public function test_it_requires_current_version_active_amendment_supported_type_and_fund_scope(): void
    {
        $this->getJson($this->url(1))->assertConflict();
        DB::table('tb_ADT_ATA')->update(['VANTAJOSIDADE' => null]);
        $this->getJson($this->url())->assertUnprocessable()->assertJsonPath('message', 'Selecione e salve a Vantajosidade antes de emitir o aditivo.');
        DB::table('tb_ADT_ATA')->update(['Tipo_ADT' => SaveAtaAmendment::EXTENSION]);
        $this->getJson($this->url())->assertUnprocessable();
        DB::table('tb_ADT_ATA')->update(['deleted_at' => now()]);
        $this->getJson($this->url())->assertNotFound();
        $actor = auth()->user();
        $fund = DB::table('tb_ATA')->where('ID', $this->ata)->value('fund_id');
        $scope = AccessScope::create(['scope_key' => 'WORD-SCOPE', 'fund_id' => $fund, 'fund' => 'Fundo Teste']);
        $actor->scopes()->attach($scope);
        DB::table('users')->where('id', $actor->id)->update(['catalog_permissions' => '["contract-issuance"]', 'user_type' => 'Usuario']);
        $otherFund = DB::table('contract_funds')->insertGetId(['name' => 'Outro Fundo', 'name_key' => 'OUTRO FUNDO', 'raw_payload' => '{}']);
        DB::table('tb_ATA')->where('ID', $this->ata)->update(['fund_id' => $otherFund]);
        $this->actingAs($actor->fresh())->getJson($this->url())->assertForbidden();
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_pdf_download_converts_the_same_model_and_audits_the_pdf(): void
    {
        $this->getJson($this->url().'&format=rtf')->assertUnprocessable()->assertJsonValidationErrors('format');
        $response = $this->getJson($this->url().'&format=pdf&products_layout=parallel')->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Content-Disposition', "attachment; filename=ADT_003_Comercio_Filhos_ATA_002-2025.pdf; filename*=utf-8''ADT_003_Com%C3%A9rcio_Filhos_ATA_002-2025.pdf");
        $pdf = $response->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $text = (new Process(['pdftotext', '-', '-'], input: $pdf))->mustRun()->getOutput();
        $this->assertStringContainsString('Pesquisa de Preços', $text);
        $this->assertStringContainsString('Renovado <A>', $text);
        $this->assertStringNotContainsString('#VANTAJOSIDADE', $text);
        $event = ActivityEvent::where('action', 'ata_amendment_pdf_generated')->sole();
        $this->assertSame('parallel', $event->metadata['presentation']['products_layout']);
        $this->assertSame(hash('sha256', $pdf), $event->metadata['sha256']);
    }

    public function test_positive_balance_word_and_pdf_use_the_saved_remaining_quantities_and_previous_expiry(): void
    {
        DB::table('tb_ATA')->where('ID', $this->ata)->update(['INICIO_ATA' => '2025-06-03', 'FINAL_ATA' => '2027-06-02',
            'QTD_ESPECIAL_NOME' => 'Caixas de teste',
            'validity_basis' => json_encode(['start' => '2025-06-03', 'original_end' => '2026-06-02'], JSON_THROW_ON_ERROR)]);
        DB::table('tb_ADT_ATA')->insert(['ATA' => '002/25', 'Proc_ATA' => 'ORIGINAL', 'ADT' => 2, 'FINAL' => '2026-06-10']);
        $amendmentId = DB::table('tb_ADT_ATA')->where('ADT', 3)->value('ID');
        $row = ['item' => '2', 'code' => 'COD-2', 'description' => 'Saldo do item de teste', 'brand' => 'Marca',
            'unit' => 'UN', 'quantity' => '10.0000', 'balance_quantity' => '3.5000', 'special_quantity' => '1.1250', 'unit_price' => '2.0000'];
        $excludedId = DB::table('tb_PRODUTOS_ATA')->where('ADITIVO', 0)->value('ID');
        DB::table('tb_ADT_ATA')->where('ADT', 3)->update([
            'Tipo_ADT' => SaveAtaAmendment::EXTENSION, 'INICIO' => '2026-06-11',
            'VANTAJOSIDADE' => 'Declaração do Gestor',
            'balance_products' => json_encode([
                $row + ['id' => 100], array_replace($row, ['id' => $excludedId, 'description' => 'Saldo fora do aditivo']),
            ], JSON_THROW_ON_ERROR),
        ]);
        DB::table('ata_amendment_excluded_products')->insert(['ata_id' => $this->ata, 'amendment_id' => $amendmentId,
            'amendment_number' => 3, 'product_id' => $excludedId, 'product_snapshot' => '{}', 'created_at' => now()]);
        $word = $this->getJson($this->url().'&format=docx')->assertOk()->getContent();
        $path = tempnam(sys_get_temp_dir(), 'adt-balance-test-');
        file_put_contents($path, $word);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        try {
            $document = $this->part($zip, 'word/document.xml');
            $body = $document->textContent;
            $xpath = new \DOMXPath($document);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $clause = $xpath->query('//w:body/w:p[starts-with(normalize-space(.), "2.1")]//w:t');
            $text = implode('', array_map(fn ($run) => $run->textContent, iterator_to_array($clause)));
            $this->assertSame('2.1 - A vigência da Ata de Registro de Preços nº 002/2025 começou em 03/06/2025 e tinha término previsto para 10/06/2026. Este aditivo prorroga a vigência por 12 meses, de 11/06/2026 a 02/06/2027.', $text);
            $next = $xpath->query('//w:body/w:p[starts-with(normalize-space(.), "6.2")]')->item(0);
            $this->assertSame('', trim($xpath->evaluate('string(preceding-sibling::w:p[1])', $next)));
            $this->assertStringStartsWith('6.1', trim($xpath->evaluate('string(preceding-sibling::w:p[2])', $next)));
            $this->assertSame(file_get_contents(resource_path('documents/atas/extract-footer.png')), $zip->getFromName('word/media/image2.png'));
            $this->assertStringContainsString('ADM: 2031/2034', $this->part($zip, 'word/header1.xml')->textContent);
        } finally {
            $zip->close();
            unlink($path);
        }
        $pdf = $this->getJson($this->url().'&format=pdf&products_layout=parallel')->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        $text = (new Process(['pdftotext', '-', '-'], input: $pdf))->mustRun()->getOutput();
        foreach ([$body, preg_replace('/\s+/u', ' ', $text)] as $output) {
            foreach (['03/06/2025', '10/06/2026', '11/06/2026', '02/06/2027', 'Declaração do Gestor', 'Saldo do item de teste', '3,5000', '7,0000'] as $value) {
                $this->assertStringContainsString($value, $output);
            }
            $this->assertStringNotContainsString('Saldo fora do aditivo', $output);
            $this->assertStringNotContainsString('Renovado <A>', $output);
            $this->assertDoesNotMatchRegularExpression('/#\p{Lu}/u', $output);
        }
        $this->assertSame(1, ActivityEvent::where('action', 'ata_amendment_pdf_generated')->count());
        $this->assertStringContainsString('Caixas de teste', $body);
        $this->assertStringContainsString('1,1250', $body);
        $this->assertStringContainsString('Caixas de teste', preg_replace('/\s+/u', ' ', $text));
        $this->assertStringContainsString('1,1250', $text);
        DB::table('tb_ATA')->where('ID', $this->ata)->update(['QTD_ESPECIAL_NOME' => null]);
        $withoutSpecial = $this->getJson($this->url().'&format=pdf')->assertOk()->getContent();
        $withoutSpecialText = (new Process(['pdftotext', '-', '-'], input: $withoutSpecial))->mustRun()->getOutput();
        $this->assertStringNotContainsString('Caixas de teste', $withoutSpecialText);
        $this->assertStringNotContainsString('1,1250', $withoutSpecialText);
        DB::table('tb_ADT_ATA')->where('ADT', 3)->update(['balance_products' => null]);
        $this->getJson($this->url())->assertUnprocessable();
    }

    #[DataProvider('advantageOptions')]
    public function test_variable_period_word_and_pdf_use_the_saved_term_quantities_and_estimated_total(string $advantage): void
    {
        DB::table('tb_ATA')->where('ID', $this->ata)->update(['QTD_ESPECIAL_NOME' => 'Caixas']);
        $amendments = DB::table('tb_ADT_ATA');
        $amendmentId = (clone $amendments)->where('ADT', 3)->value('ID');
        $otherAmendmentId = (clone $amendments)->insertGetId(['ID_ATA' => $this->ata, 'ATA' => '002/25', 'Proc_ATA' => 'ORIGINAL', 'ADT' => 2], 'ID');
        $amendments->where('ID', $amendmentId)->update([
            'ID_ATA' => $this->ata, 'Tipo_ADT' => SaveAtaAmendment::VARIABLE_QUANTITY,
            'FINAL' => '2026-12-02', 'Período' => '6 meses', 'VANTAJOSIDADE' => $advantage,
        ]);
        $products = DB::table('tb_PRODUTOS_ATA')->where('ID_ATA', $this->ata)->where('ADITIVO', 3)->whereNull('deleted_at');
        (clone $products)->update(['amendment_id' => $amendmentId, 'DISCRIMINACAO' => 'Item com quantidade variável',
            'QTD' => '3.5000', 'QTD_ESPECIAL' => '1.2500', 'VL_UNIT' => '2.3326', 'VL_TOTAL' => '8.1641']);
        $row = (array) (clone $products)->sole(['ID_ATA', 'ATA', 'PROCESSO', 'LICITACAO', 'N_LICIT', 'ADITIVO', 'amendment_id', 'UND', 'source_row']);
        DB::table('tb_PRODUTOS_ATA')->insert([
            $row + ['ITEM' => '9', 'DISCRIMINACAO' => 'Segundo item incluído', 'QTD' => '1.0000', 'VL_UNIT' => '0.0040', 'VL_TOTAL' => '0.0040'],
            array_replace($row, ['amendment_id' => $otherAmendmentId, 'ITEM' => '10', 'DISCRIMINACAO' => 'Vínculo de outro aditivo',
                'QTD' => '10.0000', 'VL_UNIT' => '3.0000', 'VL_TOTAL' => '30.0000']),
        ]);
        $lookup = route('atas.lookup', ['number' => '002/2025']);
        $this->getJson($lookup)->assertOk()->assertJsonPath('amendments.3.has_registered_products', true)
            ->assertJsonMissingPath('amendments.3.balance_products');

        $word = $this->getJson($this->url().'&format=docx')->assertOk()->getContent();
        $path = tempnam(sys_get_temp_dir(), 'adt-variable-test-');
        file_put_contents($path, $word);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        try {
            $document = $this->part($zip, 'word/document.xml');
            $body = implode('', array_map(fn ($run) => $run->textContent, iterator_to_array($document->getElementsByTagNameNS('*', 't'))));
            $this->assertSame(1, $document->getElementsByTagNameNS('*', 'tbl')->length);
            $this->assertSame(file_get_contents(resource_path('documents/atas/extract-footer.png')), $zip->getFromName('word/media/image2.png'));
        } finally {
            $zip->close();
            unlink($path);
        }
        $pdf = $this->getJson($this->url().'&format=pdf&products_layout=parallel')->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private')->getContent();
        $text = (new Process(['pdftotext', '-', '-'], input: $pdf))->mustRun()->getOutput();
        foreach ([$body, preg_replace('/\s+/u', ' ', $text)] as $output) {
            foreach (['pelo período de 6 meses', '03/06/2026 a 02/12/2026',
                '1.2 - Fica demonstrada nos autos a vantajosidade da manutenção dos preços registrados para o período prorrogado. A Detentora manifesta sua anuência às condições do presente termo.',
                '2.1 - O presente Termo Aditivo fundamenta-se no art. 84 da Lei Federal nº 14.133/2021, na regulamentação aplicável e nas cláusulas de vigência e prorrogação do Edital e da Ata de Registro de Preços.',
                '2.2 - A vantajosidade da manutenção dos preços registrados foi comprovada pela '.$advantage.' juntada aos autos do Processo Administrativo nº PROCESSO-ADT.',
                'CLÁUSULA QUARTA – DOS ITENS, QUANTITATIVOS E VALORES', 'R$ 8,17 (oito reais e dezessete centavos)',
                'Item com quantidade variável', 'Segundo item incluído', '3,5000', '2,3326', '8,1641', 'Caixas', '1,2500'] as $value) {
                $this->assertStringContainsString($value, $output);
            }
            foreach (['2.3 -', 'deve observar', 'deve ser demonstrada', 'por igual período', 'renovação integral', 'restabelecendo-se', 'Vínculo de outro aditivo', 'Excluído'] as $value) {
                $this->assertStringNotContainsString($value, $output);
            }
            $this->assertDoesNotMatchRegularExpression('/#\p{Lu}/u', $output);
        }
        $event = ActivityEvent::where('action', 'ata_amendment_pdf_generated')->sole();
        $this->assertSame('parallel', $event->metadata['presentation']['products_layout']);
        $this->assertSame(hash('sha256', $pdf), $event->metadata['sha256']);

        $products->where('amendment_id', $amendmentId)->update(['deleted_at' => now()]);
        $this->getJson($lookup)->assertOk()->assertJsonPath('amendments.3.has_registered_products', false);
        $this->getJson($this->url())->assertUnprocessable()->assertJsonPath('message', 'O aditivo não tem produtos ativos para emissão.');
    }

    public function test_annual_index_word_and_pdf_use_the_recorded_percentage_index_type_budget_date_and_adjusted_prices(): void
    {
        $ata = DB::table('tb_ATA')->where('ID', $this->ata);
        $ata->update(['DATA_ORCAMENTO' => '2025-05-20', 'TIPO_INDICE_ANUAL' => 'IPCA']);
        $amendment = DB::table('tb_ADT_ATA')->where('ADT', 3);
        $amendment->update(['Tipo_ADT' => SaveAtaAmendment::ANNUAL_INDEX, 'VANTAJOSIDADE' => 'Declaração do Gestor']);
        $this->getJson($this->url())->assertUnprocessable()->assertJsonPath('message', 'Informe e salve Indice_Anual antes de emitir o aditivo.');
        $amendment->update(['annual_index' => '12.256700']);
        $item = DB::table('tb_PRODUTOS_ATA')->where('ID_ATA', $this->ata)->where('ADITIVO', 3)->whereNull('deleted_at');
        $item->update(['amendment_id' => $amendment->value('ID'), 'DISCRIMINACAO' => 'Item com preço manual',
            'QTD' => '2.0001', 'VL_UNIT' => '3.4567', 'VL_TOTAL' => '6.9100']);
        $this->getJson(route('atas.lookup', ['number' => '002/2025']))->assertOk()
            ->assertJsonPath('amendments.3.has_registered_products', true);

        $word = $this->getJson($this->url().'&format=docx')->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')
            ->assertHeader('Cache-Control', 'no-store, private')->getContent();
        $path = tempnam(sys_get_temp_dir(), 'adt-annual-test-');
        file_put_contents($path, $word);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        try {
            $document = $this->part($zip, 'word/document.xml');
            $body = implode('', array_map(fn ($run) => $run->textContent, iterator_to_array($document->getElementsByTagNameNS('*', 't'))));
            $this->assertSame(1, $document->getElementsByTagNameNS('*', 'tbl')->length);
            $this->assertSame(file_get_contents(resource_path('documents/atas/extract-footer.png')), $zip->getFromName('word/media/image2.png'));
        } finally {
            $zip->close();
            unlink($path);
        }
        $pdf = $this->getJson($this->url().'&format=pdf&products_layout=parallel')->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private')->getContent();
        $text = (new Process(['pdftotext', '-', '-'], input: $pdf))->mustRun()->getOutput();
        foreach ([$body, preg_replace('/\s+/u', ' ', $text)] as $output) {
            foreach (['com o reajuste anual dos preços', 'arts. 25, § 7º, e 84', 'preços reajustados foi comprovada pela Declaração do Gestor',
                '03/06/2026 a 02/06/2027',
                'O percentual de referência do reajuste é de 12,256700% e corresponde à variação acumulada do IPCA no período de 1 (um) ano, contado da data do orçamento estimado (20/05/2025).',
                'Item com preço manual', '3,4567', '6,9100', 'R$ 6,91 (seis reais e noventa e um centavos)'] as $value) {
                $this->assertStringContainsString($value, $output);
            }
            $this->assertStringNotContainsString('Outro aditivo', $output);
            $this->assertStringNotContainsString('Excluído', $output);
            $this->assertStringNotContainsString('ficam mantidos os preços unitários', $output);
            $this->assertDoesNotMatchRegularExpression('/#\p{Lu}/u', $output);
        }
        $this->assertSame(hash('sha256', $word), ActivityEvent::where('action', 'ata_amendment_word_generated')->sole()->metadata['sha256']);
        $this->assertSame(hash('sha256', $pdf), ActivityEvent::where('action', 'ata_amendment_pdf_generated')->sole()->metadata['sha256']);

        $ata->update(['DATA_ORCAMENTO' => null, 'TIPO_INDICE_ANUAL' => null]);
        $legacyPdf = $this->getJson($this->url().'&format=pdf')->assertOk()->getContent();
        $legacyText = (new Process(['pdftotext', '-', '-'], input: $legacyPdf))->mustRun()->getOutput();
        $this->assertStringContainsString('variação acumulada do índice indicado na instrução do aditivo no período de 1 (um) ano, contado da data do orçamento estimado (conforme os autos do Processo Administrativo nº PROCESSO-ADT).',
            preg_replace('/\s+/u', ' ', $legacyText));
    }

    #[DataProvider('advantageOptions')]
    public function test_apostille_emits_its_own_word_and_pdf_with_saved_prices_and_effect_dates(string $advantage): void
    {
        DB::table('tb_ATA')->where('ID', $this->ata)->update(['DATA_ORCAMENTO' => '2025-05-20', 'TIPO_INDICE_ANUAL' => 'IPCA']);
        DB::table('tb_ADT_ATA')->where('ADT', 3)->update(['ADT' => 1]);
        $product = DB::table('tb_PRODUTOS_ATA')->where('ID_ATA', $this->ata)->where('ADITIVO', 0)->value('ID');
        $this->postJson(route('atas.amendments.store', $this->ata), ['version' => 2, 'number' => 1, 'date' => '2026-06-02',
            'process' => 'PROCESSO-APOST', 'type' => SaveAtaAmendment::APOSTILLE, 'start' => '2026-06-03', 'end' => '2027-06-02',
            'period' => '12 meses', 'advantage' => $advantage, 'annual_index' => '12,256700',
            'balance_quantities' => [['product_id' => $product, 'quantity' => '2,0001', 'adjusted_unit_price' => '3,4567']]])->assertCreated();
        $url = route('atas.amendments.document', ['ata' => $this->ata, 'amendment' => 1, 'version' => 3, 'instrument' => 'apostille']);
        $word = $this->getJson($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Content-Disposition', "attachment; filename=APOST_001_Comercio_Filhos_ATA_002-2025.docx; filename*=utf-8''APOST_001_Com%C3%A9rcio_Filhos_ATA_002-2025.docx")
            ->getContent();
        $path = tempnam(sys_get_temp_dir(), 'apostille-test-');
        file_put_contents($path, $word);
        $zip = new ZipArchive;
        $reference = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $this->assertTrue($reference->open(resource_path('documents/atas/Aditivo_Prorrogacao_Reajuste_Anual_Indice.docx')));
        try {
            $document = $this->part($zip, 'word/document.xml');
            $xpath = new \DOMXPath($document);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $body = implode('', array_map(fn ($run) => $run->textContent, iterator_to_array($xpath->query('//w:body//w:t'))));
            $sections = $document->getElementsByTagNameNS('*', 'sectPr');
            $referenceSections = $this->part($reference, 'word/document.xml')->getElementsByTagNameNS('*', 'sectPr');
            $this->assertSame($referenceSections->item(0)->C14N(true), $sections->item(0)->C14N(true));
            $this->assertSame($reference->getFromName('word/styles.xml'), $zip->getFromName('word/styles.xml'));
            $this->assertSame($reference->getFromName('word/footer1.xml'), $zip->getFromName('word/footer1.xml'));
            $this->assertCount(5, $xpath->query('//w:body/w:p[w:r/w:rPr/w:b][starts-with(normalize-space(.), "CLÁUSULA ")]'));
            $this->assertSame(1, $document->getElementsByTagNameNS('*', 'tbl')->length);
            $this->assertStringContainsString('Apostilamento: 001', $this->part($zip, 'word/header1.xml')->textContent);
        } finally {
            $reference->close();
            $zip->close();
            unlink($path);
        }
        $pdf = $this->getJson($url.'&format=pdf&products_layout=parallel')->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        $pdfText = (new Process(['pdftotext', '-', '-'], input: $pdf))->mustRun()->getOutput();
        $this->assertMatchesRegularExpression('/ÓRGÃO GERENCIADOR[^\f]*_{10}[^\f]*Gestor Teste/u', $pdfText);
        $text = preg_replace('/\s+/u', ' ', $pdfText);
        $this->assertStringContainsString('Apostilamento: 001', $text);
        $this->assertStringContainsString('ADM: 2031/2034', $text);
        $clause = "2.2 - Consta nos autos a {$advantage} para demonstrar a vantajosidade do apostilamento da Ata de Registro de Preços.";
        foreach ([$body, $text] as $output) {
            foreach (['TERMO DE APOSTILAMENTO Nº 001', 'PROCESSO-APOST', $clause, '12,256700%', 'IPCA', '20/05/2025',
                '03/06/2026 a 02/06/2027', '02 de junho de 2026', 'Original', '3,4567', 'R$ 6,91', '5.2 - A vigência'] as $value) {
                $this->assertStringContainsString($value, $output);
            }
            $this->assertStringNotContainsString('TERMO ADITIVO', $output);
            $this->assertStringNotContainsString('Outro aditivo', $output);
            $this->assertStringNotContainsString('na modalidade', $output);
            $this->assertDoesNotMatchRegularExpression('/sem (?:consulta|pesquisa) de preços/iu', $output);
            $this->assertDoesNotMatchRegularExpression('/#\p{Lu}/u', $output);
        }
        $event = ActivityEvent::where('action', 'ata_amendment_pdf_generated')->sole();
        $this->assertSame('apostille', $event->metadata['presentation']['instrument']);
        $this->assertSame(hash('sha256', $pdf), $event->metadata['sha256']);
        $this->getJson($url.'&format=pdf&version=2')->assertConflict();
        $this->getJson(str_replace('instrument=apostille', 'instrument=amendment', $url))->assertUnprocessable();
    }

    public static function advantageOptions(): array
    {
        return [
            'pesquisa de preços' => ['Pesquisa de Preços'],
            'declaração do gestor' => ['Declaração do Gestor'],
        ];
    }

    private function url(int $version = 2): string
    {
        return route('atas.amendments.document', ['ata' => $this->ata, 'amendment' => 3, 'version' => $version]);
    }

    private function part(ZipArchive $zip, string $name): DOMDocument
    {
        $xml = new DOMDocument;
        $this->assertTrue($xml->loadXML($zip->getFromName($name), LIBXML_NONET));

        return $xml;
    }
}
