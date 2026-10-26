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

    public function test_header_links_open_the_two_dashboards_before_selected_fund(): void
    {
        $response = $this->actingAs($this->actor)->withSession(['fund_id' => $this->fund])->get('/atas')->assertOk();
        $response->assertSeeInOrder(['class="header-modules"', 'href="'.route('dashboard').'"', 'CONTRATOS',
            'href="'.route('atas.dashboard').'"', 'ATAS', 'class="selected-fund-name">Fundo autorizado'], false);
        $this->assertMatchesRegularExpression('/class="header-module-button" href="'.preg_quote(route('atas.dashboard'), '/').'"\s+aria-current="page"/', $response->getContent());
        $dashboard = $this->get('/dashboard')->assertOk();
        $this->assertMatchesRegularExpression('/class="header-module-button" href="'.preg_quote(route('dashboard'), '/').'"\s+aria-current="page"/', $dashboard->getContent());
    }

    public function test_module_buttons_and_separator_are_absent_outside_dashboards(): void
    {
        $this->actingAs($this->actor)->withSession(['fund_id' => $this->fund]);
        foreach (['/contracts?year=', '/calendar', '/reports', '/account'] as $url) {
            $this->get($url)->assertOk()->assertDontSee('class="header-modules"', false)
                ->assertDontSee('class="header-module-button"', false)
                ->assertSee('class="selected-fund-name">Fundo autorizado', false);
        }
    }

    public function test_future_atas_dashboard_shows_empty_charts_without_using_contract_records(): void
    {
        Contract::create(['numero' => 'CONTRATO-PRIVADO', 'fund_id' => $this->fund, 'exercicio' => 2026, 'ownership_resolved' => true,
            'vigencia_inicio' => '2026-01-01', 'vigencia_fim_atual' => '2026-11-01', 'valor_acumulado' => '9876.5400', 'quality_issues' => []]);
        $this->actingAs($this->actor)->withSession(['fund_id' => $this->fund])->get('/atas')->assertOk()
            ->assertSee('PAINEL DE ACOMPANHAMENTO DE ATAS')->assertSee('Atas cadastradas')->assertSee('Vencidas')
            ->assertSee('Distribuição das atas por vigência')->assertSee('Nenhuma ata cadastrada')->assertSee('R$ 0,00')
            ->assertSee('class="donut-chart"', false)->assertSee('class="month-chart"', false)
            ->assertDontSee('CONTRATO-PRIVADO')->assertDontSee('9.876,54')->assertSee('em breve');
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
