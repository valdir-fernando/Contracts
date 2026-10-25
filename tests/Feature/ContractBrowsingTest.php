<?php

namespace Tests\Feature;

use App\Livewire\Overview;
use App\Models\AccessScope;
use App\Models\Contract;
use App\Models\ContractAmendment;
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

    public function test_reports_list_only_current_year_contracts_from_the_session_fund(): void
    {
        $fund = $this->catalog('contract_funds', 'Fundo selecionado');
        $otherFund = $this->catalog('contract_funds', 'Outro fundo');
        $current = $this->contract(['numero' => 'CONTRATO-ATUAL', 'fund_id' => $fund]);
        $this->contract(['numero' => 'CONTRATO-ANTERIOR', 'fund_id' => $fund, 'exercicio' => 2025]);
        $this->contract(['numero' => 'CONTRATO-OUTRO-FUNDO', 'fund_id' => $otherFund]);
        $deleted = $this->contract(['numero' => 'CONTRATO-EXCLUIDO', 'fund_id' => $fund]);
        $deleted->delete();

        $this->actingAs($this->user())->withSession(['fund_id' => $fund])->get('/reports')->assertOk()
            ->assertViewHas('reportYear', 2026)
            ->assertSee('name="report_year" min="1" max="9999" value="2026"', false);

        $this->withSession(['fund_id' => $fund])
            ->get('/reports?fund='.$otherFund.'&year=2025')->assertOk()
            ->assertSee('CONTRATO-ATUAL')->assertDontSee('CONTRATO-ANTERIOR')
            ->assertDontSee('CONTRATO-OUTRO-FUNDO')->assertDontSee('CONTRATO-EXCLUIDO')
            ->assertViewHas('reportContracts', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $current->id);

        $this->get('/reports?report_year=2025')->assertOk()
            ->assertSee('CONTRATO-ANTERIOR')->assertDontSee('CONTRATO-ATUAL')
            ->assertSee('referente ao ano de 2025')
            ->assertViewHas('reportYear', 2025);
        $this->getJson('/reports?report_year=10000')->assertUnprocessable();
        $this->get('/reports?report_year=')->assertOk()
            ->assertSee('CONTRATO-ATUAL')->assertSee('CONTRATO-ANTERIOR')
            ->assertDontSee('CONTRATO-OUTRO-FUNDO')->assertDontSee('CONTRATO-EXCLUIDO')
            ->assertSee('referente a todos os exercícios')->assertViewHas('reportYear', null)
            ->assertViewHas('reportContracts', fn ($rows) => $rows->total() === 2);

        $this->withSession(['fund_id' => null])->get('/reports')->assertOk()
            ->assertViewHas('reportContracts', fn ($rows) => $rows->total() === 0);
    }

    public function test_report_scope_selectors_filter_authorized_funds_and_municipal_departments(): void
    {
        $fund = $this->catalog('contract_funds', 'Fundo Saúde');
        $municipal = $this->catalog('contract_funds', 'MUNICIPIO DE NIQUELÂNDIA');
        $department = $this->catalog('contract_departments', 'Secretaria Educação');
        $otherDepartment = $this->catalog('contract_departments', 'Secretaria Obras');
        $this->contract(['fund_id' => $fund, 'numero' => 'SAUDE']);
        $one = $this->contract(['fund_id' => $municipal, 'department_id' => $department, 'secretaria_nome_snapshot' => 'Secretaria Educação', 'numero' => 'EDUCACAO', 'vigencia_fim_atual' => '2027-06-30']);
        $this->contract(['fund_id' => $municipal, 'department_id' => $otherDepartment, 'numero' => 'OBRAS', 'vigencia_fim_atual' => '2027-06-30']);
        $this->actingAs($this->user())->withSession(['fund_id' => $fund])
            ->get('/reports?report_fund='.$municipal.'&report_department='.$department)->assertOk()
            ->assertViewHas('canSelectDepartment', true)
            ->assertSee('referente ao ano de 2026. Filtros aplicados: Secretaria: Secretaria Educação.')
            ->assertViewHas('reportContracts', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $one->id);
        $this->get('/reports?report_fund='.$municipal.'&report_department='.$department.'&report_year=2025&report_process=INEXISTENTE&report_end_to=2027-06-30')->assertOk()
            ->assertViewHas('reportDepartmentId', $department)
            ->assertViewHas('reportProcess', '')
            ->assertViewHas('reportContracts', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $one->id);
        $this->get('/reports?report_fund='.$fund.'&report_department='.$department)->assertOk()
            ->assertViewHas('canSelectDepartment', false)
            ->assertViewHas('reportDepartmentId', null)
            ->assertViewHas('reportContracts', fn ($rows) => $rows->total() === 1 && $rows->first()->numero === 'SAUDE');
        $this->get('/reports?report_fund='.$municipal.'&report_department=999999')->assertForbidden();
        $this->get('/reports?report_fund=')->assertOk()
            ->assertSee('Secretaria: Secretaria Educação')
            ->assertSee('Secretaria: Não informada')
            ->assertSee('Fundo: Fundo Saúde')
            ->assertDontSee('Fundo: MUNICIPIO DE NIQUELÂNDIA')
            ->assertViewHas('reportFundId', null)
            ->assertViewHas('reportFundName', 'Todos os Fundos')
            ->assertViewHas('reportDepartmentId', null)
            ->assertViewHas('reportContracts', fn ($rows) => $rows->total() === 3);
        $this->get('/reports?report_fund=&report_end_to=2027-06-30')->assertOk()
            ->assertViewHas('reportFundId', null)
            ->assertViewHas('reportContracts', fn ($rows) => $rows->total() === 3);

        $user = User::create(['name' => 'Restrito', 'username' => 'RESTRITO', 'password' => 'TestingPassword123']);
        $scope = AccessScope::create(['scope_key' => hash('sha256', 'report-scope'), 'fund' => 'Fundo Saúde', 'fund_id' => $fund]);
        $user->scopes()->attach($scope);
        $this->actingAs($user)->withSession(['fund_id' => $fund])
            ->getJson('/reports?report_fund='.$municipal)->assertUnprocessable();
        $this->get('/reports')->assertOk()->assertDontSee('name="report_fund"', false)
            ->assertDontSee('name="report_department"', false)
            ->assertViewHas('reportContracts', fn ($rows) => $rows->total() === 1 && $rows->first()->numero === 'SAUDE');
        $this->getJson('/reports?report_fund='.$fund)->assertUnprocessable();
        $this->getJson('/reports?report_department='.$department)->assertUnprocessable();
        $this->get('/reports?report_fund=')->assertOk()
            ->assertViewHas('reportContracts', fn ($rows) => $rows->total() === 1 && $rows->first()->numero === 'SAUDE');

        $user->update(['user_type' => User::MASTER]);
        $this->get('/reports')->assertOk()->assertSee('name="report_fund"', false)
            ->assertSee('name="report_department"', false);
    }

    public function test_report_process_filter_searches_across_pages_without_leaking_other_funds(): void
    {
        $fund = $this->catalog('contract_funds', 'Fundo A');
        $other = $this->catalog('contract_funds', 'Fundo B');
        $match = $this->contract(['fund_id' => $fund, 'processo' => '2026-12345']);
        $this->contract(['fund_id' => $fund, 'processo' => '2026-99999']);
        $this->contract(['fund_id' => $other, 'processo' => '2026-12345']);
        $this->actingAs($this->user())->withSession(['fund_id' => $fund])
            ->get('/reports?report_process=12345')->assertOk()
            ->assertSee('Filtros aplicados: Processo contém: 12345.')
            ->assertViewHas('reportContracts', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $match->id)
            ->assertSee('Total de Contratos: 1');
        $this->get('/reports?report_process=')->assertOk()
            ->assertViewHas('reportContracts', fn ($rows) => $rows->total() === 2);
    }

    public function test_report_supplier_filters_combine_bidding_number_and_partial_name(): void
    {
        $fund = $this->catalog('contract_funds', 'Fundo A');
        $other = $this->catalog('contract_funds', 'Fundo B');
        $attributes = ['fund_id' => $fund, 'modalidade' => 'Pregão eletrônico', 'n_licit' => '015/2026', 'fornecedor_nome_snapshot' => 'Empresa Materiais Brasil Ltda'];
        $match = $this->contract($attributes);
        $this->contract(array_replace($attributes, ['fund_id' => $other]));
        $this->contract(array_replace($attributes, ['n_licit' => '099/2026']));
        $this->contract(array_replace($attributes, ['modalidade' => 'Dispensa']));
        $this->contract(array_replace($attributes, ['fornecedor_nome_snapshot' => 'Outra empresa']));
        $this->actingAs($this->user())->withSession(['fund_id' => $fund])
            ->get('/reports?'.http_build_query(['report_bidding' => 'Pregão', 'report_bidding_number' => '015', 'report_supplier' => 'materiais brasil']))->assertOk()
            ->assertSee('Filtros aplicados: Licitação contém: Pregão; Nº da licitação contém: 015; Nome do fornecedor contém: materiais brasil.')
            ->assertViewHas('reportContracts', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $match->id)
            ->assertSee('Total de Contratos: 1');
        $this->get('/reports?report_supplier=')->assertOk()
            ->assertViewHas('reportContracts', fn ($rows) => $rows->total() === 4);
    }

    public function test_report_type_filter_lists_only_types_in_authorized_fund_and_year(): void
    {
        $fund = $this->catalog('contract_funds', 'Fundo A');
        $other = $this->catalog('contract_funds', 'Fundo B');
        $match = $this->contract(['fund_id' => $fund, 'tipo' => 'Fornecimento']);
        $this->contract(['fund_id' => $fund, 'tipo' => 'Obras']);
        $this->contract(['fund_id' => $other, 'tipo' => 'Tipo restrito']);
        $this->contract(['fund_id' => $fund, 'tipo' => 'Tipo antigo', 'exercicio' => 2025]);
        $this->actingAs($this->user())->withSession(['fund_id' => $fund])
            ->get('/reports?report_type=Fornecimento')->assertOk()
            ->assertSee('Filtros aplicados: Tipo de contrato: Fornecimento.')
            ->assertViewHas('reportContracts', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $match->id)
            ->assertViewHas('reportTypes', fn ($types) => $types->all() === ['Fornecimento', 'Obras']);
        $this->get('/reports?report_type=')->assertOk()
            ->assertViewHas('reportContracts', fn ($rows) => $rows->total() === 2);
    }

    public function test_report_final_filter_uses_updated_end_date_and_inclusive_limits(): void
    {
        $fund = $this->catalog('contract_funds', 'Fundo A');
        $this->contract(['fund_id' => $fund, 'vigencia_fim_atual' => '2026-06-01']);
        $this->contract(['fund_id' => $fund, 'vigencia_fim_atual' => '2026-06-30']);
        $this->contract(['fund_id' => $fund, 'vigencia_fim_atual' => '2026-07-01']);
        $this->contract(['fund_id' => $fund, 'vigencia_fim_atual' => null]);
        $this->actingAs($this->user())->withSession(['fund_id' => $fund])
            ->get('/reports?report_end_from=2026-06-01&report_end_to=2026-06-30')->assertOk()
            ->assertViewHas('reportContracts', fn ($rows) => $rows->total() === 2)
            ->assertSee('Final a partir de: 01/06/2026; Final até: 30/06/2026');
        $this->get('/reports?report_end_to=2026-06-30')->assertOk()
            ->assertViewHas('reportEndFrom', null)
            ->assertViewHas('reportContracts', fn ($rows) => $rows->total() === 2);
        $this->get('/reports?report_end_from=2026-06-30&report_end_to=2026-06-30')->assertOk()
            ->assertViewHas('reportContracts', fn ($rows) => $rows->total() === 1);
        $this->get('/reports?report_end_active=1&report_end_from=&report_end_to=')->assertOk()
            ->assertViewHas('reportEndFrom', '2026-10-09')
            ->assertSee('Final a partir de: 09/10/2026');
        $this->get('/reports?report_end_active=&report_end_from=&report_end_to=')->assertOk()
            ->assertViewHas('reportContracts', fn ($rows) => $rows->total() === 4);
        $this->get('/reports?report_end_from=2026-07-01')->assertOk()
            ->assertViewHas('reportContracts', fn ($rows) => $rows->total() === 2);
        $this->get('/reports?report_end_from=&report_end_to=2026-12-31')->assertOk()
            ->assertViewHas('reportEndFrom', null)
            ->assertViewHas('reportContracts', fn ($rows) => $rows->total() === 4);
        $this->getJson('/reports?report_end_from=2026-07-01&report_end_to=2026-06-30')->assertUnprocessable()
            ->assertJsonValidationErrors('report_end_to')
            ->assertJsonPath('errors.report_end_to.0', 'A data Até deve ser igual ou posterior à data De.');
    }

    public function test_final_filter_clears_other_filters_and_keeps_authorized_fund(): void
    {
        $fund = $this->catalog('contract_funds', 'Fundo A');
        $other = $this->catalog('contract_funds', 'Fundo B');
        $match = $this->contract(['fund_id' => $fund, 'exercicio' => 2025, 'processo' => 'PROCESSO-ALVO', 'vigencia_fim_atual' => '2027-06-30']);
        $this->contract(['fund_id' => $fund, 'exercicio' => 2026, 'processo' => 'OUTRO', 'vigencia_fim_atual' => '2027-06-30']);
        $this->contract(['fund_id' => $other, 'exercicio' => 2025, 'processo' => 'PROCESSO-ALVO', 'vigencia_fim_atual' => '2027-06-30']);
        $this->contract(['fund_id' => $fund, 'exercicio' => 2025, 'processo' => 'PROCESSO-ALVO', 'vigencia_fim_atual' => '2027-07-01']);
        $this->actingAs($this->user())->withSession(['fund_id' => $fund])
            ->get('/reports?report_year=2026&report_process=PROCESSO-ALVO&report_supplier=INEXISTENTE&report_bidding=INEXISTENTE&report_bidding_number=INEXISTENTE&report_type=INEXISTENTE&report_current=1&report_end_from=2027-06-01&report_end_to=2027-06-30')
            ->assertOk()->assertSee('referente a todos os exercícios')
            ->assertViewHas('reportProcess', '')
            ->assertViewHas('reportType', '')
            ->assertViewHas('reportCurrent', false)
            ->assertViewHas('supplierFilters', fn ($filters) => array_values($filters) === ['', '', ''])
            ->assertViewHas('reportContracts', fn ($rows) => $rows->total() === 2 && $rows->contains('id', $match->id));
        $this->get('/reports?report_year=2026&report_process=PROCESSO-ALVO')->assertOk()
            ->assertViewHas('reportContracts', fn ($rows) => $rows->total() === 0);
    }

    public function test_list_filters_lock_scope_controls_and_preserve_the_selected_year(): void
    {
        $fund = $this->catalog('contract_funds', 'Fundo A');
        $this->contract(['fund_id' => $fund]);
        $this->actingAs($this->user())->withSession(['fund_id' => $fund]);
        foreach ([['report_process' => '123'], ['report_supplier' => 'Empresa'], ['report_bidding' => 'Pregão'],
            ['report_bidding_number' => '01'], ['report_type' => 'Obras']] as $filter) {
            $response = $this->get('/reports?'.http_build_query($filter + ['report_year' => 2025, 'report_fund' => $fund]));
            $response->assertOk()->assertViewHas('reportScopeLocked', true)
                ->assertSee('name="report_year" min="1" max="9999" value="2025" disabled', false)
                ->assertSee('name="report_fund" disabled', false)
                ->assertSee('name="report_department" x-ref="department" disabled', false)
                ->assertSee('type="hidden" name="report_year" value="2025"', false);
        }
        $this->get('/reports?report_year=2025&report_end_active=1')->assertOk()
            ->assertViewHas('reportYear', null)
            ->assertViewHas('reportScopeLocked', true)
            ->assertSee('name="report_year" min="1" max="9999" value="" disabled', false)
            ->assertDontSee('type="hidden" name="report_year"', false);
        $this->get('/reports')->assertOk()->assertViewHas('reportScopeLocked', false)
            ->assertViewHas('reportYear', 2026)
            ->assertDontSee('name="report_year" min="1" max="9999" value="2026" disabled', false)
            ->assertDontSee('name="report_fund" disabled', false);
    }

    public function test_report_current_option_selects_only_current_contracts(): void
    {
        $fund = $this->catalog('contract_funds', 'Fundo A');
        $current = $this->contract(['fund_id' => $fund]);
        $this->contract(['fund_id' => $fund, 'vigencia_fim_atual' => '2026-10-08']);
        $this->contract(['fund_id' => $fund, 'vigencia_inicio' => '2026-11-01']);
        $this->contract(['fund_id' => $fund, 'vigencia_em_revisao' => true]);
        $this->actingAs($this->user())->withSession(['fund_id' => $fund])
            ->get('/reports?report_current=1')->assertOk()
            ->assertViewHas('reportContracts', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $current->id)
            ->assertViewHas('reportScopeLocked', true)->assertSee('Somente contratos vigentes');
        $this->get('/reports?report_current=0')->assertOk()
            ->assertViewHas('reportContracts', fn ($rows) => $rows->total() === 4)
            ->assertViewHas('reportScopeLocked', false);
    }

    public function test_report_row_opens_summary_with_editing_available_only_in_summary(): void
    {
        $fund = $this->catalog('contract_funds', 'Fundo selecionado');
        $contract = $this->contract(['fund_id' => $fund]);

        $this->actingAs($this->user())->withSession(['fund_id' => $fund])
            ->get('/reports?report_process=PROCESSO-TESTE')->assertOk()
            ->assertDontSee('href="'.route('contracts.edit', $contract).'"', false)
            ->assertSee('href="'.route('contracts.show', $contract).'" aria-label="Resumo do contrato '.$contract->numero.'"', false)
            ->assertSee('data-row-href="'.route('contracts.show', $contract).'"', false);

        $this->get(route('contracts.edit', $contract))->assertOk()
            ->assertViewIs('contracts.form')->assertViewHas('contract', fn ($saved) => $saved->id === $contract->id)
            ->assertDontSee('id="report-filters"', false);
        $this->get(route('contracts.show', $contract))->assertOk()->assertViewIs('contracts.show')
            ->assertSee('href="'.route('contracts.edit', $contract).'"', false)->assertSee('Editar contrato')
            ->assertSee('data-contract-amendments-open aria-haspopup="dialog" aria-controls="contract-amendments-panel"', false)
            ->assertSee('id="contract-amendments-panel"', false)
            ->assertSee('name="contracts-amendments-panel"', false);
    }

    public function test_report_list_displays_updated_dates_accumulated_amount_and_only_latest_amendment(): void
    {
        $fund = $this->catalog('contract_funds', 'Fundo selecionado');
        $contract = $this->contract(['fund_id' => $fund, 'n_licit' => 'LIC-123', 'modalidade' => 'Pregão eletrônico',
            'n_aditivo' => 'RESUMO-ANTIGO', 'vigencia_fim_atual' => '2027-06-30', 'valor_acumulado' => '2345.6700']);
        $user = $this->user();
        foreach (['ANTIGO' => '2026-01-01', 'ULTIMO' => '2026-10-01', 'EXCLUIDO' => '2026-11-01'] as $number => $date) {
            $amendment = ContractAmendment::create(['contract_id' => $contract->id,
                'number' => $number, 'event_date' => $date, 'payload' => ['Proc_ADT' => 'PROC-'.$number],
                'created_by' => $user->id, 'updated_by' => $user->id]);
            if ($number === 'EXCLUIDO') {
                $amendment->delete();
            }
        }
        $this->actingAs($user)->withSession(['fund_id' => $fund])->get('/reports')->assertOk()
            ->assertSeeInOrder(['Pregão eletrônico nº LIC-123', 'Fornecedor fictício', 'Aditivo ULTIMO', 'Processo PROC-ULTIMO', '01/01/2026', '30/06/2027', 'R$ 2.345,67'])
            ->assertDontSee('PROC-ANTIGO')->assertDontSee('PROC-EXCLUIDO')
            ->assertDontSee('Aditivo ANTIGO')->assertDontSee('Aditivo EXCLUIDO')
            ->assertDontSee('RESUMO-ANTIGO')->assertDontSee('31/12/2026');

        ContractAmendment::where('contract_id', $contract->id)->delete();
        $contract->update(['n_aditivo' => '0', 'valor_acumulado' => null, 'vigencia_fim_atual' => null]);
        $this->get('/reports')->assertOk()->assertDontSee('Aditivo 0')
            ->assertDontSee('R$ 1.908,63')->assertDontSee('31/12/2026')->assertSee('Não informado');
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

    public function test_report_pagination_navigates_forward_and_back_with_final_filter(): void
    {
        $fund = $this->catalog('contract_funds', 'Fundo paginação');
        for ($i = 1; $i <= 45; $i++) {
            $this->contract(['fund_id' => $fund, 'numero' => sprintf('%03d/25', $i), 'exercicio' => 2025, 'vigencia_fim_atual' => '2024-12-31']);
        }
        $this->actingAs($this->user())->withSession(['fund_id' => $fund]);
        foreach ([1, 2, 3, 2, 1] as $page) {
            $response = $this->get('/reports?report_year=2026&report_end_to=2024-12-31&page='.$page)->assertOk();
            $response->assertViewHas('reportContracts', fn ($rows) => $rows->currentPage() === $page && $rows->total() === 45 && $rows->count() === ($page === 3 ? 5 : 20))
                ->assertSee('aria-current="page" aria-label="Página '.$page.'"', false);
            if ($page < 3) {
                $response->assertSee('href="'.url('/reports').'?report_end_to=2024-12-31&amp;page='.($page + 1).'"', false);
            }
        }
    }

    public function test_report_pagination_preserves_all_years_and_all_funds_when_following_links(): void
    {
        $fund = $this->catalog('contract_funds', 'Fundo paginação');
        for ($i = 1; $i <= 21; $i++) {
            $this->contract(['fund_id' => $fund, 'exercicio' => 2025]);
        }
        $this->actingAs($this->user())->withSession(['fund_id' => $fund]);
        $response = $this->get('/reports?report_year=&report_fund=')->assertOk();
        $next = $response->viewData('reportContracts')->nextPageUrl();
        $this->get($next)->assertOk()->assertViewHas('reportYear', null)->assertViewHas('reportFundId', null)
            ->assertViewHas('reportContracts', fn ($rows) => $rows->currentPage() === 2 && $rows->total() === 21 && $rows->count() === 1);
    }

    public function test_report_out_of_range_page_returns_to_last_page_with_filters_and_working_previous_link(): void
    {
        $fund = $this->catalog('contract_funds', 'Fundo paginação');
        for ($i = 1; $i <= 21; $i++) {
            $this->contract(['fund_id' => $fund, 'exercicio' => 2025]);
        }
        $this->actingAs($this->user())->withSession(['fund_id' => $fund]);
        $response = $this->get('/reports?report_year=2025&report_fund='.$fund.'&page=3')->assertRedirect();
        $last = $this->get($response->headers->get('Location'))->assertOk()
            ->assertViewHas('reportYear', 2025)->assertViewHas('reportFundId', (string) $fund)
            ->assertViewHas('reportContracts', fn ($rows) => $rows->currentPage() === 2 && $rows->count() === 1);
        $this->get($last->viewData('reportContracts')->previousPageUrl())->assertOk()
            ->assertViewHas('reportYear', 2025)->assertViewHas('reportFundId', (string) $fund)
            ->assertViewHas('reportContracts', fn ($rows) => $rows->currentPage() === 1 && $rows->count() === 20);
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
