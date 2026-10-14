<?php

namespace Tests\Feature;

use App\Models\AccessScope;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierAutofillTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $user;

    private int $fund;

    private int $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'SyntheticPassword123', 'user_type' => 'Administrador']);
        $this->user = User::create(['name' => 'Regular', 'username' => 'REGULAR', 'password' => 'SyntheticPassword123']);
        $this->fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo', 'name_key' => 'FUNDO', 'raw_payload' => '{}']);
        $scope = AccessScope::create(['scope_key' => 'supplier-autofill', 'fund' => 'Fundo', 'fund_id' => $this->fund]);
        $this->user->scopes()->attach($scope);
        $this->supplier = $this->supplier('Empresa cadastrada', '11222333000181');
        Contract::create(['supplier_id' => $this->supplier, 'fund_id' => $this->fund, 'ownership_resolved' => true, 'quality_issues' => []]);
        $this->actingAs($this->admin);
    }

    private function supplier(string $name, string $document, array $raw = []): int
    {
        return DB::table('suppliers')->insertGetId(['name' => $name, 'name_key' => strtoupper($name), 'document' => $document,
            'document_key' => $document, 'raw_payload' => json_encode(array_replace(['RG' => 'RG-FORNECEDOR', 'Doc_Prof' => 'Registro profissional',
                'PIS_PASEP' => 'PIS-PRIVATE', 'Endereco' => "Rua da Empresa\nCentro", 'Nome_Socio' => 'Representante cadastrado',
                'CPF_Socio' => '529.982.247-25', 'RG_Socio' => 'RG-SOCIO', 'Email_Fornecedor' => 'UNEXPOSED EMAIL',
                'Data_Nasc' => 'UNEXPOSED BIRTH', 'VL_Pensao' => 'UNEXPOSED MONEY'], $raw), JSON_THROW_ON_ERROR)]);
    }

    public function test_lookup_returns_explicit_complete_supplier_projection_for_admin(): void
    {
        $this->postJson('/suppliers/lookup', ['document' => '11.222.333/0001-81'])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertExactJson(['values' => [
                'fornecedor_nome_snapshot' => 'Empresa cadastrada', 'fornecedor_documento_snapshot' => '11222333000181',
                'rg_fornec' => 'RG-FORNECEDOR', 'doc_prof' => 'Registro profissional', 'pis_pasep' => 'PIS-PRIVATE',
                'end_fornec' => "Rua da Empresa\nCentro", 'socio' => 'Representante cadastrado', 'cpf_socio' => '52998224725', 'rg_socio' => 'RG-SOCIO',
            ]])->assertDontSee('UNEXPOSED');
        $person = $this->supplier('Pessoa cadastrada', '52998224725');
        $this->postJson('/suppliers/lookup', ['document' => '529.982.247-25'])->assertOk()->assertJsonPath('values.fornecedor_nome_snapshot', 'Pessoa cadastrada');
        $this->get('/contracts/create?supplier='.$person)->assertOk()->assertViewHas('values', fn ($values) => $values['fornecedor_documento_snapshot'] === '52998224725');
    }

    public function test_catalog_selection_uses_same_defaults_and_preserves_fund_and_old_input(): void
    {
        $values = $this->postJson('/suppliers/lookup', ['document' => '11222333000181'])->assertOk()->json('values');
        $this->withSession(['fund_id' => $this->fund])->get('/contracts/create?supplier='.$this->supplier)->assertOk()
            ->assertViewHas('values', fn ($selected) => $selected === ['fund_id' => $this->fund, 'fundo_nome_snapshot' => 'Fundo',
                'fundo_documento_snapshot' => null, 'end_fundo' => null, 'nome_gestor' => null, 'cargo_gestor' => null,
                'cpf_gestor' => null, 'rg_gestor' => null] + $values)
            ->assertSee('data-supplier-lookup-url', false)->assertDontSee('UNEXPOSED');
        $this->withSession(['_old_input' => ['socio' => 'Representante digitado']])->get('/contracts/create?supplier='.$this->supplier)
            ->assertOk()->assertSee('value="Representante digitado"', false);
    }

    public function test_regular_user_only_gets_visible_company_and_non_personal_fields_in_both_paths(): void
    {
        $this->actingAs($this->user);
        $response = $this->postJson('/suppliers/lookup', ['document' => '11222333000181'])->assertOk()
            ->assertJsonPath('values.end_fornec', "Rua da Empresa\nCentro")->assertJsonPath('values.socio', 'Representante cadastrado');
        foreach (['rg_fornec', 'pis_pasep', 'cpf_socio', 'rg_socio'] as $field) {
            $response->assertJsonMissingPath('values.'.$field);
        }
        $this->get('/contracts/create?supplier='.$this->supplier)->assertOk()->assertViewHas('values', fn ($values) => $values === $response->json('values'))
            ->assertDontSee('RG-FORNECEDOR')->assertDontSee('PIS-PRIVATE')->assertDontSee('52998224725')->assertDontSee('RG-SOCIO')->assertDontSee('UNEXPOSED');
        $hidden = $this->supplier('Empresa restrita', '19131243000197');
        $person = $this->supplier('Pessoa restrita', '52998224725');
        Contract::create(['supplier_id' => $person, 'fund_id' => $this->fund, 'ownership_resolved' => true, 'quality_issues' => []]);
        foreach (['19131243000197', '52998224725'] as $document) {
            $this->postJson('/suppliers/lookup', ['document' => $document])->assertNotFound()->assertDontSee('RG-FORNECEDOR');
        }
        foreach ([$hidden, $person] as $supplier) {
            $this->get('/contracts/create?supplier='.$supplier)->assertNotFound();
        }
    }

    public function test_lookup_rejects_invalid_missing_and_ambiguous_documents_without_guessing(): void
    {
        foreach (['123', '11111111111', '11222333000181<script>', str_repeat('1', 33)] as $document) {
            $this->postJson('/suppliers/lookup', ['document' => $document])->assertUnprocessable()->assertJsonValidationErrors('document');
        }
        $this->postJson('/suppliers/lookup', ['document' => '19131243000197'])->assertNotFound();
        $duplicate = $this->supplier('Empresa duplicada', '11222333000181');
        $this->postJson('/suppliers/lookup', ['document' => '11222333000181'])->assertConflict()->assertDontSee('Empresa cadastrada')->assertDontSee('Empresa duplicada');
        $this->get('/contracts/create?supplier='.$duplicate)->assertOk()->assertViewHas('values', fn ($values) => $values['fornecedor_nome_snapshot'] === 'Empresa duplicada');
    }

    public function test_lookup_reauthorizes_current_role_and_revoked_supplier_scope(): void
    {
        $scope = $this->user->scopes()->first();
        $this->admin->scopes()->attach($scope);
        DB::table('users')->where('id', $this->admin->id)->update(['user_type' => 'Usuario']);
        $this->postJson('/suppliers/lookup', ['document' => '11222333000181'])->assertOk()->assertJsonMissingPath('values.cpf_socio');
        $this->get('/contracts/create?supplier='.$this->supplier)->assertOk()->assertDontSee('52998224725')->assertDontSee('RG-SOCIO');
        $this->actingAs($this->user);
        $this->user->scopes()->detach();
        $this->postJson('/suppliers/lookup', ['document' => '11222333000181'])->assertForbidden();
        $this->post('/logout');
        $this->postJson('/suppliers/lookup', ['document' => '11222333000181'])->assertUnauthorized();
    }

    public function test_autofilled_fields_are_validated_persisted_and_audited_normally(): void
    {
        $values = $this->postJson('/suppliers/lookup', ['document' => '11222333000181'])->assertOk()->json('values');
        $this->post('/contracts', $values + ['fund_id' => $this->fund, 'numero' => 'AUTOFILL/26', 'processo' => 'PROC-AUTOFILL',
            'objeto' => 'Objeto sintético', 'tipo' => 'Serviço', 'valor_total' => '100.0000', 'data_contrato' => '2026-10-13',
            'vigencia_inicio' => '2026-10-13', 'vigencia_fim_original' => '2027-10-13'])->assertRedirect();
        $contract = Contract::where('processo', 'PROC-AUTOFILL')->firstOrFail();
        foreach ($values as $field => $value) {
            $this->assertSame($value, $contract->$field);
        }
        $this->assertSame($this->supplier, $contract->supplier_id);
        $this->assertDatabaseHas('contract_audits', ['contract_id' => $contract->id, 'actor_id' => $this->admin->id, 'action' => 'created']);
        $this->get('/contracts/'.$contract->id.'/edit')->assertOk()->assertDontSee('data-supplier-lookup-url', false);
    }
}
