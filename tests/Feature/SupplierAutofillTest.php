<?php

namespace Tests\Feature;

use App\Models\AccessScope;
use App\Models\Contract;
use App\Models\User;
use App\Support\ContractFields;
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
        $this->withSession(['fund_id' => $this->fund]);
    }

    private function supplier(string $name, string $document, array $raw = []): int
    {
        return DB::table('suppliers')->insertGetId(['name' => $name, 'name_key' => strtoupper($name), 'document' => $document,
            'document_key' => $document, 'raw_payload' => json_encode(array_replace(['RG' => 'RG-FORNECEDOR', 'Doc_Prof' => 'Registro profissional',
                'PIS_PASEP' => 'PIS-PRIVATE', 'Endereco' => "Rua da Empresa\nCentro", 'Nome_Socio' => 'Representante cadastrado',
                'CPF_Socio' => '529.982.247-25', 'RG_Socio' => 'RG-SOCIO', 'Email_Fornecedor' => 'UNEXPOSED EMAIL',
                'Data_Nasc' => 'UNEXPOSED BIRTH', 'VL_Pensao' => 'UNEXPOSED MONEY'], $raw), JSON_THROW_ON_ERROR)]);
    }

    private function contractInput(array $extra = []): array
    {
        return array_replace(['fund_id' => $this->fund, 'processo' => 'CATALOG-ONLY', 'fornecedor_documento_snapshot' => '11222333000181',
            'modalidade' => 'Pregão', 'n_licit' => '001/2026', 'objeto' => 'Objeto sintético', 'tipo' => 'Serviço', 'valor_total' => '100.0000',
            'data_contrato' => '2026-10-13', 'vigencia_inicio' => '2026-10-13', 'vigencia_fim_original' => '2027-10-13'], $extra);
    }

    public function test_supplier_controls_are_readonly_and_never_restore_client_values(): void
    {
        $response = $this->withSession(['_old_input' => ['fornecedor_documento_snapshot' => '11222333000181', 'socio' => 'FORGED-SUPPLIER']])
            ->get('/contracts/create')->assertOk()->assertDontSee('FORGED-SUPPLIER');
        $html = new \DOMDocument;
        @$html->loadHTML($response->getContent());
        foreach (ContractFields::SUPPLIER_CATALOG as $column) {
            $control = $html->getElementById($column);
            $this->assertNotNull($control);
            $this->assertTrue($control->hasAttribute('readonly'), $column);
            $this->assertTrue($control->hasAttribute('data-supplier-catalog-field'), $column);
        }
    }

    public function test_creation_uses_current_database_values_even_when_client_forces_all_readonly_fields(): void
    {
        $values = $this->postJson('/suppliers/lookup', ['document' => '11222333000181'])->json('values');
        DB::table('suppliers')->where('id', $this->supplier)->update(['name' => 'Nome atualizado no cadastro']);
        $forced = array_fill_keys(ContractFields::SUPPLIER_CATALOG, 'FORGED-SUPPLIER');
        $this->post('/contracts', $this->contractInput($forced + ['_catalog_supplier_id' => 999]))->assertRedirect()->assertSessionHasNoErrors();
        $contract = Contract::where('processo', 'CATALOG-ONLY')->firstOrFail();
        $this->assertSame('Nome atualizado no cadastro', $contract->fornecedor_nome_snapshot);
        foreach (ContractFields::SUPPLIER_CATALOG as $column) {
            if ($column !== 'fornecedor_nome_snapshot') {
                $this->assertSame($values[$column], $contract->$column, $column);
            }
        }
        $this->assertSame($this->supplier, $contract->supplier_id);
        $this->assertDatabaseHas('contract_audits', ['contract_id' => $contract->id, 'action' => 'created']);
    }

    public function test_missing_ambiguous_inaccessible_and_mismatched_suppliers_cannot_be_saved(): void
    {
        $hidden = $this->supplier('Outro cadastro', '19131243000197');
        $this->post('/contracts', $this->contractInput(['supplier_id' => $hidden]))->assertSessionHasErrors('fornecedor_documento_snapshot');
        $this->post('/contracts', $this->contractInput(['fornecedor_documento_snapshot' => '19131243000197', 'supplier_id' => $this->supplier]))->assertSessionHasErrors('fornecedor_documento_snapshot');
        $this->supplier('Duplicado', '11222333000181');
        $this->post('/contracts', $this->contractInput())->assertSessionHasErrors('fornecedor_documento_snapshot');
        $this->actingAs($this->user)->post('/contracts', $this->contractInput(['fornecedor_documento_snapshot' => '19131243000197', 'supplier_id' => $hidden]))
            ->assertSessionHasErrors('fornecedor_documento_snapshot');
        $this->assertDatabaseCount('contract_audits', 0);
        $this->assertDatabaseCount('tb_N_Contrato', 0);
        $this->assertDatabaseMissing('contracts', ['processo' => 'CATALOG-ONLY']);
        $this->actingAs($this->admin);
        DB::table('suppliers')->where('document_key', '19131243000197')->delete();
        $this->post('/contracts', $this->contractInput(['fornecedor_documento_snapshot' => '19131243000197']))->assertSessionHasErrors('fornecedor_documento_snapshot');
    }

    public function test_explicit_duplicate_selection_remains_linked_and_same_document_preserves_saved_snapshot(): void
    {
        $duplicate = $this->supplier('Cadastro escolhido', '11222333000181');
        $this->post('/contracts', $this->contractInput(['supplier_id' => $duplicate]))->assertRedirect()->assertSessionHasNoErrors();
        $contract = Contract::where('processo', 'CATALOG-ONLY')->firstOrFail();
        DB::table('suppliers')->where('id', $duplicate)->update(['name' => 'Nome posterior']);
        $this->put('/contracts/'.$contract->id, ['version' => 1, 'reason' => 'Correção de descrição', 'fornecedor_documento_snapshot' => '11.222.333/0001-81',
            'fornecedor_nome_snapshot' => 'FORGED-SUPPLIER', 'end_fornec' => 'FORGED-ADDRESS', 'supplier_id' => $this->supplier])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Cadastro escolhido', $contract->fresh()->fornecedor_nome_snapshot);
        $this->assertSame($duplicate, $contract->fresh()->supplier_id);
        $this->assertSame("Rua da Empresa\nCentro", $contract->fresh()->end_fornec);
        $this->actingAs($this->user)->put('/contracts/'.$contract->id, ['version' => 2, 'reason' => 'Correção sem trocar fornecedor',
            'objeto' => 'Objeto corrigido', 'cpf_socio' => 'FORGED-CPF'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('52998224725', $contract->fresh()->cpf_socio);
        $this->assertSame('RG-FORNECEDOR', $contract->fresh()->rg_fornec);
    }

    public function test_changing_document_uses_new_catalog_and_regular_users_never_receive_personal_fields(): void
    {
        $other = $this->supplier('Novo fornecedor', '19131243000197', ['Nome_Socio' => 'Novo representante']);
        Contract::create(['supplier_id' => $other, 'fund_id' => $this->fund, 'ownership_resolved' => true, 'quality_issues' => []]);
        $this->post('/contracts', $this->contractInput())->assertRedirect()->assertSessionHasNoErrors();
        $contract = Contract::where('processo', 'CATALOG-ONLY')->firstOrFail();
        $this->actingAs($this->user)->put('/contracts/'.$contract->id, ['version' => 1, 'reason' => 'Correção de fornecedor',
            'fornecedor_documento_snapshot' => '19131243000197', 'cpf_socio' => 'FORGED-CPF'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Novo fornecedor', $contract->fresh()->fornecedor_nome_snapshot);
        $this->assertSame('Novo representante', $contract->fresh()->socio);
        $this->assertSame($other, $contract->fresh()->supplier_id);
        foreach (['rg_fornec', 'pis_pasep', 'cpf_socio', 'rg_socio'] as $column) {
            $this->assertNull($contract->fresh()->$column);
        }
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
                'cpf_gestor' => null, 'rg_gestor' => null, 'supplier_id' => $this->supplier] + $values)
            ->assertSee('data-supplier-lookup-url', false)->assertDontSee('UNEXPOSED');
        $this->withSession(['_old_input' => ['socio' => 'Representante digitado']])->get('/contracts/create?supplier='.$this->supplier)
            ->assertOk()->assertDontSee('value="Representante digitado"', false)->assertSee('value="Representante cadastrado"', false);
    }

    public function test_regular_user_only_gets_visible_company_and_non_personal_fields_in_both_paths(): void
    {
        $this->actingAs($this->user);
        $response = $this->postJson('/suppliers/lookup', ['document' => '11222333000181'])->assertOk()
            ->assertJsonPath('values.end_fornec', "Rua da Empresa\nCentro")->assertJsonPath('values.socio', 'Representante cadastrado');
        foreach (['rg_fornec', 'pis_pasep', 'cpf_socio', 'rg_socio'] as $field) {
            $response->assertJsonMissingPath('values.'.$field);
        }
        $this->get('/contracts/create?supplier='.$this->supplier)->assertOk()->assertViewHas('values', fn ($values) => $values === [
            'fund_id' => $this->fund, 'fundo_nome_snapshot' => 'Fundo', 'fundo_documento_snapshot' => null,
            'end_fundo' => null, 'nome_gestor' => null, 'cargo_gestor' => null, 'supplier_id' => $this->supplier,
        ] + $response->json('values'))
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
        $this->flushSession();
        $this->postJson('/suppliers/lookup', ['document' => '11222333000181'])->assertForbidden();
        $this->post('/logout');
        $this->postJson('/suppliers/lookup', ['document' => '11222333000181'])->assertUnauthorized();
    }

    public function test_autofilled_fields_are_validated_persisted_and_audited_normally(): void
    {
        $values = $this->postJson('/suppliers/lookup', ['document' => '11222333000181'])->assertOk()->json('values');
        $this->post('/contracts', $values + ['fund_id' => $this->fund, 'numero' => 'AUTOFILL/26', 'processo' => 'PROC-AUTOFILL',
            'modalidade' => 'Pregão', 'n_licit' => '001/2026', 'objeto' => 'Objeto sintético', 'tipo' => 'Serviço', 'valor_total' => '100.0000', 'data_contrato' => '2026-10-13',
            'vigencia_inicio' => '2026-10-13', 'vigencia_fim_original' => '2027-10-13'])->assertRedirect();
        $contract = Contract::where('processo', 'PROC-AUTOFILL')->firstOrFail();
        foreach ($values as $field => $value) {
            $this->assertSame($value, $contract->$field);
        }
        $this->assertSame($this->supplier, $contract->supplier_id);
        $this->assertDatabaseHas('contract_audits', ['contract_id' => $contract->id, 'actor_id' => $this->admin->id, 'action' => 'created']);
        $this->get('/contracts/'.$contract->id.'/edit')->assertOk()->assertSee('data-supplier-lookup-url', false);
    }
}
