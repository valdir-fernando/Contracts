<?php

namespace Tests\Feature;

use App\Models\AccessScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DfdPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private int $fund;

    private int $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->actor = User::create(['name' => 'Pessoa exemplo', 'username' => 'DFD-PANEL', 'password' => 'SyntheticPassword123', 'user_type' => 'Administrador']);
        $this->fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo A', 'name_key' => 'FUNDO A', 'raw_payload' => '{}']);
        $this->product = DB::table('tb_Produtos_Prodata')->insertGetId(['ID_Prodata' => 10, 'Classe' => 'Material', 'Descricao' => 'Papel A4', 'UND' => 'UN', 'Inativo' => 0], 'ID');
        $this->actingAs($this->actor)->withSession(['fund_id' => $this->fund]);
    }

    private function input(): array
    {
        return ['_dfd_id' => '', 'Categoria' => 'Material', 'Data_DFD' => '2026-10-29', 'Processo_DFD' => '100/2026',
            'Necessidade' => 'Reposição de material', 'Desricao' => 'Aquisição de papel', 'Data_Conclusao' => '2026-12-10',
            'Prioridade' => 'Alta', 'Vigencia' => '12 meses', 'Local' => 'Almoxarifado', 'End_Local' => 'Rua exemplo, 10',
            'Vinculacao' => 'Sem dependência', 'Observacoes' => 'Entrega em horário comercial', 'Responsavel' => 'Pessoa responsável',
            'Decreto' => '123/2026', 'Cargo' => 'Diretor', 'email' => 'exemplo@example.test'];
    }

    private function createDfd(): object
    {
        $this->post('/dfd-compra', $this->input())->assertRedirect();

        return DB::table('tb_DFD')->sole();
    }

    private function preview(string $name, string $html): void
    {
        if ($directory = getenv('DFD_PREVIEW_DIR')) {
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            file_put_contents($directory.'/'.$name.'.html', $html);
        }
    }

    public function test_general_panel_contains_reference_fields_and_blocks_products_before_save(): void
    {
        $response = $this->get('/dfd-compra?classe=Material')->assertOk();
        foreach (array_keys($this->input()) as $field) {
            $response->assertSee('name="'.$field.'"', false);
        }
        $response->assertSee('Dotação orçamentária')->assertSee('data-dfd-budget-select', false)
            ->assertSee('data-dfd-search-open', false)->assertSee('Salve a DFD para acessar os produtos')
            ->assertSee('id="dfd-quotation"', false)->assertSee('maxlength="5"', false);
        $this->get('/dfd-compra?classe=Material&tab=products')->assertForbidden();
        $this->assertDatabaseCount('tb_DFD', 0);
        $this->preview('new', $response->getContent());
    }

    public function test_tabs_group_fields_in_one_form_without_losing_inputs(): void
    {
        $html = $this->get('/dfd-compra?classe=Material')->assertOk()->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($dom);
        foreach ([
            'description' => ['Necessidade', 'Desricao', 'Observacoes'],
            'information' => ['Data_Conclusao', 'Prioridade', 'Vigencia', 'Local', 'End_Local', 'Vinculacao'],
            'requester' => ['Responsavel', 'Decreto', 'Cargo', 'email'],
            'budget' => ['dfd-budget', 'dfd-budget-Dotacao'],
        ] as $tab => $fields) {
            foreach ($fields as $field) {
                $this->assertSame(1, $xpath->query('//form[@id="dfd-general-form"]//*[@id="dfd-panel-'.$tab.'"]//*[@id="'.$field.'"]')->length);
            }
            $this->assertSame(1, $xpath->query('//nav//button[@aria-controls="dfd-panel-'.$tab.'"]')->length);
        }
        $this->assertSame(1, $xpath->query('//*[@id="Observacoes"]')->length);
    }

    public function test_general_fields_save_reopen_edit_and_delete_with_audit(): void
    {
        $record = $this->createDfd();
        $expected = $this->input();
        unset($expected['_dfd_id']);
        $this->assertDatabaseHas('tb_DFD', $expected + ['fund_id' => $this->fund, 'Fundo' => 'Fundo A', 'Requisitante' => 'Fundo A']);
        $response = $this->get('/dfd-compra/'.$record->ID)->assertOk()->assertSee('Reposição de material')->assertSee('Pessoa responsável');
        $this->preview('saved', $response->getContent());
        $this->put('/dfd-compra/'.$record->ID, array_replace($this->input(), ['_dfd_id' => $record->ID, 'version' => 1, 'Observacoes' => 'Entrega revisada']))->assertRedirect();
        $this->assertDatabaseHas('tb_DFD', ['ID' => $record->ID, 'Observacoes' => 'Entrega revisada', 'version' => 2]);
        $this->delete('/dfd-compra/'.$record->ID, ['version' => 1, 'confirmation' => 1])->assertConflict();
        $this->delete('/dfd-compra/'.$record->ID, ['version' => 2, 'confirmation' => 1])->assertRedirect();
        $this->assertNotNull(DB::table('tb_DFD')->value('deleted_at'));
        foreach (['dfd_created', 'dfd_updated', 'dfd_deleted'] as $action) {
            $this->assertDatabaseHas('activity_events', ['actor_id' => $this->actor->id, 'action' => $action]);
        }
        $this->get('/dfd-compra/'.$record->ID)->assertNotFound();
    }

    public function test_validation_restores_category_and_fields_only_in_the_same_form(): void
    {
        $this->from('/dfd-compra')->post('/dfd-compra', array_replace($this->input(), ['Desricao' => '']))->assertSessionHasErrors('Desricao');
        $this->get('/dfd-compra')->assertOk()->assertSee('Reposição de material')->assertSee('value="Material" readonly data-dfd-class-panel', false);
        DB::table('tb_Produtos_Prodata')->insert(['ID_Prodata' => 20, 'Classe' => 'Outra classe', 'Descricao' => 'Produto B', 'UND' => 'UN', 'Inativo' => 0]);
        $this->withSession(['_old_input' => $this->input()])->get('/dfd-compra?classe=Outra%20classe')->assertOk()->assertDontSee('Reposição de material');
        $this->withSession(['_old_input' => ['Categoria' => ['invalid'], '_dfd_id' => '']])->get('/dfd-compra')->assertOk();
        $this->assertDatabaseCount('tb_DFD', 0);
    }

    public function test_lookup_destination_loads_all_saved_fields_and_the_record_category(): void
    {
        $record = $this->createDfd();
        $search = $this->getJson('/dfd-compra/search?q='.urlencode($record->N_DFD))->assertOk()->assertJsonCount(1, 'records');
        $url = $search->json('records.0.url');
        $response = $this->get($url.'?classe=Outra')->assertOk();
        $response->assertSee('value="Material" readonly data-dfd-class-panel', false);
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($document);
        foreach ($this->input() as $name => $expected) {
            $field = $xpath->query('//*[@name="'.$name.'"]')->item(0);
            $this->assertNotNull($field, $name);
            $actual = match ($field->nodeName) {
                'textarea' => $field->textContent,
                'select' => $xpath->query('option[@selected]', $field)->item(0)?->textContent,
                default => $field->getAttribute('value'),
            };
            $this->assertSame($name === '_dfd_id' ? (string) $record->ID : $expected, $actual, $name);
        }
    }

    public function test_products_tab_selects_catalog_items_and_persists_quantity_and_characteristics(): void
    {
        $record = $this->createDfd();
        $this->get('/dfd-compra/'.$record->ID.'?tab=products')->assertOk()->assertSee('dfd-products-dialog', false)->assertDontSee('dfd-catalog-query', false);
        $response = $this->get('/dfd-compra/'.$record->ID.'?tab=products&catalog_product='.$this->product)->assertOk()->assertSee('Produtos da DFD')->assertSee('name="quantity"', false);
        $this->preview('products', $response->getContent());
        $this->post('/dfd-compra/'.$record->ID.'/products', ['version' => 1, 'product_id' => $this->product, 'quantity' => '2.5000', 'characteristics' => 'Pacote com 500 folhas'])->assertRedirect();
        $line = DB::table('tb_Produtos_DFD')->sole();
        $this->get('/dfd-compra/'.$record->ID.'?tab=products&item='.$line->ID)->assertOk()->assertSee('Pacote com 500 folhas')->assertSee('name="quantity"', false);
        $this->put('/dfd-compra/'.$record->ID.'/products/'.$line->ID, ['version' => 2, 'product_id' => $this->product, 'quantity' => '3.0000'])->assertRedirect();
        $this->assertDatabaseHas('tb_Produtos_DFD', ['ID' => $line->ID, 'QTD' => '3.0000']);
        $this->delete('/dfd-compra/'.$record->ID.'/products/'.$line->ID, ['version' => 3, 'confirmation' => 1])->assertRedirect();
        $this->assertNotNull(DB::table('tb_Produtos_DFD')->value('deleted_at'));
    }

    public function test_launch_saves_localized_quantity_and_complement_and_rejects_stale_version(): void
    {
        $record = $this->createDfd();
        $payload = ['version' => 1, 'product_id' => $this->product, 'quantity' => '1.654,000', 'characteristics' => 'Pacote especial'];
        $this->postJson('/dfd-compra/'.$record->ID.'/products', $payload)->assertRedirect();
        $this->assertDatabaseHas('tb_Produtos_DFD', ['ID_DFD' => $record->ID, 'ID_PROD' => $this->product,
            'QTD' => '1654.0000', 'CARACT' => 'Pacote especial', 'PRODUTO' => "Papel A4\nPacote especial"]);
        $this->assertDatabaseHas('activity_events', ['actor_id' => $this->actor->id, 'action' => 'dfd_product_created']);
        $this->postJson('/dfd-compra/'.$record->ID.'/products', $payload)->assertConflict();
        $this->assertDatabaseCount('tb_Produtos_DFD', 1);
        $this->get('/dfd-compra/'.$record->ID.'?tab=products')->assertOk()->assertSee('Pacote especial');
        $this->postJson('/dfd-compra/'.$record->ID.'/products', array_replace($payload, ['version' => 2, 'quantity' => '0,000']))->assertUnprocessable();
        $this->assertDatabaseCount('tb_Produtos_DFD', 1);
    }

    public function test_legacy_underscore_url_renders_linked_products(): void
    {
        $record = $this->createDfd();
        DB::table('tb_Produtos_DFD')->insert(['ID_DFD' => $record->ID, 'N_DFD' => $record->N_DFD, 'ITEM' => 1,
            'ID_PROD' => $this->product, 'COD_PROD' => '10', 'PROD_SIMPLES' => 'Papel A4', 'UND' => 'UN', 'QTD' => '2.0000']);

        $this->get('/dfd_compra/'.$record->ID.'?tab=products')->assertOk()
            ->assertSee('Produtos da DFD')->assertDontSee('Produtos do catálogo')->assertDontSee('Itens incluídos')
            ->assertSee('Itens vinculados à DFD')->assertSee('2,0000')->assertSee('Papel A4');
    }

    public function test_catalog_contains_the_whole_class_for_filtering_and_excludes_other_classes_and_inactive_products(): void
    {
        $record = $this->createDfd();
        DB::table('tb_Produtos_Prodata')->insert([
            ['ID_Prodata' => 11, 'Classe' => 'Material', 'Descricao' => 'Papel A4 reciclado', 'UND' => 'UN', 'Inativo' => 0],
            ['ID_Prodata' => 12, 'Classe' => 'Material', 'Descricao' => 'Caneta azul', 'UND' => 'UN', 'Inativo' => 0],
            ['ID_Prodata' => 14, 'Classe' => 'Material', 'Descricao' => 'Papel A4 inativo', 'UND' => 'UN', 'Inativo' => 1],
            ['ID_Prodata' => 13, 'Classe' => 'Outra classe', 'Descricao' => 'Papel A4 de outra classe', 'UND' => 'UN', 'Inativo' => 0],
        ]);

        $this->get('/dfd-compra/'.$record->ID.'?'.http_build_query(['tab' => 'products', 'q' => 'Papel A4']))
            ->assertOk()->assertSee('dfd-products-dialog', false)->assertSee('Papel A4 reciclado')->assertSee('Caneta azul')->assertDontSee('Papel A4 de outra classe')->assertDontSee('Papel A4 inativo');
    }

    public function test_other_funds_records_products_and_budgets_are_inaccessible(): void
    {
        $record = $this->createDfd();
        $other = DB::table('contract_funds')->insertGetId(['name' => 'Fundo B', 'name_key' => 'FUNDO B', 'raw_payload' => '{}']);
        $budget = DB::table('tb_Dotacao')->insertGetId(['Ficha' => 'FICHA-OUTRO-FUNDO', 'Fundo' => 'Fundo B', 'Descricao' => 'Dotação restrita'], 'ID_Dotacao');
        $this->get('/dfd-compra?classe=Material')->assertOk()->assertDontSee('FICHA-OUTRO-FUNDO');
        $this->post('/dfd-compra', $this->input() + ['budget_id' => $budget])->assertSessionHasErrors('budget_id');
        $this->withSession(['fund_id' => $other])->get('/dfd-compra/'.$record->ID)->assertNotFound();
        $this->get('/dfd-compra/search')->assertOk()->assertExactJson(['records' => []]);
        $this->put('/dfd-compra/'.$record->ID, $this->input() + ['version' => 1])->assertNotFound();
        $this->post('/dfd-compra/'.$record->ID.'/products', ['version' => 1, 'product_id' => $this->product, 'quantity' => '1'])->assertNotFound();
    }

    public function test_budget_fields_are_derived_from_authorized_catalog_and_omission_preserves_history(): void
    {
        $budget = DB::table('tb_Dotacao')->insertGetId(['Ficha' => '100', 'Fundo' => 'Fundo A', 'Descricao' => 'Material de consumo', 'Orgao' => '02', 'Gestao' => '01', 'Dotacao' => '01.02.03'], 'ID_Dotacao');
        $this->post('/dfd-compra', $this->input() + ['budget_id' => $budget, 'Ficha' => 'FORGED'])->assertRedirect();
        $record = DB::table('tb_DFD')->sole();
        $this->assertSame('100', $record->Ficha);
        $response = $this->get('/dfd-compra/'.$record->ID)->assertOk()->assertSee('Material de consumo')->assertSee('data-dfd-budget-field="Orgao"', false);
        $this->preview('budget', $response->getContent());
        $this->put('/dfd-compra/'.$record->ID, $this->input() + ['version' => 1])->assertRedirect();
        $this->assertDatabaseHas('tb_DFD', ['ID' => $record->ID, 'Ficha' => '100']);
        $this->put('/dfd-compra/'.$record->ID, $this->input() + ['version' => 2, 'budget_id' => null])->assertRedirect();
        $this->assertDatabaseHas('tb_DFD', ['ID' => $record->ID, 'Ficha' => '']);
    }

    public function test_department_scope_applies_to_lookup_and_opening_documents(): void
    {
        DB::table('contract_funds')->where('id', $this->fund)->update(['name' => 'Município de Niquelândia', 'name_key' => 'MUNICIPIO DE NIQUELANDIA']);
        $department = DB::table('contract_departments')->insertGetId(['name' => 'Saúde', 'name_key' => 'SAUDE', 'raw_payload' => '{}']);
        $other = DB::table('contract_departments')->insertGetId(['name' => 'Educação', 'name_key' => 'EDUCACAO', 'raw_payload' => '{}']);
        foreach ([$department, $other] as $index => $id) {
            $this->post('/dfd-compra', $this->input() + ['department_id' => $id])->assertRedirect();
        }
        $records = DB::table('tb_DFD')->orderBy('ID')->get();
        $user = User::create(['name' => 'Pessoa', 'username' => 'DFD-SCOPED', 'password' => 'SyntheticPassword123', 'user_type' => 'Usuario']);
        $scope = AccessScope::create(['scope_key' => 'dfd-panel-health', 'fund_id' => $this->fund, 'fund' => 'Município de Niquelândia', 'department_id' => $department, 'department' => 'Saúde']);
        $user->scopes()->attach($scope);
        $this->actingAs($user)->get('/dfd-compra/search')->assertOk()->assertJsonCount(1, 'records')->assertJsonPath('records.0.number', $records[0]->N_DFD);
        $this->get('/dfd-compra/'.$records[1]->ID)->assertNotFound();
        $this->post('/dfd-compra', $this->input() + ['department_id' => $other])->assertForbidden();
        $this->get('/dfd-compra?classe=Material')->assertOk()->assertSee('Saúde')->assertDontSee('Educação');
    }
}
