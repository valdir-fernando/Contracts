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
        $this->contract = Contract::create(['numero' => 'VISIVEL-CALENDARIO', 'exercicio' => 2026, 'fund_id' => $fund, 'ownership_resolved' => true,
            'vigencia_inicio' => '2026-01-01', 'vigencia_fim_atual' => '2026-10-31', 'quality_issues' => []]);
        $this->actingAs($this->user->fresh());
        $this->withSession(['fund_id' => $fund]);
    }

    public function test_calendar_and_livewire_alerts_obey_current_scope_and_known_dates(): void
    {
        Contract::create(['numero' => 'SEGREDO-CALENDARIO', 'vigencia_inicio' => '2026-01-01', 'vigencia_fim_atual' => '2026-10-31', 'quality_issues' => []]);
        $this->get('/calendar?month=2026-10')->assertOk()->assertSee('VISIVEL-CALENDARIO')->assertDontSee('SEGREDO-CALENDARIO')->assertSee('1 vencimento')
            ->assertViewHas('counts', fn ($counts) => $counts->all() === ['2026-10-31' => 1]);
        Livewire::test(Overview::class)->assertViewHas('alertCount', 1)->assertSee('VISIVEL-CALENDARIO')->assertDontSee('SEGREDO-CALENDARIO');
        $this->contract->update(['vigencia_em_revisao' => true]);
        $this->get('/calendar?month=2026-10')->assertSee('VISIVEL-CALENDARIO');
        Livewire::test(Overview::class)->assertViewHas('alertCount', 1);
        $this->contract->update(['vigencia_em_revisao' => false, 'vigencia_fim_atual' => null]);
        $this->get('/calendar?month=2026-10')->assertSee('1 sem data final atualizada');
        $this->contract->update(['vigencia_fim_atual' => '2026-10-31']);
        $component = Livewire::test(Overview::class)->assertViewHas('alertCount', 1);
        $this->user->scopes()->detach();
        $component->call('$refresh')->assertViewHas('alertCount', 0)->assertDontSee('VISIVEL-CALENDARIO');
        $this->get('/calendar?month=2026-10')->assertRedirect('/login');
    }

    public function test_calendar_is_limited_to_selected_fund_even_with_other_authorized_funds_or_admin_role(): void
    {
        $otherFund = DB::table('contract_funds')->insertGetId(['name' => 'Outro Fundo', 'name_key' => 'OUTRO FUNDO', 'raw_payload' => '{}']);
        $scope = AccessScope::create(['scope_key' => 'calendar-other-fund', 'fund' => 'Outro Fundo', 'fund_id' => $otherFund]);
        $this->user->scopes()->attach($scope);
        foreach ([['numero' => 'OUTRO-VENCIMENTO'], ['numero' => 'OUTRO-REVISAO', 'vigencia_em_revisao' => true],
            ['numero' => 'OUTRO-INDETERMINADO', 'vigencia_fim_atual' => null]] as $changes) {
            $copy = $this->contract->replicate();
            $copy->fill($changes + ['fund_id' => $otherFund])->save();
        }
        $this->get('/calendar?month=2026-10&fund='.$otherFund.'&fund_id='.$otherFund)->assertOk()
            ->assertViewHas('contracts', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $this->contract->id)
            ->assertViewHas('counts', fn ($counts) => $counts->all() === ['2026-10-31' => 1])
            ->assertViewHas('reviewCount', 0)->assertViewHas('unknownCount', 0)->assertDontSee('OUTRO-VENCIMENTO');
        $admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'TestPassword12345', 'user_type' => 'Administrador']);
        $this->actingAs($admin)->get('/calendar?month=2026-10')->assertOk()
            ->assertViewHas('contracts', fn ($rows) => $rows->total() === 1 && $rows->first()->fund_id === $this->contract->fund_id)
            ->assertViewHas('reviewCount', 0)->assertViewHas('unknownCount', 0);
        $this->withSession(['fund_id' => $otherFund])->get('/calendar?month_number=10&calendar_year=2026')->assertOk()
            ->assertSee('OUTRO-VENCIMENTO')->assertDontSee('VISIVEL-CALENDARIO')
            ->assertViewHas('counts', fn ($counts) => $counts->all() === ['2026-10-31' => 2])
            ->assertViewHas('reviewCount', 0)->assertViewHas('unknownCount', 1);
    }

    public function test_calendar_without_selected_fund_does_not_list_any_contracts(): void
    {
        $admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'TestPassword12345', 'user_type' => 'Administrador']);
        $this->actingAs($admin)->withSession(['fund_id' => null])->get('/calendar?month=2026-10&fund='.$this->contract->fund_id)->assertOk()
            ->assertViewHas('contracts', fn ($rows) => $rows->total() === 0)
            ->assertViewHas('counts', fn ($counts) => $counts->isEmpty())
            ->assertViewHas('reviewCount', 0)->assertViewHas('unknownCount', 0)->assertDontSee('VISIVEL-CALENDARIO');
    }

    public function test_calendar_pagination_keeps_the_selected_fund(): void
    {
        for ($i = 0; $i < 31; $i++) {
            $this->contract->replicate()->save();
        }
        $otherFund = DB::table('contract_funds')->insertGetId(['name' => 'Outro Fundo', 'name_key' => 'OUTRO FUNDO', 'raw_payload' => '{}']);
        $admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'TestPassword12345', 'user_type' => 'Administrador']);
        $copy = $this->contract->replicate();
        $copy->fill(['fund_id' => $otherFund, 'numero' => 'OUTRO-FUNDO'])->save();
        $this->actingAs($admin)->get('/calendar?month=2026-10&page=2')->assertOk()
            ->assertViewHas('contracts', fn ($rows) => $rows->total() === 32 && $rows->count() === 2
                && $rows->every(fn ($row) => $row->fund_id === $this->contract->fund_id))
            ->assertViewHas('counts', fn ($counts) => $counts->all() === ['2026-10-31' => 32])->assertDontSee('OUTRO-FUNDO');
    }

    public function test_calendar_organization_column_uses_department_only_for_selected_municipal_fund(): void
    {
        $this->contract->update(['fundo_nome_snapshot' => 'Fundo do contrato', 'secretaria_nome_snapshot' => 'Secretaria de Saúde']);
        $organization = function ($response): string {
            $html = new \DOMDocument;
            @$html->loadHTML($response->getContent());
            $xpath = new \DOMXPath($html);

            return trim($xpath->query('//tr[@data-row-href="'.route('contracts.show', $this->contract).'"]/td[4]')->item(0)->textContent);
        };
        $this->assertSame('Fundo do contrato', $organization($this->get('/calendar?month=2026-10')->assertOk()));
        foreach (['MUNICIPIO DE NIQUELÂNDIA', '  município de niquelândia  '] as $name) {
            DB::table('contract_funds')->where('id', $this->contract->fund_id)->update(['name' => $name]);
            $this->assertSame('Secretaria de Saúde', $organization($this->get('/calendar?month=2026-10')->assertOk()));
        }
        $this->contract->update(['secretaria_nome_snapshot' => null]);
        $this->assertSame('Secretaria não informada', $organization($this->get('/calendar?month=2026-10')->assertOk()));
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

    public function test_dashboard_saves_personal_alert_days_and_recalculates_alerts(): void
    {
        $other = User::create(['name' => 'Outro', 'username' => 'OTHER', 'password' => 'TestPassword12345']);
        Livewire::test(Overview::class)->assertSet('alertDaysInput', '30')
            ->assertSee('aria-haspopup="dialog"', false)->assertViewHas('alertCount', 1)
            ->set('alertDaysInput', '10')->call('saveAlertDays')->assertHasNoErrors()
            ->assertDispatched('alert-days-saved')->assertViewHas('alertDays', 10)->assertViewHas('alertCount', 0);
        $this->assertSame(10, $this->user->fresh()->expiration_alert_days);
        $this->assertSame(30, $other->fresh()->expiration_alert_days);
        $this->assertDatabaseHas('activity_events', ['actor_id' => $this->user->id, 'action' => 'expiration_preferences_updated',
            'metadata->days' => 10]);
    }

    public function test_dashboard_rejects_invalid_alert_days_without_saving(): void
    {
        $component = Livewire::test(Overview::class);
        foreach (['0', '366', '1.5', 'abc', ''] as $days) {
            $component->set('alertDaysInput', $days)->call('saveAlertDays')->assertHasErrors('alertDaysInput')
                ->assertNotDispatched('alert-days-saved');
        }
        $this->assertSame(30, $this->user->fresh()->expiration_alert_days);
        $this->assertDatabaseMissing('activity_events', ['action' => 'expiration_preferences_updated']);
    }

    public function test_dashboard_rejects_saving_alert_days_with_stale_actor(): void
    {
        $component = Livewire::test(Overview::class);
        User::whereKey($this->user->id)->increment('session_version');
        $component->set('alertDaysInput', '10')->call('saveAlertDays')->assertForbidden();
        $this->assertSame(30, $this->user->fresh()->expiration_alert_days);
        $this->assertDatabaseMissing('activity_events', ['action' => 'expiration_preferences_updated']);
    }

    public function test_dashboard_lists_every_alert_in_the_selected_period_with_current_scope(): void
    {
        $rows = [];
        $attributes = $this->contract->getAttributes();
        unset($attributes['id']);
        for ($index = 1; $index <= 501; $index++) {
            $rows[] = array_replace($attributes, ['Contrato' => sprintf('ALERTA-%03d', $index)]);
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('contracts')->insert($chunk);
        }
        foreach ([['fund_id' => null], ['exercicio' => 2025], ['vigencia_fim_atual' => '2027-10-11'], ['deleted_at' => now()]] as $excluded) {
            DB::table('contracts')->insert(array_replace($attributes, $excluded, ['Contrato' => 'EXCLUIDO-ALERTAS']));
        }

        Livewire::test(Overview::class)->set('alertDaysInput', '365')->call('saveAlertDays')
            ->assertHasNoErrors()->assertViewHas('alertCount', 502)->assertSee('data-expanded="true"', false)
            ->assertViewHas('alerts', fn ($alerts) => $alerts->count() === 502 && $alerts->pluck('id')->unique()->count() === 502
                && ! $alerts->contains('numero', 'EXCLUIDO-ALERTAS') && $alerts->contains('numero', 'ALERTA-501'))
            ->assertSee('ALERTA-501');
    }

    public function test_dashboard_expands_alert_panel_only_after_four_contracts(): void
    {
        for ($index = 0; $index < 3; $index++) {
            $this->contract->replicate()->save();
        }
        $component = Livewire::test(Overview::class)->assertViewHas('alertCount', 4)
            ->assertSee('data-expanded="false"', false);
        $this->contract->replicate()->save();
        $component->call('$refresh')->assertViewHas('alertCount', 5)->assertSee('data-expanded="true"', false);
        $component->set('alertDaysInput', '1')->call('saveAlertDays')
            ->assertViewHas('alertCount', 0)->assertSee('data-expanded="false"', false);
    }

    public function test_alert_shows_full_updated_end_date_after_contract_number(): void
    {
        $this->contract->update(['vigencia_fim_atual' => '2026-10-25', 'vigencia_fim_original' => '2026-10-20']);
        Livewire::test(Overview::class)
            ->assertSee('datetime="2026-10-25" aria-label="Término do contrato: 25/10/2026"', false)
            ->assertSeeInOrder(['class="alert-contract-heading"', 'VISIVEL-CALENDARIO', '25/10/2026'], false);
    }
}
