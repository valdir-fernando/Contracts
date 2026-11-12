<?php

namespace Tests\Feature;

use App\Livewire\AtasOverview;
use App\Models\AccessScope;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class AtasOverviewTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private int $fund;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 10, 26));
        $this->actor = User::create(['name' => 'Usuário', 'username' => 'ATAS', 'password' => 'SyntheticPassword123'])->fresh();
        $this->fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo autorizado', 'name_key' => 'FUNDO AUTORIZADO', 'raw_payload' => '{}']);
        $scope = AccessScope::create(['scope_key' => 'atas-fund', 'fund_id' => $this->fund, 'fund' => 'Fundo autorizado']);
        $this->actor->scopes()->attach($scope);
    }

    public function test_atas_dashboard_requires_authenticated_active_user_and_current_fund_access(): void
    {
        $this->get('/atas')->assertRedirect('/login');
        $this->actingAs($this->actor)->withSession(['fund_id' => $this->fund])->get('/atas')->assertOk();
        $this->actor->scopes()->detach();
        $this->get('/atas')->assertRedirect('/login');
        $this->actor->forceFill(['is_active' => false])->save();
        $this->actingAs($this->actor->fresh())->get('/atas')->assertRedirect('/login');
    }

    public function test_new_ata_link_opens_panel_without_creating_records(): void
    {
        $this->get('/atas/create')->assertRedirect('/login');
        $this->actingAs($this->actor)->withSession(['fund_id' => $this->fund])->get('/atas')
            ->assertOk()->assertSee('href="'.route('atas.create').'"', false);
        $this->get(route('atas.create'))->assertOk()->assertSee('Nova ATA')
            ->assertSee('FUNDO GERENCIADOR')->assertSee('Nome / Razão Social')->assertSee('Nº ATA')
            ->assertSee('class="contract-number-search" aria-label="Buscar atas"', false)
            ->assertSee('workspace-top-contract', false)->assertSee('href="'.route('atas.dashboard').'"', false)
            ->assertSee('Fundo Gerenciador')->assertSee('id="ata-managing-fund"', false)
            ->assertSeeInOrder(['1. Dados Processuais', '2. Dados Gerenciador', '3. Dados do Fornecedor'])
            ->assertSee('aria-controls="ata-manager-panel"', false)
            ->assertSee('aria-controls="ata-process-panel"', false)->assertSee('aria-controls="ata-supplier-panel"', false)
            ->assertSeeInOrder(['Processo ATA', 'Licitação', 'Nº Licit', 'Fundos Participantes'])
            ->assertSee('id="ata-participant-fund"', false)
            ->assertDontSee('data-contract-form', false);
        $this->assertDatabaseCount('tb_ATA', 0);
        $this->assertDatabaseCount('activity_events', 0);

        $this->actor->scopes()->detach();
        $this->get(route('atas.create'))->assertRedirect('/login');
    }

    public function test_new_ata_products_is_disabled_until_an_ata_is_saved_or_loaded(): void
    {
        $response = $this->actingAs($this->actor)->withSession(['fund_id' => $this->fund])
            ->get(route('atas.create'))->assertOk();

        $response->assertSee('4. Produtos')
            ->assertSee('class="section-tab products-button" disabled title="Salve ou carregue uma ata para acessar os Produtos."', false)
            ->assertSee('aria-haspopup="dialog" aria-controls="ata-products-dialog"', false)
            ->assertSee('$refs.ataProducts.showModal()', false)
            ->assertSee('class="catalog-modal contract-products-dialog"', false)
            ->assertSee('aria-labelledby="ata-products-title"', false)
            ->assertSee('Cadastro de produtos')->assertSee('Fechar cadastro de produtos')
            ->assertSee('$refs.ataProducts.close()', false)
            ->assertSee('<fieldset disabled', false)
            ->assertSee('O lançamento de produtos ficará disponível após a implementação da gravação da ata.')
            ->assertSeeInOrder(['Exibir produtos do processo', 'Lançamento manual', 'Ficha', 'Descrição', 'Dotação',
                'Disponível', 'Salvar produto', 'Novo Produto', 'Excluir / estornar', 'Nova Ficha', 'Valor Total da ATA'])
            ->assertDontSee('data-contract-products ', false)
            ->assertDontSee('data-base=', false)
            ->assertDontSee('Valor Total do Contrato');
        $this->assertDatabaseCount('tb_ATA', 0);
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 0);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_new_ata_lists_all_active_funds_alphabetically_without_private_details(): void
    {
        $other = DB::table('contract_funds')->insertGetId(['name' => 'A Fundo <Global>', 'name_key' => 'GLOBAL',
            'document' => 'DOCUMENTO-PRIVADO', 'raw_payload' => '{"Nome":"CONTATO-PRIVADO"}']);
        DB::table('contract_funds')->insert(['name' => 'Fundo removido', 'name_key' => 'REMOVIDO',
            'raw_payload' => '{}', 'deleted_at' => now()]);

        $this->actingAs($this->actor)->withSession(['fund_id' => $this->fund])->get(route('atas.create'))
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertSeeInOrder(['Selecione o Fundo Gerenciador',
                '<option value="'.$other.'">A Fundo &lt;Global&gt;</option>',
                '<option value="'.$this->fund.'">Fundo autorizado</option>'], false)
            ->assertDontSee('Fundo removido')->assertDontSee('DOCUMENTO-PRIVADO')->assertDontSee('CONTATO-PRIVADO');
        $this->assertDatabaseCount('tb_ATA', 0);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_header_links_show_global_atas_title_and_preserve_contract_fund(): void
    {
        $response = $this->actingAs($this->actor)->withSession(['fund_id' => $this->fund])->get('/atas')->assertOk();
        $response->assertSeeInOrder(['class="header-modules"', 'href="'.route('dashboard').'"', 'CONTRATOS',
            'href="'.route('atas.dashboard').'"', 'ATAS', 'class="selected-fund-name">Atas de Registro de Preços'], false);
        $response->assertDontSee('class="selected-fund-name">Fundo autorizado', false);
        $this->assertMatchesRegularExpression('/class="header-module-button" href="'.preg_quote(route('atas.dashboard'), '/').'"\s+aria-current="page"/', $response->getContent());
        $dashboard = $this->get('/dashboard')->assertOk();
        $dashboard->assertSee('class="selected-fund-name">Fundo autorizado', false);
        $this->assertMatchesRegularExpression('/class="header-module-button" href="'.preg_quote(route('dashboard'), '/').'"\s+aria-current="page"/', $dashboard->getContent());
    }

    public function test_module_buttons_and_separator_are_absent_outside_dashboards(): void
    {
        $this->actingAs($this->actor)->withSession(['fund_id' => $this->fund]);
        foreach (['/reports?report_year=all', '/calendar', '/reports', '/account'] as $url) {
            $this->get($url)->assertOk()->assertDontSee('class="header-modules"', false)
                ->assertDontSee('class="header-module-button"', false)
                ->assertSee('class="selected-fund-name">Fundo autorizado', false);
        }
    }

    public function test_empty_atas_dashboard_does_not_use_contract_records(): void
    {
        Contract::create(['numero' => 'CONTRATO-PRIVADO', 'fund_id' => $this->fund, 'exercicio' => 2026, 'ownership_resolved' => true,
            'vigencia_inicio' => '2026-01-01', 'vigencia_fim_atual' => '2026-11-01', 'valor_acumulado' => '9876.5400', 'quality_issues' => []]);
        $this->actingAs($this->actor)->withSession(['fund_id' => $this->fund])->get('/atas')->assertOk()
            ->assertSee('PAINEL DE ACOMPANHAMENTO DE ATAS')->assertSee('Atas cadastradas')->assertSee('Vencidas')
            ->assertSee('Distribuição das atas por vigência')->assertSee('Nenhuma ata cadastrada')->assertSee('R$ 0,00')
            ->assertSee('class="donut-chart"', false)->assertSee('class="month-chart"', false)
            ->assertDontSee('CONTRATO-PRIVADO')->assertDontSee('9.876,54')->assertDontSee('em breve')
            ->assertSee('Visão global das atas')->assertSee('Indicadores globais de atas')
            ->assertDontSee('Pesquisar atas')->assertDontSee('Consultar atas')->assertDontSee('id="atas-list"', false);
        $this->assertDatabaseCount('contracts', 1);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_atas_year_filter_is_validated_and_actor_is_revalidated(): void
    {
        $this->actingAs($this->actor)->withSession(['fund_id' => $this->fund]);
        Livewire::test(AtasOverview::class)->assertSet('year', '2026')->set('year', 'all')->assertHasNoErrors()->assertSee('em todos os exercícios');
        Livewire::test(AtasOverview::class)->set('year', '2027')->assertHasErrors('year');
        $component = Livewire::test(AtasOverview::class);
        User::whereKey($this->actor->id)->increment('session_version');
        $component->call('$refresh')->assertForbidden();
    }
}
