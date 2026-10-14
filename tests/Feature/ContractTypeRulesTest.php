<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractTypeRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContractTypeRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $fund;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'TestPassword12345', 'user_type' => 'Administrador']);
        $this->fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo', 'name_key' => 'FUNDO', 'raw_payload' => '{}']);
        DB::table('suppliers')->insert(['name' => 'Fornecedor', 'name_key' => 'FORNECEDOR', 'document' => '11222333000181', 'document_key' => '11222333000181', 'raw_payload' => '{}']);
        $this->actingAs($this->admin);
        $this->withSession(['fund_id' => $this->fund]);
    }

    private function data(array $extra = []): array
    {
        return array_replace(['numero' => 'TIPO/26', 'processo' => 'Processo', 'fornecedor_nome_snapshot' => 'Fornecedor',
            'fornecedor_documento_snapshot' => '11222333000181', 'objeto' => 'Objeto de teste', 'tipo' => 'Locação',
            'data_contrato' => '2026-10-10', 'vigencia_inicio' => '2026-10-10', 'vigencia_fim_original' => '2027-10-10',
            'modalidade' => 'Pregão', 'n_licit' => '001/2026', 'valor_total' => '1908.6300', 'fund_id' => $this->fund], $extra);
    }

    public function test_configured_requirements_apply_on_creation_and_type_change_without_retroactively_blocking_history(): void
    {
        $this->post('/contract-types', ['name' => 'Locação', 'required_fields' => ['end_imovel'], 'reason' => 'Endereço exigido para identificar imóvel'])->assertRedirect('/contract-types');
        $this->assertDatabaseHas('activity_events', ['action' => 'contract_type_rule_updated', 'actor_id' => $this->admin->id]);
        $this->get('/contract-types')->assertOk()->assertSee('Locação');
        $this->get('/contracts/create')->assertOk()->assertSee('Campos específicos por tipo');
        $this->post('/contracts', $this->data())->assertSessionHasErrors('end_imovel');
        $this->post('/contracts', $this->data(['end_imovel' => 'Imóvel de teste']))->assertRedirect();
        $contract = Contract::sole();
        $contract->update(['tipo' => 'Outro', 'end_imovel' => null]);
        $this->put('/contracts/'.$contract->id, ['version' => 1, 'tipo' => 'LOCAÇÃO', 'reason' => 'Alteração do tipo de instrumento'])->assertSessionHasErrors('end_imovel');
        $this->put('/contracts/'.$contract->id, ['version' => 1, 'tipo' => 'LOCAÇÃO', 'end_imovel' => 'Endereço confirmado', 'reason' => 'Alteração do tipo de instrumento'])->assertRedirect();
        $contract->update(['end_imovel' => null]);
        $this->put('/contracts/'.$contract->id, ['version' => 2, 'objeto' => 'Atualização compatível com registro histórico', 'reason' => 'Correção do objeto histórico'])->assertRedirect();
        $this->post('/contract-types', ['name' => 'LOCACAO', 'reason' => 'Retirada dos requisitos adicionais'])->assertRedirect();
        $this->assertDatabaseCount('contract_type_rules', 1);
        $this->assertSame([], ContractTypeRule::sole()->required_fields);
    }

    public function test_rules_reject_unknown_fields_and_non_admin_and_validate_cpfs_without_losing_legacy_values(): void
    {
        $this->post('/contract-types', ['name' => 'Teste', 'required_fields' => ['user_type'], 'reason' => 'Tentativa com campo inválido'])->assertSessionHasErrors('required_fields.0');
        $regular = User::create(['name' => 'Regular', 'username' => 'REGULAR', 'password' => 'TestPassword12345']);
        $this->flushSession();
        $this->actingAs($regular)->get('/contract-types')->assertForbidden();
        $this->post('/contract-types', ['name' => 'Teste', 'reason' => 'Tentativa de alterar requisitos'])->assertForbidden();
        $this->actingAs($this->admin);
        $this->withSession(['fund_id' => $this->fund]);
        $this->post('/contracts', $this->data(['cpf_gestor' => '11111111111', 'cpf_fiscal' => '11222333000181']))->assertSessionHasErrors(['cpf_gestor', 'cpf_fiscal']);
        $this->post('/contracts', $this->data(['cpf_gestor' => '529.982.247-25']))->assertRedirect();
        $contract = Contract::sole();
        $contract->update(['cpf_gestor' => '11111111111']);
        $this->put('/contracts/'.$contract->id, ['version' => 1, 'cpf_gestor' => '11111111111', 'reason' => 'Manter documento de origem histórica'])->assertRedirect();
        $this->assertSame('11111111111', $contract->fresh()->cpf_gestor);
        $this->put('/contracts/'.$contract->id, ['version' => 2, 'cpf_gestor' => '22222222222', 'reason' => 'Novo documento inválido'])->assertSessionHasErrors('cpf_gestor');
    }
}
