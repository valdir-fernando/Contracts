<?php

namespace Tests\Feature;

use App\Infrastructure\Contracts\OriginalContractProducts;
use App\Infrastructure\Documents\SupplyContractPdf;
use App\Infrastructure\Documents\SupplyContractTemplate;
use App\Infrastructure\Managers\ManagerSignatory;
use App\Infrastructure\Security\LockActor;
use App\Models\AccessScope;
use App\Models\ActivityEvent;
use App\Models\Contract;
use App\Models\ContractFinancialRecord;
use App\Models\ContractProduct;
use App\Models\ImportRun;
use App\Models\LegacyRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SupplyContractPdfTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = User::create(['name' => 'Admin', 'username' => 'PDF_ADMIN', 'password' => 'Synthetic123', 'user_type' => 'Administrador']);
        $fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo de teste', 'name_key' => 'FUNDO DE TESTE', 'raw_payload' => '{}']);
        $this->contract = Contract::create(['numero' => '015/26', 'processo' => '202600001', 'modalidade' => 'Pregão', 'n_licit' => '012/26',
            'data_licit' => '2025-12-20', 'tipo' => 'Fornecimento', 'fund_id' => $fund, 'ownership_resolved' => true,
            'fundo_nome_snapshot' => 'Fundo de teste', 'fundo_documento_snapshot' => '11222333000181', 'end_fundo' => 'Praça de teste, 1',
            'fornecedor_nome_snapshot' => 'Fornecedor fictício', 'fornecedor_documento_snapshot' => '11444777000161',
            'end_fornec' => 'Rua de teste, 2', 'socio' => 'Representante fictício', 'cpf_socio' => '52998224725', 'rg_socio' => 'RG SOCIO PRIVADO',
            'objeto' => 'Materiais de teste', 'vigencia' => '12 meses', 'vigencia_inicio' => '2026-01-01',
            'vigencia_fim_original' => '2026-12-31', 'vigencia_fim_atual' => '2027-02-28',
            'valor_total' => '2530.9200', 'valor_acumulado' => '9999.0000', 'ext_total' => 'Dois mil quinhentos e trinta reais e noventa e dois centavos',
            'nome_gestor' => 'Gestor fictício', 'cpf_gestor' => '11144477735', 'rg_gestor' => 'RG GESTOR PRIVADO', 'cargo_gestor' => 'Gestor do Fundo',
            'data_contrato' => '2026-01-02', 'quality_issues' => [], 'ficha' => '001']);
        $this->actingAs($this->admin)->withSession(['fund_id' => $fund]);
        DB::table('tb_Dotacao')->insert([
            ['Ficha' => '001', 'Dotacao' => '01.02.03.4.5'],
            ['Ficha' => '002', 'Dotacao' => '02.03.04.5.6'],
        ]);
    }

    private function url(?int $version = null): string
    {
        return '/contracts/'.$this->contract->id.'/document?version='.($version ?? $this->contract->fresh()->version);
    }

    private function product(array $attributes = []): ContractProduct
    {
        return ContractProduct::create(array_replace(['contract_id' => $this->contract->id, 'item' => '1', 'description' => 'Produto exclusivo do contrato',
            'code' => '30149', 'brand' => 'Marca teste', 'unit' => 'UND', 'quantity' => '2.5000', 'unit_price' => '1012.3680', 'total' => '2530.9200', 'Ficha' => '001',
            'process_number' => $this->contract->processo, 'supplier_document' => $this->contract->fornecedor_documento_snapshot], $attributes));
    }

    private function budget(array $attributes = []): ContractFinancialRecord
    {
        return ContractFinancialRecord::create(array_replace(['contract_id' => $this->contract->id, 'kind' => 'dotacao', 'number' => 'REC-'.(ContractFinancialRecord::withTrashed()->count() + 1),
            'year' => 2026, 'event_date' => '2026-01-02', 'created_by' => $this->admin->id, 'updated_by' => $this->admin->id, 'status' => 'registrado', 'version' => 1,
            'payload' => ['Ficha' => '001', 'Dotacao' => '01.02.03.4.5', 'Descricao' => 'Material de consumo']], $attributes));
    }

    private function renderer(?\Closure $during = null): SupplyContractPdf
    {
        $renderer = new class(app(LockActor::class), app(SupplyContractTemplate::class), app(ManagerSignatory::class), $during, DB::transactionLevel()) extends SupplyContractPdf
        {
            public string $html = '';

            public function __construct($actors, $template, $signatories, private ?\Closure $during, private int $outerTransactionLevel)
            {
                parent::__construct($actors, $template, $signatories);
            }

            protected function render(string $html): string
            {
                if (DB::transactionLevel() !== $this->outerTransactionLevel) {
                    throw new \RuntimeException('PDF rendering must be outside the write transaction');
                }
                $this->html = $html;
                if ($this->during) {
                    ($this->during)();

                    return '%PDF-synthetic';
                }

                return parent::render($html);
            }
        };
        app()->instance(SupplyContractPdf::class, $renderer);

        return $renderer;
    }

    public static function worksBudgets(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('worksBudgets')]
    public function test_health_accreditation_uses_saved_fields_and_word_table(bool $multiple): void
    {
        $this->contract->update(['tipo' => 'Credenciamento Saúde', 'cargo_credenc' => 'Médico <clínico>',
            'local_servico' => 'Unidade de saúde', 'doc_prof' => 'CRM TESTE 123', 'rg_fornec' => 'RG PRIVADO',
            'fornecedor_documento_snapshot' => '52998224725', 'vl_parcial' => '210.9100', 'ext_parcial' => 'Duzentos e dez reais e noventa e um centavos']);
        $this->product($multiple ? ['quantity' => '1.0000', 'unit_price' => '1000.0000', 'total' => '1000.0000'] : []);
        if ($multiple) {
            $this->product(['item' => '2', 'Ficha' => '002', 'quantity' => '1.0000', 'unit_price' => '1530.9200', 'total' => '1530.9200']);
        }
        $renderer = $this->renderer();
        $response = $this->get($this->url().'&tipo=Fornecimento')->assertOk()
            ->assertHeader('Content-Disposition', 'inline; filename="contrato-credenciamento-saude-'.$this->contract->id.'.pdf"')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$renderer->html);
        $xpath = new \DOMXPath($document);
        $text = $xpath->evaluate('string(//body)');
        foreach (['SAÚDE PÚBLICA N° 015/26', 'Médico <clínico>', 'Unidade de saúde', 'CRM TESTE 123', 'RG PRIVADO',
            '529.982.247-25', '210,91', '2.530,92', '01.02.03.4.5', 'CREDENCIANTE', 'CREDENCIADO(A)',
            $multiple ? 'nas dotações abaixo discriminadas:' : 'na dotação abaixo discriminada:'] as $value) {
            $this->assertStringContainsString($value, $text);
        }
        $this->assertDoesNotMatchRegularExpression('/#[\p{L}_]/u', $text);
        $this->assertSame(7, $xpath->query('//table[@class="products"]/thead/tr/th')->length);
        $this->assertSame('Produto exclusivo do contrato', $xpath->evaluate('string((//table[@class="products"]/tbody/tr)[1]/td[2])'));
        $this->assertSame('30149', $xpath->evaluate('string((//table[@class="products"]/tbody/tr)[1]/td[3])'));
        $this->assertSame($multiple ? 2 : 1, $xpath->query('//table[@class="budget-lines"]/tbody/tr')->length);
        $this->assertSame('health-accreditation', ActivityEvent::sole()->metadata['model']);
        $this->assertNull(SupplyContractPdf::modelFor('Credenciamento'));
        $this->blade('<x-contract-document-actions :contract="$contract"/>', ['contract' => $this->contract])
            ->assertSee('data-contract-document-url=', false)->assertSee('Abrir contrato de credenciamento saúde em PDF.');
        ActivityEvent::query()->delete();
        $this->contract->update(['valor_total' => '2530.9300']);
        $this->getJson($this->url())->assertUnprocessable();
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_works_without_products_uses_saved_object_and_omits_product_table(): void
    {
        $this->contract->update(['tipo' => 'Obras', 'objeto' => 'Construção de escola <exemplo> & instalações']);
        $renderer = $this->renderer();
        $this->get($this->url())->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$renderer->html);
        $xpath = new \DOMXPath($document);
        $text = $xpath->evaluate('string(//body)');
        $this->assertStringContainsString('Construção de escola <exemplo> & instalações', $text);
        $this->assertStringNotContainsString('#OBJETO', $text);
        $this->assertStringNotContainsString('Produtos não informados', $text);
        $this->assertStringNotContainsString('contratação de empresa especializada em', $text);
        $this->assertSame(0, $xpath->query('//table[@class="products"]')->length);
        $this->assertSame(1, $xpath->query('//table[@class="signatures"]')->length);
        $this->assertSame('works', ActivityEvent::sole()->metadata['model']);
    }

    #[DataProvider('worksBudgets')]
    public function test_works_uses_saved_fields_and_singular_or_plural_budget_text(bool $multiple): void
    {
        $this->contract->update(['tipo' => 'Obras', 'prazo_obra' => '180 dias', 'tipo_obra' => 'Empreitada por preço global']);
        $this->product($multiple ? ['quantity' => '1.0000', 'unit_price' => '1000.0000', 'total' => '1000.0000'] : []);
        if ($multiple) {
            $this->product(['item' => '2', 'Ficha' => '002', 'quantity' => '1.0000', 'unit_price' => '1530.9200', 'total' => '1530.9200']);
        }
        $renderer = $this->renderer();
        $response = $this->get($this->url().'&tipo=Fornecimento')->assertOk()
            ->assertHeader('Content-Disposition', 'inline; filename="contrato-obras-'.$this->contract->id.'.pdf"')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$renderer->html);
        $xpath = new \DOMXPath($document);
        $text = $xpath->evaluate('string(//body)');
        foreach (['CONTRATO DE OBRAS Nº. 015/26', 'Empreitada por preço global', '180 dias', '2.530,92', 'Produto exclusivo do contrato',
            '01.02.03.4.5', 'Gestor fictício', 'Fornecedor fictício', $this->contract->ext_total,
            $multiple ? 'nas dotações abaixo discriminadas:' : 'na dotação abaixo discriminada:'] as $value) {
            $this->assertStringContainsString($value, $text);
        }
        $this->assertStringNotContainsString('::', $text);
        $this->assertDoesNotMatchRegularExpression('/#[\p{L}_]/u', $text);
        $this->assertSame(6, $xpath->query('//table[@class="products"]/thead/tr/th')->length);
        $this->assertSame($multiple ? 2 : 1, $xpath->query('//table[@class="budget-lines"]/tbody/tr')->length);
        $this->assertSame('works', ActivityEvent::sole()->metadata['model']);
        $this->blade('<x-contract-document-actions :contract="$contract"/>', ['contract' => $this->contract])
            ->assertSee('data-contract-document-url=', false)->assertSee('Abrir contrato de obras em PDF.');
    }

    public function test_services_model_uses_supplied_text_saved_fields_and_six_column_table(): void
    {
        $this->contract->update(['tipo' => 'Serviços Diversos']);
        $this->product(['description' => 'Serviço sintético de manutenção']);
        $renderer = $this->renderer();
        $response = $this->get($this->url().'&tipo=Fornecimento')->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="contrato-servicos-'.$this->contract->id.'.pdf"')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$renderer->html);
        $xpath = new \DOMXPath($document);
        $text = $xpath->evaluate('string(//body)');
        foreach (['SERVIÇOS DIVERSOS Nº 015/26', 'Serviço sintético de manutenção', '2.530,92',
            '01.02.03.4.5', 'Gestor fictício', 'Fornecedor fictício'] as $value) {
            $this->assertStringContainsString($value, $text);
        }
        $this->assertSame(6, $xpath->query('//table[@class="products"]/thead/tr/th')->length);
        $this->assertDoesNotMatchRegularExpression('/#[\p{L}_]/u', $text);
        $this->assertSame('services', ActivityEvent::sole()->metadata['model']);
        $this->blade('<x-contract-document-actions :contract="$contract"/>', ['contract' => $this->contract])
            ->assertSee('data-contract-document-url=', false)->assertSee('Abrir contrato de serviços diversos em PDF.');

        $this->assertSame('services', SupplyContractPdf::modelFor('Servicos Diversos'));
        ActivityEvent::query()->delete();
        $this->contract->update(['valor_total' => '2530.9300']);
        $this->getJson($this->url())->assertUnprocessable();
        $this->assertDatabaseCount('activity_events', 0);
    }

    public static function personalDocumentModels(): array
    {
        return [['Serviços Diversos'], ['Credenciamento Saúde']];
    }

    #[DataProvider('personalDocumentModels')]
    public function test_services_masks_personal_fields_and_requires_issuance_permission(string $type): void
    {
        $this->contract->update(['tipo' => $type, 'fornecedor_documento_snapshot' => '52998224725', 'rg_fornec' => 'RG FORNECEDOR PRIVADO']);
        $this->product();
        $user = User::create(['name' => 'Emissor', 'username' => 'SERVICES_ISSUER', 'password' => 'Synthetic123']);
        $scope = AccessScope::create(['scope_key' => 'services-pdf', 'fund_id' => $this->contract->fund_id, 'fund' => 'Fundo de teste']);
        $user->scopes()->attach($scope);
        $this->actingAs($user)->get($this->url())->assertForbidden();
        $user->catalog_permissions = ['contract-issuance'];
        $user->save();
        $renderer = $this->renderer(fn () => null);
        $this->get($this->url())->assertOk();
        $this->assertStringContainsString('•••4725', $renderer->html);
        $this->assertStringNotContainsString('529.982.247-25', $renderer->html);
        $this->assertStringNotContainsString('RG SOCIO PRIVADO', $renderer->html);
        $this->assertStringNotContainsString('RG GESTOR PRIVADO', $renderer->html);
        $this->assertStringNotContainsString('RG FORNECEDOR PRIVADO', $renderer->html);
    }

    public function test_property_lease_model_uses_saved_values_and_its_seven_column_table(): void
    {
        $this->contract->update(['tipo' => 'Locação de Imóvel', 'instalacoes' => 'Unidade de atendimento',
            'vl_parcial' => '210.9100', 'ext_parcial' => 'Duzentos e dez reais e noventa e um centavos']);
        $this->product(['description' => 'Imóvel na Rua de teste', 'unit' => 'MÊS', 'quantity' => '12.0000', 'unit_price' => '210.9100']);
        $renderer = $this->renderer();
        $response = $this->get($this->url().'&tipo=Fornecimento')->assertOk()
            ->assertHeader('Content-Disposition', 'inline; filename="contrato-locacao-imovel-'.$this->contract->id.'.pdf"')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $text = html_entity_decode(strip_tags($renderer->html));
        foreach (['DE LOCAÇÃO DE IMÓVEL', 'Unidade de atendimento', 'Imóvel na Rua de teste', '210,91', '2.530,92',
            'Duzentos e dez reais', 'CLÁUSULA DÉCIMA', 'Gestor fictício', 'Fornecedor fictício', '01.02.03.4.5'] as $value) {
            $this->assertStringContainsString($value, $text);
        }
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$renderer->html);
        $xpath = new \DOMXPath($document);
        $this->assertDoesNotMatchRegularExpression('/#[\p{L}_]/u', $xpath->evaluate('string(//body)'));
        $this->assertSame(7, $xpath->query('//table[@class="products"]/thead/tr/th')->length);
        $this->assertStringContainsString('CONTRATO DE LOCAÇÃO DE IMÓVEL Nº 015/26', $text);
        $this->assertStringNotContainsString('CONTRATO ADMINISTRATIVO Nº', $text);
        $signatures = $xpath->evaluate('string(//table[@class="signatures"])');
        $this->assertStringContainsString('LOCATÁRIO', $signatures);
        $this->assertStringContainsString('LOCADOR', $signatures);
        $this->assertStringContainsString('CPF: 111.444.777-35', $signatures);
        $this->assertStringNotContainsString('CONTRATANTE', $signatures);
        $this->assertStringNotContainsString('CONTRATADA', $signatures);
        $this->assertStringNotContainsString('Decreto Municipal', $signatures);
        $this->assertStringNotContainsString('Gestor do Fundo', $signatures);
        $this->assertStringNotContainsString('Rua de teste', $signatures);
        $this->assertStringNotContainsString('representante:', $signatures);
        $this->assertStringNotContainsString('RG:', $signatures);
        $this->assertSame(1, $xpath->query('//p[normalize-space(.)="CONTRATO DE LOCAÇÃO DE IMÓVEL Nº 015/26" and strong and contains(@style,"text-align:center")]')->length);
        $this->assertStringNotContainsString('CONTRATO Nº 015/26', $text);
        $this->assertStringNotContainsString('O MUNICÍPIO DE NIQUELÂNDIA-GO, por intermédio do', $text);
        $this->assertStringContainsString('Fundo de teste, pessoa jurídica de direito público interno', $text);
        $this->assertSame(0, $xpath->query('//body//text()[not(ancestor::strong) and (contains(., "LOCADOR") or contains(., "LOCATÁRIO"))]')->length);
        $this->assertSame('property-lease', ActivityEvent::sole()->metadata['model']);
        $this->blade('<x-contract-document-actions :contract="$contract"/>', ['contract' => $this->contract])
            ->assertSee('data-contract-document-url=', false)->assertSee('Abrir contrato de locação de imóvel em PDF.');
    }

    public function test_property_lease_keeps_separate_rows_with_the_same_item_and_different_codes(): void
    {
        $this->contract->update(['tipo' => 'Locação de Imóvel']);
        $this->product(['item' => '1', 'code' => 'IMOVEL-A', 'description' => 'Primeiro imóvel',
            'quantity' => '1.0000', 'unit_price' => '1000.0000', 'total' => '1000.0000']);
        $this->product(['item' => '1', 'code' => 'IMOVEL-B', 'description' => 'Segundo imóvel',
            'quantity' => '1.0000', 'unit_price' => '1530.9200', 'total' => '1530.9200']);
        $renderer = $this->renderer(fn () => null);
        $this->get($this->url())->assertOk();
        $this->assertStringContainsString('IMOVEL-A', $renderer->html);
        $this->assertStringContainsString('IMOVEL-B', $renderer->html);
        $this->assertStringContainsString('Primeiro imóvel', $renderer->html);
        $this->assertStringContainsString('Segundo imóvel', $renderer->html);
        $this->assertStringContainsString('2.530,92', $renderer->html);
        $this->assertDatabaseCount('tb_Produtos_Contratos', 2);

        ActivityEvent::query()->delete();
        $this->contract->update(['tipo' => 'Fornecimento']);
        $this->get($this->url())->assertUnprocessable()->assertSee('códigos diferentes');
        $this->assertDatabaseCount('activity_events', 0);

        $this->contract->update(['tipo' => 'Locação de Imóvel', 'valor_total' => '2530.9300']);
        $this->get($this->url())->assertUnprocessable()->assertSee('A soma dos produtos');
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_lease_emission_ignores_amendments_and_errors_link_back_to_contract(): void
    {
        $this->contract->update(['tipo' => 'Locação de Imóvel']);
        $this->product();
        $run = ImportRun::create(['source_hash' => str_repeat('c', 64), 'status' => 'completed', 'started_at' => now()]);
        $source = LegacyRecord::create(['source_table' => 'tb_Produtos_Contratos', 'legacy_id' => 99001,
            'source_hash' => str_repeat('d', 64), 'raw_payload' => ['ADT' => '001/26', 'Proc_ADT' => '202600002'], 'import_run_id' => $run->id]);
        $this->product(['item' => '1', 'code' => 'ADITIVO', 'legacy_record_id' => $source->id]);
        $this->assertCount(1, app(OriginalContractProducts::class)->forContract($this->contract));
        $renderer = $this->renderer(fn () => null);
        $this->get($this->url())->assertOk();
        $this->assertStringNotContainsString('ADITIVO', $renderer->html);
        ActivityEvent::query()->delete();
        $this->contract->update(['valor_total' => '2530.9300']);
        $this->get($this->url())->assertUnprocessable()
            ->assertSee(route('contracts.edit', $this->contract), false)
            ->assertSee('Voltar ao contrato')->assertDontSee('Voltar ao início');
        $this->getJson($this->url())->assertUnprocessable()->assertJsonStructure(['message']);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_property_lease_masks_personal_details_and_rechecks_changes_during_rendering(): void
    {
        $this->contract->update(['tipo' => 'Locação de Imóvel', 'fornecedor_documento_snapshot' => '52998224725', 'rg_fornec' => 'RG LOCADOR PRIVADO']);
        $this->product();
        $user = User::create(['name' => 'Emissor', 'username' => 'LEASE_ISSUER', 'password' => 'Synthetic123']);
        $user->catalog_permissions = ['contract-issuance'];
        $user->save();
        $scope = AccessScope::create(['scope_key' => 'lease-pdf', 'fund_id' => $this->contract->fund_id, 'fund' => 'Fundo de teste']);
        $user->scopes()->attach($scope);
        $this->actingAs($user);
        $renderer = $this->renderer();
        $this->get($this->url())->assertOk();
        $this->assertStringContainsString('•••4725', $renderer->html);
        $this->assertStringNotContainsString('529.982.247-25', $renderer->html);
        $this->assertStringNotContainsString('RG LOCADOR PRIVADO', $renderer->html);
        ActivityEvent::query()->delete();
        $this->renderer(fn () => DB::table('contracts')->where('id', $this->contract->id)->update(['vl_parcial' => '300.0000']));
        $this->get($this->url())->assertConflict();
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_lease_appointment_requires_one_matching_manager_and_is_rechecked(): void
    {
        $this->contract->update(['tipo' => 'Locação de Imóvel']);
        $this->product();
        $raw = ['CPF_Gestor' => '11144477735', 'Decreto' => 'DECRETO TESTE 001', 'Matricula' => 'MATRICULA TESTE 123'];
        $record = ['legacy_id' => 90001, 'name' => 'Gestor fictício', 'name_key' => 'GESTOR FICTICIO',
            'fund_name_key' => 'FUNDO DE TESTE', 'raw_payload' => json_encode($raw)];
        $manager = DB::table('tb_Gestor')->insertGetId($record);
        $renderer = $this->renderer();
        $this->get($this->url())->assertOk();
        $this->assertStringContainsString('DECRETO TESTE 001', $renderer->html);
        $this->assertStringContainsString('MATRICULA TESTE 123', $renderer->html);
        ActivityEvent::query()->delete();
        $this->renderer(fn () => DB::table('tb_Gestor')->where('id', $manager)->update([
            'raw_payload' => json_encode(array_replace($raw, ['Decreto' => 'ALTERADO'])),
        ]));
        $this->get($this->url())->assertConflict();
        $this->assertDatabaseCount('activity_events', 0);
        DB::table('tb_Gestor')->insert(array_replace($record, ['legacy_id' => 90002]));
        $renderer = $this->renderer();
        $this->get($this->url())->assertOk();
        $this->assertStringNotContainsString('DECRETO TESTE 001', $renderer->html);
        $this->assertStringNotContainsString('MATRICULA TESTE 123', $renderer->html);
    }

    public function test_word_model_generates_full_private_pdf_with_saved_products_budgets_and_audit(): void
    {
        $this->product();
        $this->product(['item' => '2', 'description' => 'Produto de outra ficha', 'Ficha' => '002', 'quantity' => '1.0000', 'unit_price' => '10.0000', 'total' => '10.0000']);
        $this->contract->update(['valor_total' => '2540.9200', 'ext_total' => 'Dois mil quinhentos e quarenta reais e noventa e dois centavos']);
        $this->product(['description' => 'PRODUTO EXCLUIDO'])->delete();
        $other = $this->contract->replicate();
        $other->numero = '099/26';
        $other->save();
        $this->product(['contract_id' => $other->id, 'description' => 'PRODUTO DE OUTRO CONTRATO']);
        $budget = $this->budget();
        $this->budget(['kind' => 'ficha', 'budget_id' => $budget->id, 'amount' => '2530.9200', 'payload' => ['Ficha' => '001']]);
        $this->budget(['contract_id' => $other->id, 'payload' => ['Dotacao' => 'DOTACAO DE OUTRO CONTRATO']]);
        $this->budget(['status' => 'rascunho', 'payload' => ['Dotacao' => 'DOTACAO RASCUNHO']]);
        $this->budget(['payload' => ['Dotacao' => 'DOTACAO EXCLUIDA']])->delete();
        DB::table('document_settings')->where('id', 1)->update(['administration_start_year' => 2029, 'administration_end_year' => 2032]);

        $renderer = $this->renderer();
        $response = $this->get($this->url().'&tipo=Obras')->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertGreaterThan(20_000, strlen($response->getContent()));
        $this->assertGreaterThan(3, preg_match_all('~/Type\s*/Page\b~', $response->getContent()));
        $text = html_entity_decode(strip_tags($renderer->html));
        foreach (['CONTRATO DE FORNECIMENTO Nº. 015/26', '202600001', '012/26', 'Fundo de teste', '11.222.333/0001-81',
            'Praça de teste, 1', 'Fornecedor fictício', '11.444.777/0001-61', 'Representante fictício', '529.982.247-25',
            'RG SOCIO PRIVADO', '111.444.777-35', 'RG GESTOR PRIVADO', 'Gestor fictício', 'Gestor do Fundo',
            '20/12/2025', '02/01/2026', '31/12/2026', 'Dois mil quinhentos', '2.530,92',
            'Produto exclusivo do contrato', 'Produto de outra ficha', 'Marca teste', '30149', '2,50', '1.012,368', '01.02.03.4.5',
            'TOTAL GERAL', '2.540,92', 'Gestão 2029–2032', 'CLÁUSULA PRIMEIRA', 'CLÁUSULA DÉCIMA SEXTA', 'TESTEMUNHAS:'] as $value) {
            $this->assertStringContainsString($value, $text);
        }
        foreach (['#CONTRATO', '#CPF', '#OBJETO', 'XXCVXCV', 'PRODUTO EXCLUIDO', 'PRODUTO DE OUTRO CONTRATO',
            'DOTACAO DE OUTRO CONTRATO', 'DOTACAO RASCUNHO', 'DOTACAO EXCLUIDA', '9.999,00', '28/02/2027', '#FICHA', 'Ficha:'] as $value) {
            $this->assertStringNotContainsString($value, $text);
        }
        $audit = ActivityEvent::sole();
        $this->assertSame('contract_document_generated', $audit->action);
        $this->assertSame(hash('sha256', $response->getContent()), $audit->metadata['sha256']);
        $this->assertSame($this->contract->id, $audit->metadata['contract_id']);
        $this->assertSame('2029/2032', $audit->metadata['administration']);
        $this->assertStringNotContainsString('52998224725', $audit->toJson());
    }

    public function test_absent_data_is_explicit_and_user_text_is_escaped_without_dummy_items(): void
    {
        $this->product();
        $this->contract->update(['objeto' => '<script>alert(1)</script> & material', 'socio' => null]);
        $renderer = $this->renderer();
        $this->get($this->url())->assertOk();
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; material', $renderer->html);
        $this->assertStringNotContainsString('<script>', $renderer->html);
        $this->assertStringContainsString('01.02.03.4.5', $renderer->html);
        $this->assertStringNotContainsString('não informados nos registros conferidos', $renderer->html);
        $this->assertStringContainsString('Não informado', $renderer->html);
        $this->assertStringNotContainsString('XXCVXCV', $renderer->html);
    }

    public function test_long_product_descriptions_flow_across_pages_without_losing_text_or_repeating_amounts(): void
    {
        $description = 'INICIO_DESCRICAO '.str_repeat('Material com especificação detalhada. ', 125).'FIM_DESCRICAO';
        $this->product(['description' => $description]);
        $renderer = $this->renderer();
        $response = $this->get($this->url())->assertOk();
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$renderer->html);
        $xpath = new \DOMXPath($document);
        $actual = '';
        foreach ($xpath->query('//table[@class="products"]/tbody/tr/td[3]') as $cell) {
            $actual .= $cell->textContent;
        }
        $this->assertSame($description, $actual);
        $this->assertStringContainsString('(cont.)', $renderer->html);
        $this->assertSame(1, $xpath->query('//table[@class="products"]/tbody/tr/td[8][contains(., "2.530,92")]')->length);
    }

    public function test_issuance_permission_scope_and_personal_masking_are_enforced(): void
    {
        $this->product();
        $user = User::create(['name' => 'Usuário', 'username' => 'PDF_USER', 'password' => 'Synthetic123']);
        $scope = AccessScope::create(['scope_key' => 'supply-pdf', 'fund_id' => $this->contract->fund_id, 'fund' => 'Fundo de teste']);
        $user->scopes()->attach($scope);
        $this->actingAs($user)->get($this->url())->assertForbidden();
        $user->catalog_permissions = ['contract-issuance'];
        $user->save();
        $renderer = $this->renderer();
        $this->get($this->url())->assertOk();
        $this->assertStringContainsString('•••4725', $renderer->html);
        foreach (['529.982.247-25', '111.444.777-35', 'RG SOCIO PRIVADO', 'RG GESTOR PRIVADO'] as $value) {
            $this->assertStringNotContainsString($value, $renderer->html);
        }
        $user->scopes()->detach();
        $this->withSession(['fund_id' => null])->get($this->url())->assertForbidden();
    }

    public function test_long_budget_text_flows_without_truncation_or_repeating_amounts(): void
    {
        $this->product();
        $allocation = 'INICIO_DOTACAO '.str_repeat('Código orçamentário detalhado. ', 125).'FIM_DOTACAO';
        DB::table('tb_Dotacao')->where('Ficha', '001')->update(['Dotacao' => $allocation]);
        $budget = $this->budget(['payload' => ['Ficha' => '001', 'Dotacao' => $allocation, 'Descricao' => 'Descrição fora das linhas de dotação']]);
        $this->budget(['kind' => 'ficha', 'budget_id' => $budget->id, 'amount' => '2530.9200', 'payload' => ['Ficha' => '001']]);
        $renderer = $this->renderer();
        $this->get($this->url())->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$renderer->html);
        $xpath = new \DOMXPath($document);
        $actual = '';
        foreach ($xpath->query('//table[@class="budget-lines"]/tbody/tr/td[1]') as $cell) {
            $actual .= $cell->textContent;
        }
        $this->assertSame($allocation.' (001)', $actual);
        $this->assertSame(1, $xpath->query('//table[@class="budget-lines"]/tbody/tr')->length);
        $this->assertSame(1, $xpath->query('//table[@class="budget-lines"]/tbody/tr/td[3][contains(., "R$ 2.530,92")]')->length);
        $this->assertStringNotContainsString('Descrição fora das linhas de dotação', $renderer->html);
    }

    public function test_one_table_consolidates_items_across_fichas_and_matches_contract_total(): void
    {
        foreach (['202601109', '202601108'] as $ficha) {
            DB::table('tb_Dotacao')->insert(['Ficha' => $ficha, 'Dotacao' => '04.04.0401.10.301.7019.2245.449052(137)'.$ficha]);
            $this->product(['Ficha' => $ficha, 'item' => '2', 'quantity' => '1.0000', 'unit_price' => '327990.0000', 'total' => '327990.0000']);
            $this->product(['Ficha' => $ficha, 'item' => '3', 'description' => 'Outro produto', 'quantity' => '1.0000', 'unit_price' => '333990.0000', 'total' => '333990.0000']);
        }
        $this->contract->update(['valor_total' => '1323960.0000']);
        $renderer = $this->renderer();
        $this->get($this->url())->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$renderer->html);
        $xpath = new \DOMXPath($document);
        $tables = $xpath->query('//table[@class="products"]');
        $this->assertCount(1, $tables);
        $table = $tables->item(0);
        $headers = array_map(fn ($node) => $node->textContent, iterator_to_array($xpath->query('./thead/tr/th', $table)));
        $this->assertSame(['Item', 'Cod', 'Discriminação', 'Marca', 'UND', 'QTD', 'VL_Unit', 'VL_Total'], $headers);
        $this->assertSame(3, $xpath->query('./tbody/tr', $table)->length);
        $this->assertSame('2,00', $xpath->evaluate('string(./tbody/tr[1]/td[6])', $table));
        $this->assertSame('327.990,00', $xpath->evaluate('string(./tbody/tr[1]/td[7])', $table));
        $this->assertSame('655.980,00', $xpath->evaluate('string(./tbody/tr[1]/td[8])', $table));
        $this->assertSame('2,00', $xpath->evaluate('string(./tbody/tr[2]/td[6])', $table));
        $this->assertSame('667.980,00', $xpath->evaluate('string(./tbody/tr[2]/td[8])', $table));
        $this->assertSame('1.323.960,00', $xpath->evaluate('string(./tbody/tr[@class="products-total"]/td[2])', $table));
        $this->assertSame(0, $xpath->query('./caption', $table)->length);
        $this->assertStringNotContainsString('Ficha:', $renderer->html);
    }

    public function test_budget_lines_keep_each_ficha_and_amount_together_without_a_header_or_borders(): void
    {
        $contractTotal = '663214.5600';
        $this->contract->update(['valor_total' => $contractTotal]);
        foreach (['202601109' => '661980.0000', '202601108' => '1234.5600'] as $ficha => $amount) {
            DB::table('tb_Dotacao')->insert(['Ficha' => (string) $ficha, 'Dotacao' => '04.04.0401.10.301.7019.2245.449052(137)'.$ficha]);
            $this->product(['Ficha' => (string) $ficha, 'item' => (string) $ficha, 'quantity' => '1.0000', 'unit_price' => $amount, 'total' => $amount]);
            $budget = $this->budget(['payload' => ['Ficha' => (string) $ficha, 'Dotacao' => '04.04.0401.10.301.7019.2245.449052(137)'.$ficha]]);
            $this->budget(['kind' => 'ficha', 'budget_id' => $budget->id, 'amount' => $amount, 'payload' => ['Ficha' => (string) $ficha]]);
            $this->budget(['kind' => 'ficha', 'budget_id' => $budget->id, 'amount' => '10.0000', 'payload' => ['Ficha' => (string) $ficha]]);
        }
        $renderer = $this->renderer();
        $this->get($this->url())->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$renderer->html);
        $xpath = new \DOMXPath($document);
        $rows = $xpath->query('//table[@class="budget-lines"]/tbody/tr');
        $this->assertCount(2, $rows);
        $this->assertSame('04.04.0401.10.301.7019.2245.449052(137)202601109', $xpath->evaluate('string(./td[1])', $rows->item(0)));
        $this->assertSame('R$ 661.980,00', $xpath->evaluate('string(./td[3])', $rows->item(0)));
        $this->assertSame('04.04.0401.10.301.7019.2245.449052(137)202601108', $xpath->evaluate('string(./td[1])', $rows->item(1)));
        $this->assertSame('R$ 1.234,56', $xpath->evaluate('string(./td[3])', $rows->item(1)));
        $this->assertSame(0, $xpath->query('//table[@class="budget-lines"]/thead')->length);
        $this->assertSame(2, $xpath->query('//table[@class="budget-lines"]//td[@class="budget-leader"]')->length);
    }

    public function test_type_revision_required_version_deleted_contract_and_guest_are_protected(): void
    {
        $this->get($this->url($this->contract->fresh()->version + 1))->assertConflict();
        $this->get('/contracts/'.$this->contract->id.'/document')->assertSessionHasErrors('version');
        $this->contract->update(['tipo' => 'Tipo sem modelo']);
        $this->get($this->url().'&tipo=Fornecimento')->assertUnprocessable();
        $this->contract->delete();
        $this->get($this->url())->assertNotFound();
        $this->assertDatabaseCount('activity_events', 0);
        auth()->logout();
        $this->get($this->url())->assertRedirect('/login');
    }

    public function test_products_define_budget_totals_without_financial_records_or_catalog_scope_filters(): void
    {
        $this->product(['quantity' => '1.0000', 'unit_price' => '1000.0000', 'total' => '1000.0000']);
        $this->product(['item' => '2', 'quantity' => '1.0000', 'unit_price' => '1530.9200', 'total' => '1530.9200']);
        DB::table('tb_Dotacao')->where('Ficha', '001')->update(['Fundo' => 'CATALOGO GLOBAL', 'Secretaria' => 'OUTRA']);
        DB::table('tb_Dotacao')->insert(['Ficha' => '001', 'Dotacao' => '01.02.03.4.5']);
        $renderer = $this->renderer();
        $this->get($this->url())->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$renderer->html);
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//table[@class="budget-lines"]/tbody/tr')->length);
        $this->assertSame('R$ 2.530,92', $xpath->evaluate('string(//table[@class="budget-lines"]/tbody/tr/td[3])'));
        $this->assertDatabaseCount('contract_financial_records', 0);
    }

    public function test_missing_ficha_or_missing_blank_conflicting_allocation_blocks_emission_without_audit(): void
    {
        $product = $this->product(['Ficha' => null]);
        $this->getJson($this->url())->assertUnprocessable()->assertJsonFragment(['message' => 'Há produto sem ficha. Informe a ficha para identificar sua dotação antes de emitir.']);
        $product->update(['Ficha' => 'INEXISTENTE']);
        $this->getJson($this->url())->assertUnprocessable();
        $product->update(['Ficha' => '001']);
        DB::table('tb_Dotacao')->where('Ficha', '001')->update(['Dotacao' => '']);
        $this->getJson($this->url())->assertUnprocessable();
        DB::table('tb_Dotacao')->where('Ficha', '001')->update(['Dotacao' => '01']);
        DB::table('tb_Dotacao')->insert(['Ficha' => '001', 'Dotacao' => '02']);
        $this->getJson($this->url())->assertUnprocessable();
        $this->assertDatabaseCount('activity_events', 0);
        $this->assertSame('2530.9200', $product->fresh()->total);
    }

    public function test_missing_products_or_inconsistent_totals_block_pdf_without_audit_or_data_changes(): void
    {
        $this->getJson($this->url())->assertUnprocessable()->assertJsonPath('message', 'Cadastre os produtos do contrato antes de emitir o documento.');
        $this->get($this->url())->assertUnprocessable()->assertSee('Cadastre os produtos do contrato antes de emitir o documento.')
            ->assertHeader('Cache-Control', 'no-store, private');
        $product = $this->product(['total' => '1.0000']);
        $this->getJson($this->url())->assertUnprocessable();
        $this->assertSame('1.0000', $product->fresh()->total);
        $product->update(['total' => '2530.9200']);
        $this->contract->update(['valor_total' => '2531.0000']);
        $this->getJson($this->url())->assertUnprocessable();
        $this->assertSame('2531.0000', $this->contract->fresh()->valor_total);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public static function changesDuringRendering(): array
    {
        return [['contract'], ['product'], ['code'], ['budget'], ['budget_deleted'], ['budget_added'], ['ficha'], ['settings'], ['session'], ['role'], ['scope'], ['permission'], ['deleted'], ['signer']];
    }

    public static function leaseChangesDuringRendering(): array
    {
        return array_map(static fn ($case) => [$case[0], 'Locação de Imóvel'], self::changesDuringRendering());
    }

    public static function serviceChangesDuringRendering(): array
    {
        return array_map(static fn ($case) => [$case[0], 'Serviços Diversos'], self::changesDuringRendering());
    }

    public static function worksChangesDuringRendering(): array
    {
        return array_map(static fn ($case) => [$case[0], 'Obras'], self::changesDuringRendering());
    }

    public static function healthChangesDuringRendering(): array
    {
        return array_map(static fn ($case) => [$case[0], 'Credenciamento Saúde'], self::changesDuringRendering());
    }

    #[DataProvider('changesDuringRendering')]
    #[DataProvider('leaseChangesDuringRendering')]
    #[DataProvider('serviceChangesDuringRendering')]
    #[DataProvider('worksChangesDuringRendering')]
    #[DataProvider('healthChangesDuringRendering')]
    public function test_changes_during_rendering_prevent_delivery_and_audit(string $change, string $type = 'Fornecimento'): void
    {
        $this->contract->update(['tipo' => $type]);
        $product = $this->product();
        $budget = $this->budget();
        $user = User::create(['name' => 'Emissor', 'username' => 'PDF_ISSUER', 'password' => 'Synthetic123']);
        $user->catalog_permissions = ['contract-issuance'];
        $user->save();
        $scope = AccessScope::create(['scope_key' => 'pdf-render', 'fund_id' => $this->contract->fund_id, 'fund' => 'Fundo de teste']);
        $user->scopes()->attach($scope);
        $this->actingAs($user);
        $this->renderer(function () use ($change, $product, $user) {
            match ($change) {
                'contract' => DB::table('contracts')->where('id', $this->contract->id)->increment('version'),
                'product' => DB::table('tb_Produtos_Contratos')->where('id', $product->id)->update(['description' => 'Alterado']),
                'code' => DB::table('tb_Produtos_Contratos')->where('id', $product->id)->update(['code' => 'CODIGO ALTERADO']),
                'budget' => DB::table('tb_Dotacao')->where('Ficha', '001')->update(['Dotacao' => 'Alterada']),
                'budget_deleted' => DB::table('tb_Dotacao')->where('Ficha', '001')->delete(),
                'budget_added' => DB::table('tb_Dotacao')->insert(['Ficha' => '001', 'Dotacao' => 'NOVA DOTACAO']),
                'ficha' => DB::table('tb_Produtos_Contratos')->where('id', $product->id)->update(['Ficha' => '002']),
                'settings' => DB::table('document_settings')->where('id', 1)->increment('version'),
                'session' => DB::table('users')->where('id', $user->id)->increment('session_version'),
                'role' => DB::table('users')->where('id', $user->id)->update(['user_type' => 'Administrador']),
                'scope' => $user->scopes()->detach(),
                'permission' => DB::table('users')->where('id', $user->id)->update(['catalog_permissions' => '[]']),
                'deleted' => $this->contract->delete(),
                'signer' => DB::table('contracts')->where('id', $this->contract->id)->update(['nome_gestor' => 'Outro responsável']),
            };
        });
        $status = match ($change) {
            'session', 'role', 'scope', 'permission' => 403,
            'deleted' => 404,
            default => 409,
        };
        $this->get($this->url())->assertStatus($status);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_audit_failure_prevents_pdf_delivery(): void
    {
        $this->product();
        $this->renderer(fn () => null);
        ActivityEvent::creating(fn () => throw new \RuntimeException('Synthetic audit failure'));
        try {
            $this->get($this->url())->assertStatus(500);
            $this->assertDatabaseCount('activity_events', 0);
        } finally {
            ActivityEvent::flushEventListeners();
        }
    }

    public function test_contract_button_requires_saved_supply_contract_and_issuance_permission(): void
    {
        $this->blade('<x-contract-document-actions :contract="$contract"/>', ['contract' => $this->contract])
            ->assertSee('data-contract-document-url=', false)->assertSee('Abrir contrato de fornecimento em PDF.');
        $this->contract->tipo = 'Tipo sem modelo';
        $this->blade('<x-contract-document-actions :contract="$contract"/>', ['contract' => $this->contract])
            ->assertDontSee('data-contract-document-url=', false);
        $this->blade('<x-contract-document-actions :contract="$contract"/>', ['contract' => new Contract(['tipo' => 'Fornecimento'])])
            ->assertSee('Salve o contrato antes de emitir Contrato.')->assertDontSee('data-contract-document-url=', false);
        $user = User::create(['name' => 'Usuário', 'username' => 'NO_ISSUE', 'password' => 'Synthetic123']);
        $this->actingAs($user);
        $this->contract->tipo = 'Fornecimento';
        $this->blade('<x-contract-document-actions :contract="$contract"/>', ['contract' => $this->contract])
            ->assertDontSee('data-contract-document-url=', false);
    }
}
