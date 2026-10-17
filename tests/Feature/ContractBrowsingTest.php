<?php

namespace Tests\Feature;

use App\Livewire\Overview;
use App\Models\AccessScope;
use App\Models\Contract;
use App\Models\ImportRun;
use App\Models\LegacyRecord;
use App\Models\User;
use App\Support\ContractFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ContractBrowsingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 10, 9)->startOfDay());
    }

    private function user(bool $admin = true): User
    {
        return User::create(['name' => 'Pessoa fictícia', 'username' => 'TESTE', 'password' => 'SenhaFicticia123',
            'user_type' => $admin ? 'Administrador' : 'Usuario']);
    }

    private function contract(array $attributes = []): Contract
    {
        $id = Contract::withTrashed()->count() + 1;
        $run = ImportRun::create(['source_hash' => str_repeat('a', 64), 'status' => 'completed', 'started_at' => now()]);
        $record = LegacyRecord::create(['source_table' => 'TB_Contratos', 'legacy_id' => (string) $id,
            'source_hash' => hash('sha256', (string) $id), 'raw_payload' => [], 'import_run_id' => $run->id]);

        return Contract::create(array_replace(['legacy_id' => $id, 'legacy_record_id' => $record->id,
            'numero' => '019/26', 'processo' => 'PROCESSO-TESTE', 'fornecedor_nome_snapshot' => 'Fornecedor fictício',
            'fornecedor_documento_snapshot' => '11.222.333/0001-81', 'supplier_document_key' => '11222333000181',
            'fundo_nome_snapshot' => 'Fundo fictício', 'valor_total' => '1908.6300', 'exercicio' => 2026,
            'vigencia_inicio' => '2026-01-01', 'vigencia_fim_original' => '2026-12-31',
            'ownership_resolved' => true, 'vigencia_em_revisao' => false, 'quality_issues' => []], $attributes));
    }

    private function catalog(string $table, string $name): int
    {
        return DB::table($table)->insertGetId(['legacy_id' => DB::table($table)->count() + 1,
            'name' => $name, 'name_key' => $name, 'raw_payload' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_guest_and_inactive_user_cannot_browse_contracts(): void
    {
        $contract = $this->contract();
        $this->get('/contracts')->assertRedirect('/login');
        $this->get('/contracts/'.$contract->id)->assertRedirect('/login');
        $user = $this->user();
        $user->forceFill(['is_active' => false])->save();
        $this->actingAs($user)->get('/contracts')->assertRedirect('/login');
    }

    public function test_admin_can_search_and_combine_filters_without_merging_duplicate_numbers(): void
    {
        $fund = $this->catalog('contract_funds', 'Fundo A');
        $department = $this->catalog('contract_departments', 'Secretaria A');
        $one = $this->contract(['fund_id' => $fund, 'department_id' => $department]);
        $two = $this->contract(['fornecedor_nome_snapshot' => 'Outro fornecedor', 'exercicio' => 2025]);
        $this->actingAs($this->user())->get('/contracts?q=019%2F26&year=')->assertOk()
            ->assertViewHas('contracts', fn ($rows) => $rows->total() === 2);
        foreach (['PROCESSO-TESTE', 'Fornecedor fictício', '11222333000181', '11.222.333/0001-81'] as $search) {
            $this->get('/contracts?'.http_build_query(['q' => $search, 'fund' => $fund, 'department' => $department,
                'year' => 2026, 'validity' => 'vigente']))->assertOk()
                ->assertViewHas('contracts', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $one->id);
        }
        $this->get('/contracts?year=2025')->assertViewHas('contracts', fn ($rows) => $rows->first()->id === $two->id);
        $this->get('/contracts?validity=invalido')->assertSessionHasErrors('validity');
    }

    public function test_archive_defaults_to_current_year_and_keeps_all_years_available(): void
    {
        $current = $this->contract(['numero' => 'ATUAL-2026']);
        $previous = $this->contract(['numero' => 'ANTERIOR-2025', 'exercicio' => 2025]);
        $undated = $this->contract(['numero' => 'SEM-EXERCICIO', 'exercicio' => null]);
        $response = $this->actingAs($this->user())->get('/contracts')->assertOk()
            ->assertViewHas('contracts', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $current->id)
            ->assertDontSee('ANTERIOR-2025')->assertDontSee('SEM-EXERCICIO')
            ->assertSee('year=2026', false);
        $html = new \DOMDocument;
        @$html->loadHTML($response->getContent());
        $xpath = new \DOMXPath($html);
        $this->assertSame('2026', $xpath->query('//select[@id="year"]/option[@selected]')->item(0)->getAttribute('value'));
        $this->get('/contracts?year=')->assertOk()->assertViewHas('contracts', fn ($rows) => $rows->total() === 3)
            ->assertSee($previous->numero)->assertSee($undated->numero);
        $this->get('/contracts?year=2025')->assertOk()
            ->assertViewHas('contracts', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $previous->id);
    }

    public function test_archive_current_year_tracks_clock_even_when_it_has_no_contracts(): void
    {
        $this->contract();
        $this->travelTo(now()->setDate(2027, 1, 1));
        $this->actingAs($this->user())->get('/contracts')->assertOk()
            ->assertViewHas('contracts', fn ($rows) => $rows->total() === 0)
            ->assertViewHas('years', fn ($years) => $years->all() === [2027, 2026]);
        $this->get('/contracts?year=invalid')->assertSessionHasErrors('year');
    }

    public function test_archive_pagination_and_reports_keep_the_default_year_filter(): void
    {
        for ($i = 0; $i < 21; $i++) {
            $this->contract();
        }
        $this->actingAs($this->user())->get('/contracts')->assertOk()
            ->assertViewHas('contracts', fn ($rows) => $rows->total() === 21 && str_contains($rows->nextPageUrl(), 'year=2026'))
            ->assertSee(route('reports.index', ['year' => 2026]));
        $this->get('/contracts?page=2')->assertViewHas('contracts', fn ($rows) => $rows->count() === 1);
    }

    public function test_scope_limits_rows_filter_options_and_direct_urls(): void
    {
        $fundA = $this->catalog('contract_funds', 'Fundo autorizado');
        $fundB = $this->catalog('contract_funds', 'Fundo restrito');
        $departmentA = $this->catalog('contract_departments', 'Secretaria autorizada');
        $departmentB = $this->catalog('contract_departments', 'Secretaria restrita');
        $allowed = $this->contract(['fund_id' => $fundA, 'department_id' => $departmentA]);
        $otherFund = $this->contract(['fund_id' => $fundB, 'department_id' => $departmentB, 'exercicio' => 2020]);
        $otherDepartment = $this->contract(['fund_id' => $fundA, 'department_id' => $departmentB]);
        $unresolved = $this->contract(['fund_id' => $fundA, 'department_id' => $departmentA, 'ownership_resolved' => false]);
        $user = $this->user(false);
        $scope = AccessScope::create(['scope_key' => hash('sha256', 'a'), 'fund' => 'Fundo autorizado',
            'department' => 'Secretaria autorizada', 'fund_id' => $fundA, 'department_id' => $departmentA]);
        $user->scopes()->attach($scope);
        $this->actingAs($user)->get('/contracts')->assertOk()->assertDontSee('Fundo restrito')->assertDontSee('Secretaria restrita')
            ->assertViewHas('years', fn ($years) => ! $years->contains(2020))
            ->assertViewHas('contracts', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $allowed->id);
        $this->get('/contracts/'.$allowed->id)->assertOk();
        foreach ([$otherFund, $otherDepartment, $unresolved] as $contract) {
            $this->get('/contracts/'.$contract->id)->assertForbidden();
        }
        $this->get('/contracts?fund='.$fundB)->assertViewHas('contracts', fn ($rows) => $rows->total() === 0);
        $user->scopes()->detach();
        $this->get('/contracts')->assertViewHas('contracts', fn ($rows) => $rows->total() === 0);
        $this->get('/contracts/'.$allowed->id)->assertForbidden();
    }

    public function test_current_contracts_can_be_filtered_by_type_in_primary_filters_with_scope_preserved(): void
    {
        $fund = $this->catalog('contract_funds', 'Fundo autorizado');
        $otherFund = $this->catalog('contract_funds', 'Fundo restrito');
        $scope = AccessScope::create(['scope_key' => hash('sha256', 'type-filter'),
            'fund' => 'Fundo autorizado', 'fund_id' => $fund]);
        $user = $this->user(false);
        $user->scopes()->attach($scope);
        $allowed = $this->contract(['fund_id' => $fund, 'tipo' => 'Serviços']);
        $this->contract(['fund_id' => $fund, 'tipo' => 'Compras']);
        $this->contract(['fund_id' => $fund, 'tipo' => 'Serviços', 'vigencia_fim_original' => '2026-09-30']);
        $this->contract(['fund_id' => $otherFund, 'tipo' => 'Serviços']);
        $this->contract(['fund_id' => $otherFund, 'tipo' => 'Tipo restrito']);

        $response = $this->actingAs($user)->get('/contracts?'.http_build_query([
            'validity' => 'vigente', 'type' => 'Serviços', 'fund' => $fund, 'year' => 2026,
        ]))->assertOk()->assertSee('Contratos vigentes')
            ->assertSeeInOrder(['for="type"', 'Pesquisa avançada'], false)
            ->assertSee('value="Serviços" selected', false)
            ->assertDontSee('Tipo restrito')
            ->assertViewHas('contracts', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $allowed->id);
        $this->assertSame(1, substr_count($response->getContent(), 'name="type"'));
        $this->get('/contracts?'.http_build_query(['validity' => 'vigente', 'fund' => $fund, 'year' => 2026]))
            ->assertOk()->assertViewHas('contracts', fn ($rows) => $rows->total() === 2);
        $this->get('/contracts?'.http_build_query(['validity' => 'vigente', 'type' => 'Tipo restrito']))
            ->assertOk()->assertViewHas('contracts', fn ($rows) => $rows->total() === 0);
    }

    public function test_detail_renders_all_fields_masks_personal_documents_and_escapes_source_text(): void
    {
        $fund = $this->catalog('contract_funds', 'Fundo A');
        $contract = $this->contract(['fund_id' => $fund, 'objeto' => '<script>alert(1)</script>',
            'cpf_gestor' => '12345678901', 'fornecedor_documento_snapshot' => '12345678901',
            'vigencia_fim_atual' => null, 'ownership_resolved' => false,
            'quality_issues' => [['field' => 'Final_Vig_Atualiz', 'code' => 'zero_date', 'message' => 'Data inválida na origem.']]]);
        $admin = $this->user();
        $response = $this->actingAs($admin)->get('/contracts/'.$contract->id)->assertOk()
            ->assertSee('R$ 1.908,63')->assertSee('Não informado')->assertSee('Data inválida na origem.')
            ->assertSee('restrita a administradores')->assertSee('12345678901')->assertDontSee('<script>alert(1)</script>', false);
        foreach (array_keys(ContractFields::MAP) as $source) {
            $response->assertSee(ContractFields::label($source));
        }
        $contract->update(['ownership_resolved' => true]);
        $admin->update(['user_type' => 'Usuario']);
        $scope = AccessScope::create(['scope_key' => hash('sha256', 'all-fund'), 'fund' => 'Fundo A', 'fund_id' => $fund]);
        $admin->scopes()->attach($scope);
        $this->get('/contracts/'.$contract->id)->assertOk()->assertDontSee('12345678901')->assertSee('•••8901');
    }

    public function test_pagination_preserves_filters_and_excludes_soft_deleted_contracts(): void
    {
        for ($i = 0; $i < 22; $i++) {
            $this->contract();
        }
        $deleted = $this->contract();
        $deleted->delete();
        $this->actingAs($this->user())->get('/contracts?q=019&year=2026')->assertOk()
            ->assertViewHas('contracts', fn ($rows) => $rows->total() === 22 && $rows->count() === 20)
            ->assertSee('q=019', false)->assertSee('year=2026', false);
        $this->get('/contracts?q=019&year=2026&page=2')->assertViewHas('contracts', fn ($rows) => $rows->count() === 2);
        $this->get('/contracts/'.$deleted->id)->assertNotFound();
    }

    public function test_validity_filters_match_display_with_missing_dates_and_amendment_review(): void
    {
        $cases = [
            'vigente' => [],
            'a_iniciar' => ['vigencia_inicio' => '2026-11-01'],
            'vencido' => ['vigencia_fim_original' => '2026-10-08'],
            'indeterminado' => ['vigencia_inicio' => null],
            'em_revisao' => ['vigencia_em_revisao' => true],
        ];
        $this->actingAs($this->user());
        foreach ($cases as $status => $attributes) {
            $contract = $this->contract($attributes);
            $this->assertSame($status, $contract->validity);
        }
        foreach (array_keys($cases) as $status) {
            $this->get('/contracts?validity='.$status)->assertOk()
                ->assertViewHas('contracts', fn ($rows) => $rows->total() === 1 && $rows->first()->validity === $status);
        }
    }

    public function test_dashboard_cards_match_their_lists_including_ownership_review_and_access_scope(): void
    {
        $fund = $this->catalog('contract_funds', 'Fundo autorizado');
        $current = $this->contract(['numero' => 'CURRENT', 'fund_id' => $fund]);
        $expired = $this->contract(['numero' => 'EXPIRED', 'fund_id' => $fund, 'vigencia_fim_original' => '2026-10-08']);
        $review = $this->contract(['numero' => 'REVIEW', 'fund_id' => $fund, 'vigencia_em_revisao' => true]);
        $unresolved = $this->contract(['numero' => 'UNRESOLVED', 'fund_id' => $fund, 'ownership_resolved' => false]);
        $both = $this->contract(['numero' => 'BOTH', 'fund_id' => $fund, 'vigencia_em_revisao' => true, 'ownership_resolved' => false]);
        $deleted = $this->contract(['numero' => 'DELETED', 'fund_id' => $fund, 'vigencia_em_revisao' => true]);
        $deleted->delete();
        $admin = $this->user();
        $this->withSession(['fund_id' => $fund]);
        Livewire::actingAs($admin)->test(Overview::class)->assertViewHas('count', 5)->assertViewHas('expired', 1)->assertViewHas('review', 3)
            ->assertSee(route('contracts.index', ['year' => 2026, 'fund' => $fund, 'validity' => 'vigente']))
            ->assertSee(route('contracts.index', ['year' => 2026, 'fund' => $fund, 'validity' => 'vencido']))
            ->assertSee(route('contracts.index', ['year' => 2026, 'fund' => $fund, 'review' => 1]));
        $this->actingAs($admin)->get('/contracts?review=1')->assertOk()
            ->assertViewHas('contracts', fn ($rows) => $rows->total() === 3 && $rows->pluck('id')->sort()->values()->all() === collect([$review->id, $unresolved->id, $both->id])->sort()->values()->all());
        $this->get('/contracts?validity=vigente')->assertViewHas('contracts', fn ($rows) => $rows->total() === 2 && $rows->contains('id', $current->id));
        $this->get('/contracts?validity=vencido')->assertViewHas('contracts', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $expired->id);
        $this->get('/contracts?review=1&q=UNRESOLVED')->assertViewHas('contracts', fn ($rows) => $rows->total() === 1);
        $this->get('/reports?review=1')->assertOk()->assertViewHas('contracts', fn ($rows) => $rows->total() === 3);
        $this->get('/contracts?review=invalid')->assertSessionHasErrors('review');

        $this->contract(['numero' => 'SECRET', 'vigencia_em_revisao' => true]);
        $user = User::create(['name' => 'Scoped', 'username' => 'SCOPED', 'password' => 'TestPassword12345']);
        $scope = AccessScope::create(['scope_key' => hash('sha256', 'card-scope'), 'fund' => 'Fundo autorizado', 'fund_id' => $fund]);
        $user->scopes()->attach($scope);
        Livewire::actingAs($user)->test(Overview::class)->assertViewHas('review', 1);
        $this->actingAs($user)->get('/contracts?review=1')->assertOk()
            ->assertViewHas('contracts', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $review->id);
        $this->get('/reports?review=1')->assertViewHas('contracts', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $review->id);
    }
}
