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
}
