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

class ExpirationCalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 10, 10)->startOfDay());
        $this->user = User::create(['name' => 'Regular', 'username' => 'REGULAR', 'password' => 'TestPassword12345']);
        $fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo', 'name_key' => 'FUNDO', 'raw_payload' => '{}']);
        $scope = AccessScope::create(['scope_key' => hash('sha256', 'calendar-scope'), 'fund' => 'Fundo', 'fund_id' => $fund]);
        $this->user->scopes()->attach($scope);
        $this->contract = Contract::create(['numero' => 'VISIVEL-CALENDARIO', 'fund_id' => $fund, 'ownership_resolved' => true,
            'vigencia_inicio' => '2026-01-01', 'vigencia_fim_original' => '2026-10-31', 'quality_issues' => []]);
        $this->actingAs($this->user->fresh());
    }

    public function test_calendar_and_livewire_alerts_obey_current_scope_and_known_dates(): void
    {
        Contract::create(['numero' => 'SEGREDO-CALENDARIO', 'vigencia_inicio' => '2026-01-01', 'vigencia_fim_original' => '2026-10-31', 'quality_issues' => []]);
        $this->get('/calendar?month=2026-10')->assertOk()->assertSee('VISIVEL-CALENDARIO')->assertDontSee('SEGREDO-CALENDARIO')->assertSee('1 vencimentos')
            ->assertViewHas('counts', fn ($counts) => $counts->all() === ['2026-10-31' => 1]);
        Livewire::test(Overview::class)->assertViewHas('alertCount', 1)->assertSee('VISIVEL-CALENDARIO')->assertDontSee('SEGREDO-CALENDARIO');
        $this->contract->update(['vigencia_em_revisao' => true]);
        $this->get('/calendar?month=2026-10')->assertDontSee('VISIVEL-CALENDARIO')->assertSee('1 contratos em revisão');
        Livewire::test(Overview::class)->assertViewHas('alertCount', 0);
        $this->contract->update(['vigencia_em_revisao' => false, 'vigencia_fim_original' => null]);
        $this->get('/calendar?month=2026-10')->assertSee('1 com vigência indeterminada');
        $this->contract->update(['vigencia_fim_original' => '2026-10-31']);
        $component = Livewire::test(Overview::class)->assertViewHas('alertCount', 1);
        $this->user->scopes()->detach();
        $component->call('$refresh')->assertViewHas('alertCount', 0)->assertDontSee('VISIVEL-CALENDARIO');
        $this->get('/calendar?month=2026-10')->assertDontSee('VISIVEL-CALENDARIO');
    }

    public function test_month_and_alert_boundaries_updated_date_and_soft_delete(): void
    {
        $this->get('/calendar?month=2026-11')->assertDontSee('VISIVEL-CALENDARIO');
        $this->contract->update(['vigencia_fim_atual' => '2026-11-01']);
        $this->get('/calendar?month=2026-10')->assertDontSee('VISIVEL-CALENDARIO');
        $this->get('/calendar?month=2026-11')->assertSee('VISIVEL-CALENDARIO');
        $this->contract->update(['vigencia_fim_atual' => '2026-10-10']);
        Livewire::test(Overview::class)->assertViewHas('alertCount', 1);
        $this->contract->update(['vigencia_fim_atual' => '2026-10-09']);
        Livewire::test(Overview::class)->assertViewHas('alertCount', 0);
        $this->contract->delete();
        $this->get('/calendar?month=2026-10')->assertDontSee('VISIVEL-CALENDARIO');
    }

    public function test_preferences_are_personal_validated_and_audited(): void
    {
        $other = User::create(['name' => 'Outro', 'username' => 'OTHER', 'password' => 'TestPassword12345']);
        $this->put('/calendar/preferences', ['enabled' => 1, 'days' => 0])->assertSessionHasErrors('days');
        $this->put('/calendar/preferences', ['enabled' => 1, 'days' => 366])->assertSessionHasErrors('days');
        $this->put('/calendar/preferences', ['enabled' => 1, 'days' => 10, 'user_id' => $other->id])->assertRedirect('/calendar');
        $this->assertSame(10, $this->user->fresh()->expiration_alert_days);
        $this->assertSame(30, $other->fresh()->expiration_alert_days);
        $this->actingAs($this->user->fresh());
        Livewire::test(Overview::class)->assertViewHas('alertCount', 0);
        $this->put('/calendar/preferences', ['enabled' => 0, 'days' => 365])->assertRedirect();
        $this->actingAs($this->user->fresh());
        Livewire::test(Overview::class)->assertDontSee('Alertas de vencimento');
        $this->assertDatabaseHas('activity_events', ['actor_id' => $this->user->id, 'action' => 'expiration_preferences_updated']);
        $this->get('/calendar?month=2026-13')->assertSessionHasErrors('month');
        $this->get('/calendar?month=0000-01')->assertSessionHasErrors('month');
    }
}
