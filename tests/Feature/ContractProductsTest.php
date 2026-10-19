<?php

namespace Tests\Feature;

use App\Application\Security\ActorIdentity;
use App\Infrastructure\Contracts\ProcurementProducts;
use App\Models\AccessScope;
use App\Models\Contract;
use App\Models\ContractAudit;
use App\Models\ContractFinancialRecord;
use App\Models\ContractProduct;
use App\Models\ProcurementProduct;
use App\Models\User;
use App\Services\ContractWriter;
use App\Services\Legacy\BudgetImporter;
use App\Services\Legacy\FichaValuesImporter;
use App\Services\Legacy\ProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ContractProductsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Contract $contract;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'TestPassword12345', 'user_type' => 'Administrador']);
        $fund = DB::table('contract_funds')->insertGetId(['name' => 'FMS', 'name_key' => 'FMS', 'raw_payload' => '{}']);
        $this->contract = Contract::create(['numero' => '001/26', 'fund_id' => $fund, 'fundo_nome_snapshot' => 'FMS', 'ownership_resolved' => true, 'quality_issues' => [],
            'processo' => '123/2026', 'fornecedor_documento_snapshot' => '11222333000181', 'valor_total' => '5000.0000']);
        // Simulate the existing external mechanism: only the documented legacy fields are supplied.
        $this->productId = DB::table('tb_Produtos')->insertGetId(['ID_Produto' => 9001, 'Processo' => '123/2026', 'CNPJ_Fornec' => '11.222.333/0001-81',
            'Item' => '1', 'Cod_Prod' => 'CAD-01', 'Discriminacao' => 'Cadeira', 'Marca' => 'Marca teste', 'Unidade' => 'UN',
            'QTD' => '100,0000', 'QTD_Acumulado' => '100,0000', 'QTD_Autorizado' => '0', 'VL_Unitario' => '12,3456', 'VL_Total' => '1234,5600']);
        $this->actingAs($this->admin);
    }

    private function url(?Contract $contract = null): string
    {
        return '/contracts/'.($contract ?? $this->contract)->id;
    }

    private function data(array $data = [], ?Contract $contract = null): array
    {
        return $data + ['contract_version' => ($contract ?? $this->contract)->fresh()->version];
    }

    private function add(string $quantity = '40', ?Contract $contract = null, array $extra = [])
    {
        return $this->postJson($this->url($contract).'/products', $this->data($extra + ['product_id' => $this->productId, 'quantity' => $quantity], $contract));
    }

    public function test_ficha_selection_tracks_allocations_edits_and_last_reversal_and_filters_exactly(): void
    {
        $base = $this->url().'/products';
        $this->getJson($base)->assertOk()->assertJsonPath('fichas', []);
        $this->add('1', null, ['ficha' => '001'])->assertCreated();
        $first = ContractProduct::sole();
        $this->add('2', null, ['ficha' => '1'])->assertCreated();
        $second = ContractProduct::orderByDesc('id')->first();
        $this->add('1', null, ['ficha' => '001'])->assertCreated();
        $this->add('1', null, ['ficha' => '0'])->assertCreated();
        $this->add('1')->assertCreated();
        $this->getJson($base)->assertOk()->assertJsonPath('fichas', ['0', '001', '1'])->assertJsonCount(5, 'items');
        $this->getJson($base.'?ficha=001')->assertOk()->assertJsonCount(2, 'items')->assertJsonPath('items.0.id', $first->id)
            ->assertJsonPath('items.0.Ficha', '001')->assertJsonPath('items.1.Ficha', '001')->assertJsonCount(3, 'fichas');
        $this->getJson($base.'?ficha=1')->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $second->id);
        $this->getJson($base.'?ficha=0')->assertJsonCount(1, 'items')->assertJsonPath('items.0.Ficha', '0');
        $this->putJson($base.'/'.$second->id, $this->data(['quantity' => '2', 'ficha' => '0007']))->assertOk();
        $this->getJson($base)->assertJsonPath('fichas', ['0', '001', '0007']);
        $this->deleteJson($base.'/'.$second->id, $this->data())->assertOk();
        $this->getJson($base.'?ficha=0007')->assertJsonPath('fichas', ['0', '001'])->assertJsonCount(0, 'items');
        $this->getJson($base.'?ficha='.str_repeat('1', 65))->assertUnprocessable();
        $this->blade('<x-contract-products :contract="$contract"/>', ['contract' => $this->contract])
            ->assertSee('data-product-budget list="contract-product-fichas-', false)
            ->assertSee('<option value="001">', false)->assertSee('<option value="0">', false)
            ->assertDontSee('Nova ficha')->assertDontSee('data-product-new-ficha', false);
    }

    public function test_ficha_options_and_filter_never_include_another_contract_or_expand_user_access(): void
    {
        $this->add('1', null, ['ficha' => 'OWN'])->assertCreated();
        $other = $this->contract->replicate();
        $other->numero = '099/26';
        $other->save();
        $this->add('1', $other, ['ficha' => 'PRIVATE-OTHER'])->assertCreated();
        $this->get($this->url().'/edit')->assertOk()
            ->assertSee('id="contract-product-fichas-'.$this->contract->id.'"', false)
            ->assertSee('<option value="OWN">', false)->assertDontSee('<option value="PRIVATE-OTHER">', false)
            ->assertDontSee('Nova ficha');
        $this->getJson($this->url().'/products?ficha=PRIVATE-OTHER')->assertOk()->assertJsonPath('fichas', ['OWN'])->assertJsonCount(0, 'items');
        $user = User::create(['name' => 'Sem acesso', 'username' => 'NO_PRODUCT_FICHAS', 'password' => 'TestPassword12345']);
        $this->actingAs($user)->getJson($this->url().'/products?ficha=OWN')->assertForbidden()->assertJsonMissingPath('fichas');
    }

    public function test_ficha_values_match_contract_fund_and_process_and_require_contract_access(): void
    {
        $row = ['Ficha' => '00123', 'Fundo' => ' fms ', 'Contrato' => '001/26', 'Proc_Cont' => ' 123/2026 ', 'CPF_CNPJ' => '11.222.333/0001-81', 'Valor' => '1.234,5678'];
        DB::table('tb_ValorFicha')->insert($row);
        DB::table('tb_ValorFicha')->insert(array_replace($row, ['Valor' => '0,0001']));
        foreach (['Ficha' => '123', 'Fundo' => 'Outro Fundo', 'Contrato' => '002/26', 'Proc_Cont' => 'OTHER'] as $field => $value) {
            DB::table('tb_ValorFicha')->insert(array_replace($row, [$field => $value, 'Valor' => '9999']));
        }
        $url = $this->url().'/products/budget-lookup?ficha=00123';
        $this->getJson($url)->assertOk()->assertJsonCount(2, 'ficha_values')
            ->assertJsonPath('ficha_values.0', ['label' => '00123', 'total' => '1234.5678'])
            ->assertJsonPath('ficha_values.1.total', '0.0001')
            ->assertJsonMissingPath('ficha_values.0.CPF_CNPJ')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson($this->url().'/products/budget-lookup?ficha=999')->assertJsonCount(0, 'ficha_values');
        DB::table('tb_ValorFicha')->insert(array_replace($row, ['Ficha' => '0008', 'CPF_CNPJ' => '52998224725']));
        $this->getJson($this->url().'/products')->assertOk()->assertJsonCount(4, 'ficha_values')
            ->assertJsonPath('ficha_values.3.label', '0008');
        $user = User::create(['name' => 'Sem acesso', 'username' => 'NO_FICHA', 'password' => 'TestPassword12345']);
        $this->actingAs($user)->getJson($url)->assertForbidden()->assertJsonMissingPath('ficha_values');
    }

    public function test_ficha_values_import_is_idempotent_and_preserves_conflicting_records(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ficha-values-');
        try {
            file_put_contents($path, "INSERT INTO `tb_ValorFicha` (`ID_Ficha`, `Fundo`, `Contrato`, `Ficha`, `Valor`, `CPF_CNPJ`, `N_ADT`) VALUES (12,'FMS','001/26','00123','12,3400','11222333000181',0);");
            $importer = new FichaValuesImporter;
            $this->assertSame(1, $importer->import($path)['imported_count']);
            $this->assertSame(1, $importer->import($path)['skipped_count']);
            DB::table('tb_ValorFicha')->where('ID_Ficha', 12)->update(['Valor' => '99']);
            $this->assertSame(1, $importer->import($path)['rejected_count']);
            $this->assertDatabaseHas('tb_ValorFicha', ['ID_Ficha' => 12, 'Valor' => '99']);
            $this->assertDatabaseCount('tb_Produtos_Contratos', 0);
        } finally {
            unlink($path);
        }
    }

    public function test_disp_subtracts_catalog_quantity_from_sum_for_item_supplier_and_process(): void
    {
        $lookup = $this->url().'/products/item-lookup?item=1';
        $this->getJson($lookup)->assertOk()->assertJsonPath('product.disp', '0.0000');
        $this->add('40')->assertCreated();
        $allocation = ContractProduct::firstOrFail();
        $this->getJson($lookup)->assertJsonPath('product.disp', '0.0000');
        foreach ([['item' => '2'], ['Processo' => 'OTHER'], ['CNPJ' => '52998224725'], ['deleted_at' => now()]] as $patch) {
            $copy = $allocation->replicate();
            $copy->fill($patch)->save();
        }
        DB::table('tb_Produtos')->where('id', $this->productId)->update(['QTD' => '40.0000']);
        $this->getJson($lookup)->assertJsonPath('product.disp', '0.0000');
        $copy = $allocation->replicate();
        $copy->QTD = '0,0001';
        $copy->quantity = '0.0001';
        $copy->CNPJ = '11.222.333/0001-81';
        $copy->save();
        $this->getJson($lookup)->assertJsonPath('product.disp', '0.0001');
        DB::table('tb_Produtos')->where('id', $this->productId)->update(['QTD' => '30.0000']);
        $this->getJson($lookup)->assertJsonPath('product.disp', '10.0001')->assertJsonPath('product.remaining', '30.0000');
        $this->getJson($this->url().'/products')->assertJsonPath('products.0.disp', '10.0001');
    }

    public function test_existing_catalog_is_distributed_between_funds_with_exact_totals_and_reversal(): void
    {
        $other = $this->contract->replicate();
        $other->fund_id = DB::table('contract_funds')->insertGetId(['name' => 'Executivo', 'name_key' => 'EXECUTIVO', 'raw_payload' => '{}']);
        $other->numero = '002/26';
        $other->save();
        $this->getJson($this->url().'/products')->assertOk()->assertJsonPath('products.0.code', 'CAD-01')->assertJsonPath('products.0.remaining', '100.0000');
        $this->add()->assertCreated()->assertJsonPath('total', '493.8240');
        $this->assertSame('60.0000', ProcurementProduct::find($this->productId)->QTD);
        $this->add('61', $other)->assertUnprocessable();
        $this->add('60', $other)->assertCreated()->assertJsonPath('total', '740.7360');
        $this->assertSame('0.0000', ProcurementProduct::find($this->productId)->QTD);
        $item = ContractProduct::where('contract_id', $this->contract->id)->firstOrFail();
        $this->assertDatabaseHas('tb_Produtos_Contratos', ['id' => $item->id, 'Cod_Prod' => 'CAD-01', 'Marca' => 'Marca teste', 'Contrato' => '001/26', 'QTD' => '40.0000', 'TOTAL_UNIT' => '493.8240']);
        $this->putJson($this->url().'/products/'.$item->id, $this->data(['quantity' => '30']))->assertOk()->assertJsonPath('total', '370.3680');
        $this->deleteJson($this->url().'/products/'.$item->id, $this->data())->assertOk()->assertJsonPath('total', '0.0000');
        $this->assertSoftDeleted('tb_Produtos_Contratos', ['id' => $item->id]);
        $this->assertSame('40.0000', ProcurementProduct::find($this->productId)->QTD);
        $this->assertSame('740.7360', $other->fresh()->valor_total);
        $this->assertDatabaseCount('tb_Produtos', 1);
        $this->assertDatabaseCount('contract_audits', 4);
        $this->assertDatabaseHas('tb_Produtos', ['id' => $this->productId, 'QTD_Acumulado' => '100,0000', 'QTD_Autorizado' => '0']);
        Http::assertNothingSent();
    }

    public function test_scope_foreign_supplier_and_process_are_enforced_for_reads_and_writes(): void
    {
        $user = User::create(['name' => 'FMS', 'username' => 'FMS', 'password' => 'TestPassword12345']);
        $this->actingAs($user)->getJson($this->url().'/products')->assertForbidden();
        $this->add()->assertForbidden();
        $scope = AccessScope::create(['scope_key' => 'FMS', 'fund_id' => $this->contract->fund_id, 'fund' => 'FMS']);
        $user->scopes()->attach($scope);
        $this->getJson($this->url().'/products')->assertOk()->assertJsonCount(1, 'products')->assertJsonMissingPath('products.0.CNPJ_Fornec');
        foreach ([['CNPJ_Fornec' => '52998224725'], ['CNPJ_Fornec' => '11222333000181', 'Processo' => 'OUTRO']] as $patch) {
            DB::table('tb_Produtos')->where('id', $this->productId)->update($patch);
            $this->getJson($this->url().'/products')->assertOk()->assertJsonCount(0, 'products');
            $this->add()->assertNotFound();
        }
        $this->assertDatabaseCount('tb_Produtos_Contratos', 0);
    }

    public function test_stale_version_insufficient_balance_invalid_price_and_invalid_quantities_cannot_write(): void
    {
        $this->add('101')->assertUnprocessable();
        $this->add('1', null, ['contract_version' => 999])->assertConflict()->assertJsonMissingPath('contract');
        foreach (['-1', '0.00001', 'NaN', '', '1e3'] as $price) {
            $this->add('1', null, ['unit_price' => $price])->assertUnprocessable()->assertJsonValidationErrors('unit_price');
        }
        foreach (['0', '-1', '0.00001', 'NaN', '1e3', '999999999999999.0000'] as $quantity) {
            $this->add($quantity)->assertUnprocessable();
        }
        $this->assertDatabaseCount('contract_audits', 0);
        $this->assertDatabaseCount('tb_Produtos_Contratos', 0);
        $this->add('0,0001')->assertCreated()->assertJsonPath('total', '0.0012');
        $this->assertSame('99.9999', ProcurementProduct::find($this->productId)->QTD);
    }

    public function test_manual_items_without_catalog_can_be_created_edited_and_reversed(): void
    {
        $fields = ['manual' => true, 'description' => 'Locação de imóvel', 'unit' => 'MÊS', 'unit_price' => '1500,1234', 'quantity' => '12', 'code' => 'IMOVEL', 'brand' => ''];
        $this->contract->update(['processo' => null]);
        $this->postJson($this->url().'/products', $this->data($fields))->assertCreated()->assertJsonPath('total', '18001.4808');
        $item = ContractProduct::firstOrFail();
        $this->assertNull($item->product_id);
        $this->assertSame('Locação de imóvel', $item->description);
        $this->assertSame('100,0000', ProcurementProduct::findOrFail($this->productId)->QTD);
        $this->getJson($this->url().'/products')->assertOk()->assertJsonPath('items.0.product_id', null);
        $this->putJson($this->url().'/products/'.$item->id, $this->data(array_replace($fields, ['quantity' => '10', 'description' => 'Locação revisada'])))
            ->assertOk()->assertJsonPath('total', '15001.2340');
        $this->assertSame('Locação revisada', $item->fresh()->description);
        $this->assertNull(ContractAudit::latest('id')->firstOrFail()->changes['product_balance']);
        $this->putJson($this->url().'/products/'.$item->id, $this->data(array_replace($fields, ['manual' => false])))->assertUnprocessable();
        $this->deleteJson($this->url().'/products/'.$item->id, $this->data())->assertOk()->assertJsonPath('total', '0.0000');
        $this->assertSoftDeleted('tb_Produtos_Contratos', ['id' => $item->id]);
        $this->assertDatabaseCount('tb_Produtos', 1);
        $this->assertSame('100,0000', ProcurementProduct::findOrFail($this->productId)->QTD);
    }

    public function test_manual_items_require_access_valid_data_and_atomic_audit(): void
    {
        $fields = ['manual' => true, 'description' => 'Aluguel', 'unit' => 'MÊS', 'unit_price' => '10', 'quantity' => '1'];
        foreach (['description', 'unit', 'unit_price'] as $field) {
            $this->postJson($this->url().'/products', $this->data(array_diff_key($fields, [$field => true])))->assertUnprocessable();
        }
        $this->postJson($this->url().'/products', $this->data($fields + ['product_id' => $this->productId]))->assertUnprocessable();
        $this->postJson($this->url().'/products', $this->data(array_replace($fields, ['contract_version' => 999])))->assertConflict();
        $user = User::create(['name' => 'Sem acesso', 'username' => 'SEM_ACESSO', 'password' => 'TestPassword12345']);
        $this->actingAs($user)->postJson($this->url().'/products', $this->data($fields))->assertForbidden();
        $this->actingAs($this->admin);
        ContractAudit::creating(fn () => throw new RuntimeException('audit failure'));
        try {
            app(ProcurementProducts::class)->allocate(new ActorIdentity($this->admin->id, $this->admin->session_version), $this->contract->id, $this->data($fields));
            $this->fail('Expected audit failure');
        } catch (RuntimeException $error) {
            $this->assertSame('audit failure', $error->getMessage());
        } finally {
            ContractAudit::flushEventListeners();
        }
        $this->assertDatabaseCount('tb_Produtos_Contratos', 0);
        $this->assertSame('5000.0000', $this->contract->fresh()->valor_total);
    }

    public function test_catalog_unit_price_cannot_be_changed_when_creating_or_editing(): void
    {
        $this->add('2', null, ['unit_price' => '10,1234'])->assertUnprocessable()->assertJsonValidationErrors('unit_price');
        $this->assertDatabaseCount('tb_Produtos_Contratos', 0);
        $this->assertDatabaseCount('contract_audits', 0);
        $this->assertSame('100,0000', ProcurementProduct::findOrFail($this->productId)->QTD);
        $this->add('2', null, ['unit_price' => '12,3456'])->assertCreated()->assertJsonPath('total', '24.6912');
        $item = ContractProduct::firstOrFail();
        $this->assertSame('12.3456', $item->unit_price);
        $this->assertSame('12,3456', ProcurementProduct::findOrFail($this->productId)->VL_Unitario);
        $this->putJson($this->url().'/products/'.$item->id, $this->data(['quantity' => '2', 'unit_price' => '11,0001']))
            ->assertUnprocessable()->assertJsonValidationErrors('unit_price');
        $this->assertSame('12.3456', $item->fresh()->VL_UNIT);
        $this->assertDatabaseCount('contract_audits', 1);
        $this->putJson($this->url().'/products/'.$item->id, $this->data(['quantity' => '3', 'unit_price' => '12,3456']))
            ->assertOk()->assertJsonPath('total', '37.0368');
        $audit = ContractAudit::latest('id')->firstOrFail();
        $this->assertSame('12.3456', $audit->changes['products']['before']['unit_price']);
        $this->assertSame('12.3456', $audit->changes['products']['after']['unit_price']);
        $this->deleteJson($this->url().'/products/'.$item->id, $this->data())->assertOk()->assertJsonPath('total', '0.0000');
        $this->assertSame('100.0000', ProcurementProduct::findOrFail($this->productId)->QTD);
    }

    public function test_audit_failure_rolls_back_source_balance_total_version_and_contract_item(): void
    {
        $version = $this->contract->fresh()->version;
        ContractAudit::creating(fn () => throw new RuntimeException('audit failure'));
        try {
            app(ProcurementProducts::class)->allocate(new ActorIdentity($this->admin->id, $this->admin->session_version), $this->contract->id,
                ['contract_version' => $version, 'product_id' => $this->productId, 'quantity' => '40']);
            $this->fail('Expected audit failure');
        } catch (RuntimeException $error) {
            $this->assertSame('audit failure', $error->getMessage());
        } finally {
            ContractAudit::flushEventListeners();
        }
        $this->assertSame($version, $this->contract->fresh()->version);
        $this->assertSame('5000.0000', $this->contract->fresh()->valor_total);
        $this->assertSame('100,0000', ProcurementProduct::find($this->productId)->QTD);
        $this->assertDatabaseCount('tb_Produtos_Contratos', 0);
    }

    public function test_managed_total_and_origin_cannot_be_overwritten_by_contract_editor(): void
    {
        $this->add('2')->assertCreated();
        $contract = $this->contract->fresh();
        app(ContractWriter::class)->save($this->admin, ['version' => $contract->version, 'reason' => 'Conferência do total', 'valor_total' => '999999'], $contract);
        $this->assertSame('24.6912', $contract->fresh()->valor_total);
        $this->putJson($this->url(), ['version' => $contract->fresh()->version, 'reason' => 'Troca indevida do processo', 'processo' => 'OUTRO'])->assertUnprocessable();
        $this->get($this->url().'/edit')->assertOk()->assertSee('value="R$ 24,69"', false)->assertSee('data-contract-product-list', false)
            ->assertSee('Exibir produtos do processo')->assertDontSee('Analisar documento com IA')->assertDontSee('data-product-document', false);
    }

    public function test_budget_is_optional_and_must_belong_to_current_contract(): void
    {
        $other = $this->contract->replicate();
        $other->save();
        $fields = ['year' => 2026, 'event_date' => '2026-10-16', 'created_by' => $this->admin->id, 'updated_by' => $this->admin->id];
        $foreign = ContractFinancialRecord::create($fields + ['contract_id' => $other->id, 'kind' => 'dotacao', 'payload' => ['Ficha' => '999'], 'number' => '999']);
        $this->add('2', null, ['budget_id' => $foreign->id])->assertUnprocessable();
        $budget = ContractFinancialRecord::create($fields + ['contract_id' => $this->contract->id, 'kind' => 'dotacao', 'payload' => ['Ficha' => '123', 'Descricao' => 'Material', 'Dotacao' => 'Dotação A'], 'number' => '123']);
        $this->add('2', null, ['budget_id' => $budget->id])->assertCreated();
        $this->assertDatabaseHas('tb_Produtos_Contratos', ['budget_id' => $budget->id, 'Ficha' => '123']);
        $this->getJson($this->url().'/products')->assertOk()->assertJsonCount(1, 'budgets')->assertJsonPath('budgets.0.number', '123')->assertJsonPath('items.0.Ficha', '123');
        $item = ContractProduct::firstOrFail();
        $this->deleteJson($this->url($other).'/products/'.$item->id, $this->data([], $other))->assertNotFound();
    }

    public function test_contract_snapshots_survive_catalog_edits(): void
    {
        $this->add('2')->assertCreated();
        DB::table('tb_Produtos')->where('id', $this->productId)->update(['Discriminacao' => 'Descrição nova', 'Marca' => 'Marca nova', 'VL_Unitario' => '999.0000']);
        $item = ContractProduct::firstOrFail();
        $this->putJson($this->url().'/products/'.$item->id, $this->data(['quantity' => '3']))->assertOk()->assertJsonPath('total', '37.0368');
        $this->assertSame('Cadeira', $item->fresh()->description);
        $this->assertSame('Marca teste', $item->fresh()->brand);
        $this->assertSame('12.3456', $item->fresh()->unit_price);
    }

    public function test_catalog_search_pagination_and_zero_or_malformed_balance(): void
    {
        for ($i = 2; $i <= 25; $i++) {
            DB::table('tb_Produtos')->insert(['Processo' => '123/2026', 'CNPJ_Fornec' => '11222333000181', 'item' => (string) $i,
                'Discriminacao' => 'Produto '.$i, 'Unidade' => 'UN', 'QTD' => '1', 'VL_Unitario' => '1']);
        }
        $this->getJson($this->url().'/products')->assertOk()->assertJsonCount(20, 'products')->assertJsonPath('last_page', 2);
        $this->getJson($this->url().'/products?page=2')->assertOk()->assertJsonCount(5, 'products');
        $this->getJson($this->url().'/products?search=CAD-01')->assertOk()->assertJsonCount(1, 'products');
        foreach (['0', 'texto inválido'] as $balance) {
            DB::table('tb_Produtos')->where('id', $this->productId)->update(['QTD' => $balance]);
            $this->getJson($this->url().'/products?search=CAD-01')->assertOk()->assertJsonPath('products.0.available', false);
            $this->add('1')->assertUnprocessable();
        }
    }

    public function test_ai_endpoints_are_no_longer_available(): void
    {
        $this->postJson($this->url().'/product-imports', $this->data())->assertNotFound();
        $this->getJson($this->url().'/product-imports/1')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_ficha_is_editable_without_a_registered_budget_and_can_be_changed_or_cleared(): void
    {
        $this->add('2', null, ['ficha' => '  FICHA-001  '])->assertCreated();
        $item = ContractProduct::firstOrFail();
        $this->assertSame('FICHA-001', $item->Ficha);
        $this->assertNull($item->budget_id);
        $this->putJson($this->url().'/products/'.$item->id, $this->data(['quantity' => '2', 'ficha' => '0007']))->assertOk();
        $this->assertSame('0007', $item->fresh()->Ficha);
        $this->putJson($this->url().'/products/'.$item->id, $this->data(['quantity' => '2']))->assertOk();
        $this->assertSame('0007', $item->fresh()->Ficha);
        $this->putJson($this->url().'/products/'.$item->id, $this->data(['quantity' => '2', 'ficha' => str_repeat('1', 65)]))->assertUnprocessable();
        $this->putJson($this->url().'/products/'.$item->id, $this->data(['quantity' => '2', 'ficha' => '']))->assertOk();
        $this->assertNull($item->fresh()->Ficha);
        $this->assertSame('24.6912', $this->contract->fresh()->valor_total);
    }

    public function test_ficha_lookup_uses_only_ficha_and_still_requires_contract_access(): void
    {
        $url = $this->url().'/products/budget-lookup?ficha=0007';
        $this->getJson($url)->assertOk()->assertJsonPath('status', 'not_found')->assertJsonPath('description', '');
        DB::table('tb_Dotacao')->insert(['Ficha' => '0007', 'Fundo' => 'Outro Fundo', 'Descricao' => 'Material de consumo', 'Dotacao' => 'Dotação separada']);
        $this->getJson($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('description', 'Material de consumo')->assertJsonPath('allocation', 'Dotação separada')->assertJsonMissingPath('Fundo');
        DB::table('tb_Dotacao')->insert(['Ficha' => '0007', 'Fundo' => 'FMS', 'Descricao' => 'Duplicada']);
        $this->getJson($url)->assertOk()->assertJsonPath('status', 'found')->assertJsonPath('description', 'Material de consumo');
        $user = User::create(['name' => 'Sem escopo', 'username' => 'NO-SCOPE', 'password' => 'TestPassword12345']);
        $this->actingAs($user)->getJson($url)->assertForbidden();
    }

    public function test_municipal_ficha_lookup_does_not_filter_by_department(): void
    {
        DB::table('contract_funds')->where('id', $this->contract->fund_id)->update(['name' => 'Município de Niquelândia']);
        $department = DB::table('contract_departments')->insertGetId(['name' => 'Educação', 'name_key' => 'EDUCACAO', 'raw_payload' => '{}']);
        $this->contract->update(['department_id' => $department]);
        DB::table('tb_Dotacao')->insert([
            ['Ficha' => '7', 'Fundo' => 'MUNICIPIO DE NIQUELANDIA', 'Secretaria' => 'Saúde', 'Descricao' => 'Outra secretaria', 'Dotacao' => 'Privada'],
            ['Ficha' => '7', 'Fundo' => 'MUNICIPIO DE NIQUELANDIA', 'Secretaria' => 'EDUCACAO', 'Descricao' => 'Descrição correta', 'Dotacao' => 'Dotação correta'],
        ]);
        $this->getJson($this->url().'/products/budget-lookup?ficha=7')->assertOk()->assertJsonPath('description', 'Outra secretaria');
        $this->contract->update(['department_id' => null]);
        $this->getJson($this->url().'/products/budget-lookup?ficha=7')->assertOk()->assertJsonPath('status', 'found');
    }

    public function test_product_import_preserves_raw_fields_is_idempotent_and_never_replenishes_balance(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'contracts-product-test-');
        try {
            file_put_contents($path, "DROP TABLE contracts; INSERT INTO `tb_produtos` (`ID_Produto`, `Processo`, `Item`, `Discriminacao`, `QTD`, `VL_Unitario`) VALUES (12, '2025000820', '001', 'Produto importado', '100,0000', 'inválido');");
            $importer = app(ProductImporter::class);
            $this->assertSame(1, $importer->import($path)['imported_count']);
            $this->assertSame(1, $importer->import($path)['skipped_count']);
            $this->assertDatabaseHas('tb_Produtos', ['ID_Produto' => 12, 'item' => '001', 'VL_Unitario' => 'inválido']);
            DB::table('tb_Produtos')->where('ID_Produto', 12)->update(['QTD' => '60.0000']);
            $this->assertSame(1, $importer->import($path)['rejected_count']);
            $this->assertDatabaseHas('tb_Produtos', ['ID_Produto' => 12, 'QTD' => '60.0000']);
            $this->assertSame('5000.0000', $this->contract->fresh()->valor_total);
            $this->assertDatabaseCount('tb_Produtos_Contratos', 0);
            $this->assertDatabaseHas('activity_events', ['action' => 'product_catalog_imported', 'actor_id' => null]);
            $this->assertDatabaseHas('legacy_records', ['source_table' => 'tb_Produtos', 'legacy_id' => '12', 'status' => 'conflict']);
        } finally {
            unlink($path);
        }
    }

    public function test_product_import_rolls_back_all_rows_when_dump_contains_nonliteral_values(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'contracts-product-test-');
        try {
            file_put_contents($path, "INSERT INTO `tb_Produtos` (`ID_Produto`, `Item`) VALUES (12, '1'); INSERT INTO `tb_Produtos` (`ID_Produto`, `Item`) VALUES (13, NOW());");
            try {
                app(ProductImporter::class)->import($path);
                $this->fail('Expected rejection of SQL expression');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('literais', $error->getMessage());
            }
            $this->assertDatabaseMissing('tb_Produtos', ['ID_Produto' => 12]);
            $this->assertDatabaseCount('import_runs', 0);
            $this->assertDatabaseCount('legacy_records', 0);
            $this->assertDatabaseCount('activity_events', 0);
        } finally {
            unlink($path);
        }
    }

    public function test_budget_import_is_idempotent_and_preserves_conflicting_catalog_rows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'contracts-budget-test-');
        try {
            file_put_contents($path, "INSERT INTO `tb_Dotacao` (`ID_Dotacao`, `Ficha`, `Fundo`, `Descricao`, `Dotacao`) VALUES (1, '0007', 'FMS', 'Material', 'Dotação');");
            $importer = app(BudgetImporter::class);
            $this->assertSame(1, $importer->import($path)['imported_count']);
            $this->assertSame(1, $importer->import($path)['skipped_count']);
            DB::table('tb_Dotacao')->where('ID_Dotacao', 1)->update(['Descricao' => 'Conferida localmente']);
            $this->assertSame(1, $importer->import($path)['rejected_count']);
            $this->assertDatabaseHas('tb_Dotacao', ['ID_Dotacao' => 1, 'Descricao' => 'Conferida localmente']);
            $this->assertDatabaseCount('tb_Produtos_Contratos', 0);
            $this->assertDatabaseHas('activity_events', ['action' => 'budget_catalog_imported', 'actor_id' => null]);
        } finally {
            unlink($path);
        }
    }

    public function test_urbanismo_ficha_matches_the_documented_legacy_abbreviation(): void
    {
        DB::table('contract_funds')->where('id', $this->contract->fund_id)->update(['name' => 'MUNICIPIO DE NIQUELÂNDIA']);
        $department = DB::table('contract_departments')->insertGetId(['name' => 'Secretaria Municipal de Urbanismo', 'name_key' => 'SECRETARIA MUNICIPAL DE URBANISMO', 'raw_payload' => '{}']);
        $this->contract->update(['department_id' => $department]);
        DB::table('tb_Dotacao')->insert(['Ficha' => '20260288', 'Fundo' => 'MUNICIPIO DE NIQUELÂNDIA',
            'Secretaria' => 'SEC MUNICIPAL DE URBANISMO', 'Descricao' => 'Material de urbanismo', 'Dotacao' => 'Dotação Urbanismo']);
        $url = $this->url().'/products/budget-lookup?ficha=20260288';
        $this->getJson($url)->assertOk()->assertJsonPath('status', 'found')->assertJsonPath('description', 'Material de urbanismo')->assertJsonPath('allocation', 'Dotação Urbanismo')->assertJsonPath('scope_matches', true);
        DB::table('contract_departments')->insert(['name' => 'SEC MUNICIPAL DE URBANISMO', 'name_key' => 'SEC MUNICIPAL DE URBANISMO', 'raw_payload' => '{}']);
        $this->getJson($url)->assertOk()->assertJsonPath('status', 'found')->assertJsonPath('description', 'Material de urbanismo');
    }

    public function test_form_department_is_ignored_without_mutating_the_contract(): void
    {
        DB::table('contract_funds')->where('id', $this->contract->fund_id)->update(['name' => 'MUNICIPIO DE NIQUELÂNDIA']);
        $urbanismo = DB::table('contract_departments')->insertGetId(['name' => 'Secretaria Municipal de Urbanismo', 'name_key' => 'URBANISMO', 'raw_payload' => '{}']);
        $government = DB::table('contract_departments')->insertGetId(['name' => 'Secretaria Municipal de Governo', 'name_key' => 'GOVERNO', 'raw_payload' => '{}']);
        $this->contract->update(['department_id' => $government]);
        DB::table('tb_Dotacao')->insert(['Ficha' => '20260288', 'Fundo' => 'MUNICIPIO DE NIQUELÂNDIA', 'Secretaria' => 'SEC MUNICIPAL DE URBANISMO', 'Descricao' => 'Urbanismo', 'Dotacao' => 'Dotação']);
        $url = $this->url().'/products/budget-lookup?ficha=20260288&department_id='.$urbanismo;
        $this->withSession(['fund_id' => $this->contract->fund_id])->getJson($url)->assertOk()->assertJsonPath('status', 'found');
        $this->assertSame($government, $this->contract->fresh()->department_id);
        $scope = AccessScope::create(['scope_key' => 'GOVERNO', 'fund_id' => $this->contract->fund_id, 'department_id' => $government,
            'fund' => 'MUNICIPIO DE NIQUELÂNDIA', 'department' => 'Secretaria Municipal de Governo']);
        $user = User::create(['name' => 'Governo', 'username' => 'GOV', 'password' => 'TestPassword12345']);
        $user->scopes()->attach($scope);
        $this->actingAs($user)->getJson($url)->assertOk()->assertJsonPath('status', 'found');
        $this->assertDatabaseCount('tb_Produtos_Contratos', 0);
    }

    public function test_scope_comparison_only_controls_confirmation_and_does_not_block_lookup(): void
    {
        DB::table('tb_Dotacao')->insert(['Ficha' => '8', 'Fundo' => 'Outro Fundo', 'Secretaria' => '', 'Descricao' => 'Material', 'Dotacao' => 'Dotação']);
        $url = $this->url().'/products/budget-lookup?ficha=8';
        $this->getJson($url)->assertOk()->assertJsonPath('status', 'found')->assertJsonPath('scope_matches', false)->assertJsonPath('fund', 'Outro Fundo');
        DB::table('tb_Dotacao')->where('Ficha', '8')->update(['Fundo' => ' fms ']);
        $this->getJson($url)->assertOk()->assertJsonPath('scope_matches', true);
        DB::table('tb_Dotacao')->where('Ficha', '8')->update(['Secretaria' => 'Outra secretaria']);
        $this->getJson($url)->assertOk()->assertJsonPath('status', 'found')->assertJsonPath('scope_matches', false);
        $this->assertDatabaseCount('tb_Produtos_Contratos', 0);
    }

    public function test_item_lookup_checks_supplier_unless_process_catalog_is_enabled(): void
    {
        $url = $this->url().'/products/item-lookup?item=1';
        $this->getJson($url)->assertOk()->assertJsonPath('status', 'found')->assertJsonPath('product.code', 'CAD-01')->assertJsonMissingPath('product.CNPJ_Fornec');
        DB::table('tb_Produtos')->where('id', $this->productId)->update(['CNPJ_Fornec' => '52998224725']);
        $this->getJson($url)->assertOk()->assertJsonPath('status', 'supplier_mismatch')->assertJsonMissingPath('product');
        $this->getJson($this->url().'/products')->assertOk()->assertJsonCount(0, 'products');
        $this->getJson($url.'&process_catalog=1')->assertOk()->assertJsonPath('status', 'found')->assertJsonPath('product.code', 'CAD-01');
        $this->getJson($this->url().'/products?process_catalog=1')->assertOk()->assertJsonCount(1, 'products');
        $this->add('1')->assertNotFound();
        $this->add('1', null, ['process_catalog' => true])->assertCreated();
        $item = ContractProduct::firstOrFail();
        $this->assertTrue($item->process_catalog);
        $this->assertSame('11222333000181', $item->supplier_document);
        $this->assertTrue(ContractAudit::firstOrFail()->changes['products']['after']['process_catalog']);
    }

    public function test_process_catalog_shares_one_balance_across_suppliers_and_remembers_mode_for_edit_and_reversal(): void
    {
        DB::table('tb_Produtos')->where('id', $this->productId)->update(['CNPJ_Fornec' => '']);
        $other = $this->contract->replicate();
        $other->fornecedor_documento_snapshot = '52998224725';
        $other->save();
        $this->add('40', null, ['process_catalog' => true])->assertCreated();
        $this->add('61', $other, ['process_catalog' => true])->assertUnprocessable();
        $this->add('60', $other, ['process_catalog' => true])->assertCreated();
        $this->assertSame('0.0000', ProcurementProduct::find($this->productId)->QTD);
        $item = ContractProduct::where('contract_id', $this->contract->id)->firstOrFail();
        $this->putJson($this->url().'/products/'.$item->id, $this->data(['quantity' => '30']))->assertOk()->assertJsonPath('total', '370.3680');
        $this->assertSame('10.0000', ProcurementProduct::find($this->productId)->QTD);
        $this->getJson($this->url().'/products')->assertOk()->assertJsonPath('items.0.process_catalog', true);
        $this->deleteJson($this->url().'/products/'.$item->id, $this->data())->assertOk()->assertJsonPath('total', '0.0000');
        $this->assertSame('40.0000', ProcurementProduct::find($this->productId)->QTD);
        $this->assertDatabaseCount('tb_Produtos', 1);
        $this->assertSame('740.7360', $other->fresh()->valor_total);
    }

    public function test_process_catalog_never_bypasses_contract_access_or_process_and_validates_mode(): void
    {
        $this->getJson($this->url().'/products/item-lookup?item=1&process_catalog=yes')->assertUnprocessable();
        $this->add('1', null, ['process_catalog' => 'yes'])->assertUnprocessable();
        DB::table('tb_Produtos')->where('id', $this->productId)->update(['Processo' => 'OUTRO']);
        $this->getJson($this->url().'/products/item-lookup?item=1&process_catalog=1')->assertOk()->assertJsonPath('status', 'not_found');
        $this->add('1', null, ['process_catalog' => true])->assertNotFound();
        $user = User::create(['name' => 'Sem escopo', 'username' => 'NO-ITEM-SCOPE', 'password' => 'TestPassword12345']);
        $this->actingAs($user)->getJson($this->url().'/products/item-lookup?item=1&process_catalog=1')->assertForbidden();
        $this->add('1', null, ['process_catalog' => true])->assertForbidden();
        $this->assertDatabaseCount('tb_Produtos_Contratos', 0);
    }

    public function test_item_lookup_does_not_choose_ambiguous_sources_or_return_data_when_balance_is_zero(): void
    {
        DB::table('tb_Produtos')->insert(['Processo' => '123/2026', 'CNPJ_Fornec' => '52998224725', 'item' => '1',
            'Discriminacao' => 'Outra origem', 'Unidade' => 'UN', 'QTD' => '10', 'VL_Unitario' => '1']);
        $this->getJson($this->url().'/products/item-lookup?item=1&process_catalog=1')->assertOk()->assertJsonPath('status', 'ambiguous')->assertJsonMissingPath('product');
        $this->getJson($this->url().'/products/item-lookup?item=1')->assertOk()->assertJsonPath('status', 'found');
        DB::table('tb_Produtos')->where('id', $this->productId)->update(['QTD' => '0']);
        $this->getJson($this->url().'/products/item-lookup?item=1')->assertOk()->assertJsonPath('status', 'unavailable')->assertJsonMissingPath('product');
    }
}
