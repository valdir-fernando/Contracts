<?php

namespace Tests\Feature;

use App\Livewire\Overview;
use App\Models\AccessScope;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class OverviewYearTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $fund;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 10, 13)->startOfDay());
        $this->user = User::create(['name' => 'Pessoa', 'username' => 'TESTE', 'password' => 'SyntheticPassword123']);
        $this->fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo A', 'name_key' => 'FUNDO A', 'raw_payload' => '{}']);
        $scope = AccessScope::create(['scope_key' => 'overview-year', 'fund' => 'Fundo A', 'fund_id' => $this->fund]);
        $this->user->scopes()->attach($scope);
        $this->contract(['numero' => 'CURRENT-A', 'valor_total' => '0.1001']);
        $this->contract(['numero' => 'CURRENT-EXPIRED', 'valor_total' => '10.2000', 'vigencia_fim_original' => '2026-10-10']);
        $this->contract(['numero' => 'CURRENT-REVIEW', 'valor_total' => '3.0000', 'vigencia_em_revisao' => true]);
        $this->contract(['numero' => 'PREVIOUS-A', 'exercicio' => 2025, 'valor_total' => '100.0000']);
        $this->contract(['numero' => 'WITHOUT-YEAR', 'exercicio' => null, 'valor_total' => '1000.0000', 'vigencia_inicio' => null]);
        $this->contract(['numero' => 'PRIVATE-2024', 'exercicio' => 2024, 'fund_id' => null, 'valor_total' => '999999.0000']);
        $deleted = $this->contract(['numero' => 'DELETED', 'exercicio' => 2023]);
        $deleted->delete();
        $this->user = $this->user->fresh();
        $this->actingAs($this->user);
        $this->withSession(['fund_id' => $this->fund]);
    }

    private function contract(array $values = []): Contract
    {
        return Contract::create(array_replace(['numero' => 'TEST', 'fund_id' => $this->fund, 'fundo_nome_snapshot' => 'Fundo A',
            'ownership_resolved' => true, 'quality_issues' => [], 'exercicio' => 2026,
            'vigencia_inicio' => '2026-01-01', 'vigencia_fim_original' => '2026-10-20'], $values));
    }

    public function test_initial_dashboard_filters_every_contract_indicator_to_current_exercise(): void
    {
        Livewire::test(Overview::class)->assertSet('year', '2026')->assertViewHas('count', 3)
            ->assertViewHas('expired', 1)->assertViewHas('review', 1)->assertViewHas('alertCount', 1)
            ->assertViewHas('years', fn ($years) => $years->all() === [2026, 2025])
            ->assertViewHas('metrics', fn ($metrics) => $metrics['summary']['total'] === '13.3001'
                && $metrics['summary']['count'] === 3 && array_sum(array_column($metrics['validities'], 'count')) === 3
                && array_sum(array_column($metrics['months'], 'count')) === 2 && $metrics['funds'][0]['count'] === 3)
            ->assertViewHas('recent', fn ($rows) => $rows->total() === 3 && $rows->every(fn ($row) => $row->exercicio === 2026))
            ->assertSee('Todos os anos')->assertDontSee('PREVIOUS-A')->assertDontSee('WITHOUT-YEAR')->assertDontSee('PRIVATE-2024');
        $this->get('/dashboard')->assertOk()->assertSee('CURRENT-A')->assertDontSee('PREVIOUS-A');
    }

    public function test_previous_year_changes_metrics_alerts_rows_and_card_destinations(): void
    {
        $component = Livewire::test(Overview::class)->set('year', '2025')->assertViewHas('count', 1)
            ->assertViewHas('expired', 0)->assertViewHas('review', 0)->assertViewHas('alertCount', 1)
            ->assertViewHas('metrics', fn ($metrics) => $metrics['summary']['total'] === '100.0000'
                && $metrics['summary']['count'] === 1 && array_sum(array_column($metrics['months'], 'count')) === 1)
            ->assertViewHas('recent', fn ($rows) => $rows->total() === 1 && $rows->first()->numero === 'PREVIOUS-A')
            ->assertSee(route('contracts.index', ['year' => 2025, 'fund' => $this->fund, 'validity' => 'vigente']))
            ->assertSee(route('contracts.index', ['year' => 2025, 'fund' => $this->fund, 'validity' => 'vencido']))
            ->assertSee(route('contracts.index', ['year' => 2025, 'fund' => $this->fund, 'review' => 1]));
        $this->get('/contracts?year=2025')->assertOk()->assertViewHas('contracts', fn ($rows) => $rows->total() === 1);
        $component->set('search', 'CURRENT')->assertViewHas('recent', fn ($rows) => $rows->total() === 0)
            ->assertViewHas('count', 1);
    }

    public function test_all_years_includes_unknown_exercise_and_keeps_current_scope(): void
    {
        $component = Livewire::test(Overview::class)->set('year', 'all')->assertViewHas('count', 5)
            ->assertViewHas('metrics', fn ($metrics) => $metrics['summary']['total'] === '1113.3001' && $metrics['summary']['count'] === 5)
            ->assertViewHas('alertCount', 2)->assertViewHas('recent', fn ($rows) => $rows->total() === 5)
            ->assertSee('PREVIOUS-A')->assertSee('WITHOUT-YEAR')->assertDontSee('PRIVATE-2024')->assertDontSee('DELETED')
            ->assertViewHas('yearFilters', ['fund' => $this->fund]);
        $this->user->scopes()->detach();
        $component->call('$refresh')->assertViewHas('count', 0)->assertViewHas('alertCount', 0)
            ->assertViewHas('years', fn ($years) => $years->all() === [2026])->assertDontSee('PREVIOUS-A');
    }

    public function test_portfolio_value_prefers_accumulated_and_falls_back_per_contract_without_replacing_zero(): void
    {
        $this->contract(['valor_acumulado' => '100.1234', 'valor_total' => '999.0000']);
        $this->contract(['valor_acumulado' => null, 'valor_total' => '7.0001']);
        $this->contract(['valor_acumulado' => '0.0000', 'valor_total' => '8000.0000']);
        $this->contract(['valor_acumulado' => null, 'valor_total' => null]);
        $this->contract(['exercicio' => 2025, 'valor_acumulado' => '777.0000']);
        $this->contract(['fund_id' => null, 'valor_acumulado' => '999999.0000']);
        $deleted = $this->contract(['valor_acumulado' => '444.0000']);
        $deleted->delete();
        Livewire::test(Overview::class)
            ->assertViewHas('metrics', fn ($metrics) => $metrics['portfolioValue'] === ['total' => '120.4236', 'missing' => 1]
                && $metrics['summary']['total'] === '9019.3002')
            ->assertSee('Valor de todos os contratos do Fundo realizados no exercício 2026.')->assertSee('R$ 120,42')
            ->set('year', '2025')
            ->assertViewHas('metrics', fn ($metrics) => $metrics['portfolioValue'] === ['total' => '877.0000', 'missing' => 0])
            ->set('year', 'all')
            ->assertSee('Valor de todos os contratos do Fundo realizados em todos os exercícios.')
            ->assertViewHas('metrics', fn ($metrics) => $metrics['portfolioValue'] === ['total' => '1997.4236', 'missing' => 1]);
    }

    public function test_portfolio_value_keeps_selected_fund_filter_for_administrators(): void
    {
        $fundB = DB::table('contract_funds')->insertGetId(['name' => 'Fundo B', 'name_key' => 'FUNDO B', 'raw_payload' => '{}']);
        $this->contract(['fund_id' => $fundB, 'valor_acumulado' => '999999.0000']);
        $admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'SyntheticPassword123', 'user_type' => 'Administrador']);
        Livewire::actingAs($admin)->test(Overview::class)
            ->assertViewHas('metrics', fn ($metrics) => $metrics['portfolioValue'] === ['total' => '13.3001', 'missing' => 0]);
        $this->withSession(['fund_id' => null]);
        Livewire::test(Overview::class)
            ->assertViewHas('metrics', fn ($metrics) => $metrics['portfolioValue'] === ['total' => '0.0000', 'missing' => 0]);
    }

    public function test_selected_year_is_restored_from_url(): void
    {
        Livewire::withQueryParams(['year' => '2025'])->test(Overview::class)->assertSet('year', '2025')->assertViewHas('count', 1);
        Livewire::withQueryParams(['year' => 'all'])->test(Overview::class)->assertSet('year', 'all')->assertViewHas('count', 5);
    }

    public function test_year_selection_resets_pagination_and_rejects_invalid_values(): void
    {
        for ($index = 0; $index < 12; $index++) {
            $this->contract(['numero' => 'PAGE-'.$index]);
        }
        $component = Livewire::test(Overview::class)->call('setPage', 2)->assertViewHas('recent', fn ($rows) => $rows->currentPage() === 2);
        $component->set('year', '2025')->assertViewHas('recent', fn ($rows) => $rows->currentPage() === 1 && $rows->total() === 1);
        foreach (['invalid', '0', '2027', '2025 OR 1=1'] as $year) {
            Livewire::test(Overview::class)->set('year', $year)->assertHasErrors('year');
        }
    }

    public function test_year_defaults_follow_the_clock_and_current_year_remains_available_when_empty(): void
    {
        $this->travelTo(now()->setDate(2027, 1, 1));
        Livewire::test(Overview::class)->assertSet('year', '2027')->assertViewHas('count', 0)
            ->assertViewHas('years', fn ($years) => $years->all() === [2027, 2026, 2025]);
    }
}
