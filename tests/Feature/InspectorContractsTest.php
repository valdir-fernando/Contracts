<?php

namespace Tests\Feature;

use App\Models\AccessScope;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InspectorContractsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $fund;

    private int $department;

    private int $inspector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 3)->startOfDay());
        $this->withoutVite();
        $this->admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'PasswordTest123', 'user_type' => 'Administrador']);
        $this->fund = DB::table('contract_funds')->insertGetId(['name' => 'MUNICIPIO DE NIQUELÂNDIA', 'name_key' => 'MUNICIPIO DE NIQUELANDIA', 'raw_payload' => '{}']);
        $this->department = DB::table('contract_departments')->insertGetId(['name' => 'Educação', 'name_key' => 'EDUCACAO', 'raw_payload' => '{}']);
        $this->inspector = DB::table('contract_inspectors')->insertGetId(['name' => 'FISCAL TESTE', 'name_key' => 'FISCAL TESTE',
            'fund_name_key' => 'MUNICIPIO DE NIQUELANDIA', 'department_name_key' => 'EDUCACAO',
            'raw_payload' => json_encode(['CPF_Fiscal' => '529.982.247-25', 'Decreto' => '144/26'])]);
        $this->actingAs($this->admin);
    }

    private function contract(array $attributes = []): Contract
    {
        return Contract::create(array_replace(['numero' => '001/26', 'nome_fiscal' => 'FISCAL TESTE',
            'fund_id' => $this->fund, 'department_id' => $this->department, 'ownership_resolved' => true,
            'fundo_nome_snapshot' => 'MUNICIPIO DE NIQUELÂNDIA', 'secretaria_nome_snapshot' => 'Educação',
            'fornecedor_nome_snapshot' => 'Fornecedor teste', 'tipo' => 'Serviços Diversos', 'objeto' => 'Objeto teste',
            'vigencia_fim_atual' => '2027-06-30', 'quality_issues' => []], $attributes));
    }

    public function test_list_projects_columns_and_latest_amendment_with_private_response(): void
    {
        $contract = $this->contract(['n_aditivo' => 'LEGADO', 'cpf_fiscal' => '52998224725', 'rg_fiscal' => 'SEGREDO-RG']);
        ContractAmendment::create(['contract_id' => $contract->id, 'number' => '01/26', 'event_date' => '2026-01-01', 'payload' => [], 'status' => 'registrado']);
        ContractAmendment::create(['contract_id' => $contract->id, 'number' => '02/26', 'event_date' => '2026-02-01', 'payload' => [], 'status' => 'registrado']);
        $response = $this->get('/inspectors/'.$this->inspector.'/contracts')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSeeInOrder(['Contrato', 'Tipo_Cont', 'Fornecedor', 'Fundo &amp; Secretaria', 'Objeto &amp; Aditivo', 'Final'], false)
            ->assertSeeInOrder(['001/26', 'Serviços Diversos', 'Fornecedor teste', 'MUNICIPIO DE NIQUELÂNDIA', 'Educação', 'Objeto teste', 'Aditivo: 02/26', '30/06/2027'])
            ->assertDontSee('SEGREDO-RG')->assertDontSee('52998224725')->assertDontSee('LEGADO');
        $this->assertStringContainsString('<span class="inspector-contracts-detail">Educação</span>', $response->getContent());
        $this->get('/catalogs/list/inspector?edit='.$this->inspector)->assertOk()
            ->assertSee('aria-controls="inspector-contracts-dialog"', false)
            ->assertSee('Lista de contratos do fiscal');
    }

    public function test_identity_organization_and_soft_delete_exclude_other_contracts(): void
    {
        $this->contract(['numero' => 'INCLUIDO']);
        $this->contract(['numero' => 'OUTRO-FISCAL', 'nome_fiscal' => 'OUTRO FISCAL']);
        $this->contract(['numero' => 'OUTRA-PESSOA', 'cpf_fiscal' => '11111111111']);
        $this->contract(['numero' => 'OUTRA-SECRETARIA', 'department_id' => null]);
        $this->contract(['numero' => 'OUTRO-FUNDO', 'fund_id' => null]);
        $this->contract(['numero' => 'EXCLUIDO'])->delete();
        $this->get('/inspectors/'.$this->inspector.'/contracts')->assertOk()->assertSee('INCLUIDO')
            ->assertDontSee('OUTRO-FISCAL')->assertDontSee('OUTRA-PESSOA')->assertDontSee('OUTRA-SECRETARIA')
            ->assertDontSee('OUTRO-FUNDO')->assertDontSee('EXCLUIDO')->assertDontSee('Aditivo:');
    }

    public function test_non_municipal_fund_hides_department_and_escapes_content(): void
    {
        DB::table('contract_funds')->where('id', $this->fund)->update(['name' => 'Fundo Saúde', 'name_key' => 'FUNDO SAUDE']);
        DB::table('contract_inspectors')->where('id', $this->inspector)->update(['fund_name_key' => 'FUNDO SAUDE']);
        $this->contract(['fundo_nome_snapshot' => 'Fundo Saúde', 'secretaria_nome_snapshot' => 'SECRETARIA-OCULTA',
            'objeto' => '<script>alert(1)</script>', 'n_aditivo' => '03/26']);
        $this->get('/inspectors/'.$this->inspector.'/contracts')->assertOk()->assertSee('Fundo Saúde')
            ->assertDontSee('SECRETARIA-OCULTA')->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('Aditivo: 03/26');
    }

    public function test_access_requires_catalog_permission_and_current_scopes(): void
    {
        $this->contract(['numero' => 'VISIVEL']);
        $this->contract(['numero' => 'NAO-RESOLVIDO', 'ownership_resolved' => false]);
        $user = User::create(['name' => 'User', 'username' => 'USER', 'password' => 'PasswordTest123']);
        $this->actingAs($user)->get('/inspectors/'.$this->inspector.'/contracts')->assertForbidden();
        $user->catalog_permissions = ['inspector'];
        $user->save();
        $scope = AccessScope::create(['scope_key' => 'municipio-educacao', 'fund' => 'MUNICIPIO DE NIQUELÂNDIA',
            'department' => 'Educação', 'fund_id' => $this->fund, 'department_id' => $this->department]);
        $user->scopes()->attach($scope);
        $this->actingAs($user->fresh())->get('/inspectors/'.$this->inspector.'/contracts')->assertOk()
            ->assertSee('VISIVEL')->assertDontSee('NAO-RESOLVIDO');
        $user->scopes()->detach();
        $this->get('/inspectors/'.$this->inspector.'/contracts')->assertNotFound();
        $this->actingAs($this->admin)->get('/inspectors/999999/contracts')->assertNotFound();
        DB::table('contract_inspectors')->where('id', $this->inspector)->update(['deleted_at' => now()]);
        $this->get('/inspectors/'.$this->inspector.'/contracts')->assertNotFound();
    }

    public function test_empty_state_and_pagination(): void
    {
        $url = '/inspectors/'.$this->inspector.'/contracts';
        $this->get($url)->assertOk()->assertSee('Nenhum contrato disponível para este fiscal.');
        for ($i = 1; $i <= 26; $i++) {
            $this->contract(['numero' => sprintf('%03d/26', $i)]);
            $this->contract(['numero' => sprintf('V-%03d/26', $i), 'vigencia_fim_atual' => '2026-11-02']);
        }
        $this->get($url)->assertOk()->assertSee('025/26')->assertDontSee('026/26');
        $this->get($url.'?page=2')->assertOk()->assertSee('026/26')->assertDontSee('025/26');
        $this->get($url.'?status=vencido')->assertOk()->assertSee('V-025/26')->assertDontSee('V-026/26')
            ->assertSee('status=vencido&amp;page=2', false);
        $this->get($url.'?status=vencido&page=2')->assertOk()->assertSee('V-026/26')->assertDontSee('V-025/26');
    }

    public function test_default_current_and_expired_filter_follow_updated_end_date(): void
    {
        $this->contract(['numero' => 'VIGENTE-TESTE', 'vigencia_fim_atual' => '2026-11-04', 'vigencia_fim_original' => '2020-01-01']);
        $this->contract(['numero' => 'VENCIDO-TESTE', 'vigencia_fim_atual' => '2026-11-02']);
        $this->contract(['numero' => 'VENCE-HOJE', 'vigencia_fim_atual' => '2026-11-03']);
        $this->contract(['numero' => 'SEM-TERMINO', 'vigencia_fim_atual' => null]);
        $url = '/inspectors/'.$this->inspector.'/contracts';
        $this->get($url)->assertOk()->assertSee('value="vigente" checked', false)
            ->assertSee('VIGENTE-TESTE')->assertDontSee('VENCIDO-TESTE')->assertDontSee('VENCE-HOJE')->assertDontSee('SEM-TERMINO');
        $this->get($url.'?status=vencido')->assertOk()->assertSee('value="vencido" checked', false)
            ->assertSee('VENCIDO-TESTE')->assertSee('VENCE-HOJE')->assertDontSee('VIGENTE-TESTE')->assertDontSee('SEM-TERMINO');
        $this->get($url.'?status=vigente')->assertOk()->assertSee('VIGENTE-TESTE')->assertDontSee('VENCIDO-TESTE');
        $this->getJson($url.'?status=todos')->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->getJson($url.'?status[]=vigente')->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    private function history(Contract $contract, string $text = '15/01/2026 - Serviços Diversos - Pregão 010/26 - 100/26 - OBJETO: Objeto salvo - complemento'): void
    {
        DB::table('tb_Historico')->insert(['Contrato' => $contract->numero, 'Fundo' => $contract->fundo_nome_snapshot,
            'Fiscal' => "FISCAL TESTE\n144/26", 'Historico' => $text]);
    }

    public function test_load_link_opens_diary_with_history_date_separated_and_escaped_text(): void
    {
        $contract = $this->contract(['data_contrato' => '2026-02-01', 'objeto' => 'Objeto atual diferente']);
        $this->history($contract);
        $this->history($contract, '16/01/2026 - Registro <script>segredo</script>');
        $other = $this->contract(['numero' => '002/26']);
        $this->history($other, 'HISTORICO-OUTRO-CONTRATO');
        $url = route('catalogs.list', ['kind' => 'inspector', 'edit' => $this->inspector, 'diary_contract' => $contract->id]);
        $this->get('/inspectors/'.$this->inspector.'/contracts')->assertOk()->assertSee(e($url), false)
            ->assertSee('target="_top"', false);
        $response = $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertSeeInOrder(['Contratos do Fiscal</button>', 'class="section-tab inspector-history-print"', 'Imprimir Histórico</span>'], false)
            ->assertSee('@click="window.print()"', false)
            ->assertSeeInOrder(['<strong>Fiscal:</strong> FISCAL TESTE', '<strong>Decreto:</strong> 144/26', '<strong>Fundo:</strong> MUNICIPIO DE NIQUELÂNDIA', '<strong>Secretaria:</strong> Educação', 'aria-label="Histórico do diário do fiscal"'], false)
            ->assertSee('step: 3', false)->assertSee('15/01/2026')->assertSee('16/01/2026')
            ->assertSee('Tipo do contrato: Serviços Diversos')->assertSee('Licitação: Pregão 010/26')
            ->assertSee('Processo: 100/26')->assertSee('OBJETO: Objeto salvo - complemento')
            ->assertSee('Registro &lt;script&gt;segredo&lt;/script&gt;', false)
            ->assertDontSee('<script>segredo</script>', false)->assertDontSee('HISTORICO-OUTRO-CONTRATO');
        $diary = $response->viewData('diary');
        $this->assertCount(2, $diary);
        $this->assertSame('15/01/2026', $diary[0]['date']);
        $this->assertStringNotContainsString('15/01/2026', $diary[0]['text']);
        $this->assertStringNotContainsString('001/26', $diary[0]['text']);
        $this->assertSame('144/26', $diary[0]['decree']);
    }

    public function test_history_rejects_other_inspectors_departments_deleted_and_ambiguous_contracts(): void
    {
        $base = '/catalogs/list/inspector?edit='.$this->inspector.'&diary_contract=';
        foreach ([['nome_fiscal' => 'OUTRO'], ['department_id' => null], ['fund_id' => null]] as $attributes) {
            $contract = $this->contract($attributes);
            $this->history($contract, 'HISTORICO-PRIVADO');
            $this->get($base.$contract->id)->assertNotFound()->assertDontSee('HISTORICO-PRIVADO');
        }
        $deleted = $this->contract(['numero' => 'EXCLUIDO']);
        $deleted->delete();
        $this->get($base.$deleted->id)->assertNotFound();
        $valid = $this->contract(['numero' => 'DUPLICADO']);
        $this->contract(['numero' => 'DUPLICADO', 'department_id' => null]);
        $this->get($base.$valid->id)->assertConflict();
        $this->get($base.'999999')->assertNotFound();
        $this->getJson($base.'invalid')->assertUnprocessable();
    }

    public function test_diary_empty_state_and_revoked_scopes_are_enforced_on_every_load(): void
    {
        $contract = $this->contract();
        $url = '/catalogs/list/inspector?edit='.$this->inspector.'&diary_contract='.$contract->id;
        $this->get($url)->assertOk()->assertSee('Nenhum histórico registrado para este contrato.')
            ->assertSee('aria-label="Imprimir Histórico" disabled', false);
        $user = User::create(['name' => 'User', 'username' => 'DIARY', 'password' => 'PasswordTest123']);
        $user->catalog_permissions = ['inspector'];
        $user->save();
        $scope = AccessScope::create(['scope_key' => 'diary', 'fund' => 'MUNICIPIO DE NIQUELÂNDIA',
            'department' => 'Educação', 'fund_id' => $this->fund, 'department_id' => $this->department]);
        $user->scopes()->attach($scope);
        $this->actingAs($user)->get($url)->assertOk();
        $user->scopes()->detach();
        $this->get($url)->assertNotFound();
        $user->catalog_permissions = [];
        $user->save();
        $this->actingAs($user->fresh())->get($url)->assertForbidden();
    }
}
