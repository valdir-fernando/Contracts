<?php

namespace Tests\Feature;

use App\Livewire\Overview;
use App\Models\AccessScope;
use App\Models\ActivityEvent;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

class FundSwitchingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $fundA;

    private int $fundB;

    private int $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 10, 13)->startOfDay());
        $this->admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'SyntheticPassword123', 'user_type' => 'Administrador'])->fresh();
        $this->fundA = $this->fund('Fundo A');
        $this->fundB = $this->fund('Fundo B');
        $this->department = DB::table('contract_departments')->insertGetId(['name' => 'Secretaria B', 'name_key' => 'SECRETARIA B', 'raw_payload' => '{}']);
        $this->contract($this->fundA, 'A-CURRENT', 2026, '12.3456');
        $review = $this->contract($this->fundA, 'A-REVIEW', 2026, '0.1001');
        $review->update(['vigencia_em_revisao' => true]);
        $this->contract($this->fundA, 'A-PREVIOUS', 2025, '200.0000');
        $this->contract($this->fundB, 'B-CURRENT', 2026, '1000.0000', $this->department);
        $expired = $this->contract($this->fundB, 'B-EXPIRED', 2026, '2000.0000');
        $expired->update(['vigencia_fim_atual' => '2026-10-10']);
        $this->contract($this->fundB, 'B-PREVIOUS', 2024, '3000.0000');
        $this->actingAs($this->admin)->withSession(['fund_id' => $this->fundA]);
    }

    private function fund(string $name): int
    {
        return DB::table('contract_funds')->insertGetId(['name' => $name, 'name_key' => strtoupper($name), 'raw_payload' => '{}']);
    }

    private function contract(int $fund, string $number, int $year, string $amount, ?int $department = null): Contract
    {
        return Contract::create(['fund_id' => $fund, 'department_id' => $department, 'numero' => $number, 'exercicio' => $year,
            'valor_total' => '1.0000', 'valor_acumulado' => $amount, 'ownership_resolved' => true, 'quality_issues' => [], 'fundo_nome_snapshot' => $fund === $this->fundA ? 'Fundo A' : 'Fundo B',
            'vigencia_inicio' => '2026-01-01', 'vigencia_fim_atual' => '2026-10-20']);
    }

    private function grant(User $user, int $fund, ?int $department = null): void
    {
        $scope = AccessScope::create(['scope_key' => $user->id.'-'.$fund.'-'.($department ?? 'all'), 'fund_id' => $fund,
            'fund' => $fund === $this->fundA ? 'Fundo A' : 'Fundo B', 'department_id' => $department,
            'department' => $department ? 'Secretaria B' : null]);
        $user->scopes()->attach($scope);
    }

    public function test_admin_indicators_and_links_use_selected_fund_and_exercise(): void
    {
        Livewire::test(Overview::class)->assertViewHas('count', 2)->assertViewHas('expired', 0)->assertViewHas('review', 0)
            ->assertViewHas('alertCount', 2)->assertViewHas('years', fn ($years) => $years->all() === [2026, 2025])
            ->assertViewHas('metrics', fn ($metrics) => $metrics['summary']['total'] === '12.4457' && $metrics['summary']['count'] === 2
                && array_sum(array_column($metrics['validities'], 'count')) === 2 && array_sum(array_column($metrics['months'], 'count')) === 2)
            ->assertViewHas('recent', fn ($rows) => $rows->total() === 2 && $rows->every(fn ($row) => $row->fund_id === $this->fundA))
            ->assertSee('data-kpi="current"', false)
            ->assertDontSee('B-CURRENT')->assertDontSee('B-EXPIRED');
        $this->get('/reports?year=2026&fund='.$this->fundA.'&review=1')->assertOk()
            ->assertViewHas('contracts', fn ($rows) => $rows->total() === 2 && $rows->contains('numero', 'A-REVIEW'));
    }

    public function test_switch_preserves_authentication_and_year_and_updates_header_metrics_and_new_contract_defaults(): void
    {
        $this->post('/session/fund', ['fund_id' => $this->fundB, 'year' => 'all'])->assertRedirect('/dashboard?year=all')
            ->assertSessionHas('fund_id', $this->fundB);
        $this->assertAuthenticatedAs($this->admin);
        $this->assertDatabaseHas('activity_events', ['actor_id' => $this->admin->id, 'action' => 'fund_selected']);
        $this->get('/dashboard?year=all')->assertOk()->assertSee('<strong class="selected-fund-name">Fundo B</strong>', false)
            ->assertSee('B-CURRENT')->assertDontSee('A-CURRENT');
        Livewire::withQueryParams(['year' => 'all'])->test(Overview::class)->assertViewHas('count', 3)
            ->assertViewHas('metrics', fn ($metrics) => $metrics['summary']['total'] === '6000.0000')
            ->assertViewHas('yearFilters', ['fund' => $this->fundB]);
        $this->get('/contracts/create')->assertOk()->assertViewHas('values', fn ($values) => $values['fund_id'] === $this->fundB);
        $this->post('/session/fund', ['fund_id' => $this->fundA, 'year' => '2025'])->assertRedirect('/dashboard?year=2025');
        Livewire::withQueryParams(['year' => '2025'])->test(Overview::class)->assertViewHas('count', 1)->assertSee('A-PREVIOUS')->assertDontSee('B-PREVIOUS');
    }

    public function test_regular_user_can_switch_between_authorized_funds_but_department_scope_is_preserved(): void
    {
        $user = User::create(['name' => 'Regular', 'username' => 'REGULAR', 'password' => 'SyntheticPassword123'])->fresh();
        $this->grant($user, $this->fundA);
        $this->grant($user, $this->fundB, $this->department);
        $this->actingAs($user)->post('/session/fund', ['fund_id' => $this->fundB])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        Livewire::test(Overview::class)->assertViewHas('count', 1)->assertViewHas('expired', 0)
            ->assertViewHas('metrics', fn ($metrics) => $metrics['summary']['total'] === '1000.0000')
            ->assertSee('B-CURRENT')->assertDontSee('B-EXPIRED')->assertDontSee('A-CURRENT');
    }

    public function test_hidden_switcher_and_endpoint_reject_unauthorized_and_missing_targets(): void
    {
        $user = User::create(['name' => 'Regular', 'username' => 'REGULAR', 'password' => 'SyntheticPassword123']);
        $this->grant($user, $this->fundA);
        $response = $this->actingAs($user)->get('/dashboard')->assertOk();
        $response->assertDontSee('Alterar Fundo')->assertDontSee('id="session-fund"', false)->assertDontSee('Fundo B');
        $this->post('/session/fund', ['fund_id' => $this->fundB])->assertForbidden()->assertSessionHas('fund_id', $this->fundA);
        $this->post('/session/fund', ['fund_id' => 9999])->assertSessionHasErrors('fund_id')->assertSessionHas('fund_id', $this->fundA);
        $this->post('/session/fund', ['year' => 'all'])->assertSessionHasErrors('fund_id');
        $this->post('/session/fund', ['fund_id' => $this->fundA, 'year' => 'invalid'])->assertSessionHasErrors('year');
        $this->assertDatabaseMissing('activity_events', ['action' => 'fund_selected']);
    }

    public function test_switch_revalidates_current_actor_role_in_transaction(): void
    {
        $this->grant($this->admin, $this->fundA);
        DB::table('users')->where('id', $this->admin->id)->update(['user_type' => 'Usuario']);
        $this->post('/session/fund', ['fund_id' => $this->fundB])->assertForbidden()->assertSessionHas('fund_id', $this->fundA);
        $this->assertDatabaseMissing('activity_events', ['action' => 'fund_selected']);
    }

    public function test_audit_failure_preserves_previous_fund_and_authenticated_session(): void
    {
        Event::listen('eloquent.creating: '.ActivityEvent::class, function ($event) {
            if ($event->action === 'fund_selected') {
                throw new \RuntimeException('Simulated audit failure');
            }
        });
        $this->post('/session/fund', ['fund_id' => $this->fundB])->assertStatus(500)->assertSessionHas('fund_id', $this->fundA);
        $this->assertAuthenticatedAs($this->admin);
        $this->assertDatabaseMissing('activity_events', ['action' => 'fund_selected']);
    }

    public function test_missing_fund_context_shows_no_portfolio_and_can_be_selected_without_login(): void
    {
        session()->forget('fund_id');
        Livewire::test(Overview::class)->assertViewHas('count', 0)->assertViewHas('alertCount', 0)
            ->assertViewHas('metrics', fn ($metrics) => $metrics['summary']['count'] === 0 && $metrics['summary']['total'] === '0.0000')
            ->assertSee('Selecione um Fundo acima')->assertDontSee('A-CURRENT')->assertDontSee('B-CURRENT');
        $this->post('/session/fund', ['fund_id' => $this->fundB])->assertRedirect('/dashboard')->assertSessionHas('fund_id', $this->fundB);
        $this->assertAuthenticatedAs($this->admin);
    }

    public function test_url_fund_cannot_override_session_and_guest_cannot_switch(): void
    {
        Livewire::withQueryParams(['fund' => $this->fundB, 'year' => 'all'])->test(Overview::class)
            ->assertViewHas('count', 3)->assertSee('A-CURRENT')->assertDontSee('B-CURRENT');
        $this->post('/logout')->assertRedirect('/login');
        $this->post('/session/fund', ['fund_id' => $this->fundB])->assertRedirect('/login');
    }
}
