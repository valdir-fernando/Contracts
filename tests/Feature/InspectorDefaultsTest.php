<?php

namespace Tests\Feature;

use App\Infrastructure\Inspectors\InspectorRegistration;
use App\Models\AccessScope;
use App\Models\Contract;
use App\Models\ContractAudit;
use App\Models\User;
use App\Services\Legacy\InspectorImporter;
use App\Services\Legacy\Normalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InspectorDefaultsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $fund;

    private int $municipality;

    private int $department;

    private int $otherDepartment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'SenhaSegura123', 'user_type' => 'Administrador']);
        $this->fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo Saúde', 'name_key' => 'FUNDO SAUDE', 'raw_payload' => json_encode(['End_Fundo' => 'Rua do Fundo'])]);
        $this->municipality = DB::table('contract_funds')->insertGetId(['name' => 'MUNICIPIO DE NIQUELÂNDIA', 'name_key' => 'MUNICIPIO DE NIQUELANDIA', 'raw_payload' => '{}']);
        $this->department = DB::table('contract_departments')->insertGetId(['name' => 'Educação', 'name_key' => 'EDUCACAO', 'raw_payload' => '{}']);
        $this->otherDepartment = DB::table('contract_departments')->insertGetId(['name' => 'Obras', 'name_key' => 'OBRAS', 'raw_payload' => '{}']);
        DB::table('suppliers')->insert(['name' => 'Empresa', 'name_key' => 'EMPRESA', 'document' => '11222333000181', 'document_key' => '11222333000181', 'raw_payload' => '{}']);
        DB::table('tb_Gestor')->insert(['legacy_id' => 1, 'name' => 'Gestor oficial', 'name_key' => 'GESTOR OFICIAL', 'fund_name_key' => 'FUNDO SAUDE',
            'raw_payload' => json_encode(['Nome_Gestor' => 'Gestor oficial', 'Cargo' => 'Presidente', 'CPF_Gestor' => '11144477735', 'RG_Gestor' => 'RG GESTOR'])]);
        $this->inspector(1, 'Fundo Saúde', '', 'Fiscal Saúde');
        $this->inspector(2, 'MUNICIPIO DE NIQUELÂNDIA', 'Educação', 'Fiscal Educação');
        $this->inspector(3, 'MUNICIPIO DE NIQUELÂNDIA', 'Obras', 'Fiscal Obras');
        $this->actingAs($this->admin)->withSession(['fund_id' => $this->fund]);
    }

    private function inspector(int $id, string $fund, string $department, string $name): void
    {
        DB::table('contract_inspectors')->insert(['legacy_id' => $id, 'name' => $name, 'name_key' => Normalizer::name($name),
            'fund_name_key' => Normalizer::name($fund), 'department_name_key' => Normalizer::name($department),
            'raw_payload' => json_encode(['Nome_Fiscal' => $name, 'Fundo' => $fund, 'Secretaria' => $department,
                'CPF_Fiscal' => '52998224725', 'RG_Fiscal' => 'RG FISCAL PRIVADO', 'Decreto' => '001/26', 'Email' => 'EMAIL PRIVADO'])]);
    }

    private function data(array $extra = []): array
    {
        return array_replace(['processo' => 'PROC', 'fornecedor_documento_snapshot' => '11222333000181', 'objeto' => 'Objeto',
            'tipo' => 'Serviços Diversos', 'data_contrato' => '2026-10-01', 'vigencia_inicio' => '2026-10-01',
            'vigencia_fim_original' => '2027-10-01', 'modalidade' => 'Pregão', 'n_licit' => '001/26', 'valor_total' => '100.0000'], $extra);
    }

    public function test_fund_defaults_are_projected_and_contract_form_cannot_override_catalog_snapshots(): void
    {
        $this->get('/contracts/create')->assertOk()->assertSee('Fiscal Saúde')->assertSee('Gestor oficial');
        $this->getJson('/contracts/inspector-defaults?fund_id='.$this->municipality)->assertOk()
            ->assertJsonPath('values.nome_fiscal', 'Fiscal Saúde')->assertJsonPath('values.decreto_fiscal', '001/26')
            ->assertJsonPath('values.cpf_fiscal', '52998224725')->assertDontSee('EMAIL PRIVADO')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->post('/contracts', $this->data(['nome_fiscal' => 'Falso', 'nome_gestor' => 'Gestor falso', 'end_fundo' => 'Endereço falso', 'cpf_fiscal' => '11144477735']))
            ->assertRedirect()->assertSessionHasNoErrors();
        $contract = Contract::sole();
        $this->assertSame('Fiscal Saúde', $contract->nome_fiscal);
        $this->assertSame('52998224725', $contract->cpf_fiscal);
        $this->assertSame('Gestor oficial', $contract->nome_gestor);
        $this->assertSame('Rua do Fundo', $contract->end_fundo);
        $audit = ContractAudit::sole();
        $this->assertSame('Fiscal Saúde', $audit->changes['nome_fiscal']['after']);
        $response = $this->get('/contracts/'.$contract->id.'/edit')->assertOk();
        $html = new \DOMDocument;
        @$html->loadHTML($response->getContent());
        $xpath = new \DOMXPath($html);
        $this->assertSame(0, $xpath->query('//section[@data-editor-section="2"]//input | //section[@data-editor-section="2"]//textarea | //section[@data-editor-section="2"]//select')->length);
        $this->put('/contracts/'.$contract->id, ['version' => 1, 'nome_gestor' => 'Outro', 'nome_fiscal' => null, 'cpf_gestor' => null, 'reason' => 'Tentar alterar snapshots'])->assertRedirect();
        $this->assertSame('Fiscal Saúde', $contract->fresh()->nome_fiscal);
        $this->assertSame('Gestor oficial', $contract->fresh()->nome_gestor);
        $this->assertSame('11144477735', $contract->fresh()->cpf_gestor);
    }

    public function test_municipality_uses_department_and_re_resolves_inside_the_audited_save(): void
    {
        $this->withSession(['fund_id' => $this->municipality]);
        $this->getJson('/contracts/inspector-defaults')->assertOk()->assertJsonPath('values.nome_fiscal', null);
        $this->getJson('/contracts/inspector-defaults?department_id='.$this->department)->assertOk()->assertJsonPath('values.nome_fiscal', 'Fiscal Educação');
        $this->post('/contracts', $this->data(['department_id' => $this->department, 'nome_fiscal' => 'Fiscal Obras']))->assertRedirect();
        $contract = Contract::sole();
        $this->assertSame('Fiscal Educação', $contract->nome_fiscal);
        $this->put('/contracts/'.$contract->id, ['version' => 1, 'department_id' => $this->otherDepartment, 'nome_fiscal' => 'Fiscal Educação', 'reason' => 'Troca de secretaria'])->assertRedirect();
        $this->assertSame('Fiscal Obras', $contract->fresh()->nome_fiscal);
        $this->assertSame('Obras', $contract->fresh()->secretaria_nome_snapshot);
        $audit = ContractAudit::latest('id')->firstOrFail();
        $this->assertSame(['before' => 'Fiscal Educação', 'after' => 'Fiscal Obras'], $audit->changes['nome_fiscal']);
        $this->put('/contracts/'.$contract->id, ['version' => 1, 'department_id' => $this->department, 'reason' => 'Formulário antigo'])->assertStatus(409);
        $this->assertSame('Fiscal Obras', $contract->fresh()->nome_fiscal);
    }

    public function test_scopes_personal_data_and_cross_fund_contracts_are_protected(): void
    {
        $this->post('/contracts', $this->data())->assertRedirect();
        $contract = Contract::sole();
        $user = User::create(['name' => 'Usuário', 'username' => 'REGULAR', 'password' => 'SenhaSegura123']);
        $this->withSession(['fund_id' => $this->municipality])->post('/contracts', $this->data(['department_id' => $this->department]))->assertRedirect();
        $scope = AccessScope::create(['scope_key' => 'educacao', 'fund' => 'MUNICIPIO DE NIQUELÂNDIA', 'fund_id' => $this->municipality, 'department' => 'Educação', 'department_id' => $this->department]);
        $user->scopes()->attach($scope);
        $this->actingAs($user)->withSession(['fund_id' => $this->municipality]);
        $this->getJson('/contracts/inspector-defaults?department_id='.$this->department)->assertOk()
            ->assertJsonPath('values.nome_fiscal', 'Fiscal Educação')->assertJsonMissingPath('values.cpf_fiscal')
            ->assertJsonMissingPath('values.rg_fiscal')->assertDontSee('EMAIL PRIVADO');
        $this->getJson('/contracts/inspector-defaults?department_id='.$this->otherDepartment)->assertForbidden();
        $this->getJson('/contracts/inspector-defaults?contract_id='.$contract->id)->assertForbidden();
        $this->post('/contracts', $this->data(['department_id' => $this->otherDepartment]))->assertForbidden();
        $this->post('/contracts', $this->data(['department_id' => $this->department]))->assertRedirect();
        $owned = Contract::latest('id')->firstOrFail();
        $this->assertSame('52998224725', $owned->cpf_fiscal);
        $this->get('/contracts/'.$owned->id.'/edit')->assertOk()->assertDontSee('52998224725')->assertDontSee('RG FISCAL PRIVADO');
        $this->actingAs($this->admin)->withSession(['fund_id' => $this->municipality]);
        $this->getJson('/contracts/inspector-defaults?contract_id='.$contract->id)->assertForbidden();
    }

    public function test_ambiguous_or_missing_matches_never_choose_an_arbitrary_inspector_and_history_is_preserved(): void
    {
        $this->post('/contracts', $this->data())->assertRedirect();
        $contract = Contract::sole();
        $this->inspector(4, 'Fundo Saúde', '', 'Segundo fiscal');
        $this->getJson('/contracts/inspector-defaults')->assertOk()->assertJsonPath('values.nome_fiscal', null);
        $this->put('/contracts/'.$contract->id, ['version' => 1, 'objeto' => 'Descrição revista', 'reason' => 'Manter histórico'])->assertRedirect();
        $this->assertSame('Fiscal Saúde', $contract->fresh()->nome_fiscal);
        $this->post('/contracts', $this->data(['processo' => 'NOVO', 'nome_fiscal' => 'Forjado']))->assertSessionHasErrors('nome_fiscal');
        $this->assertDatabaseCount('contracts', 1);
        $this->withSession(['fund_id' => $this->municipality]);
        DB::table('contract_inspectors')->where('department_name_key', 'EDUCACAO')->delete();
        $this->getJson('/contracts/inspector-defaults?department_id='.$this->department)->assertOk()->assertJsonPath('values.nome_fiscal', null);
    }

    public function test_inspector_is_required_and_missing_or_ambiguous_catalog_cannot_be_bypassed(): void
    {
        DB::table('contract_inspectors')->delete();
        foreach ([[], ['nome_fiscal' => 'Fiscal forjado'], ['nome_fiscal' => null]] as $extra) {
            $this->post('/contracts', $this->data($extra))->assertSessionHasErrors('nome_fiscal');
        }
        $this->assertDatabaseCount('contracts', 0);
        $this->assertDatabaseCount('contract_audits', 0);
        $this->assertDatabaseCount('tb_N_Contrato', 0);

        $this->inspector(10, 'Fundo Saúde', '', 'Fiscal válido');
        $this->post('/contracts', $this->data())->assertRedirect()->assertSessionHasNoErrors();
        $contract = Contract::sole();
        $contract->update(['nome_fiscal' => null]);
        DB::table('contract_inspectors')->delete();
        $this->put('/contracts/'.$contract->id, ['version' => 1, 'objeto' => 'Alterado', 'reason' => 'Contrato sem fiscal'])
            ->assertSessionHasErrors('nome_fiscal');
        $this->assertSame(1, $contract->fresh()->version);
        $this->assertSame('Objeto', $contract->fresh()->objeto);
        $this->assertDatabaseCount('contract_audits', 1);

        $this->withSession(['fund_id' => $this->municipality]);
        $this->post('/contracts', $this->data())->assertSessionHasErrors('nome_fiscal');
        $this->post('/contracts', $this->data(['department_id' => $this->department]))->assertSessionHasErrors('nome_fiscal');
    }

    public function test_inactive_inspectors_are_not_suggested_and_saved_contracts_keep_their_history(): void
    {
        $this->post('/contracts', $this->data())->assertRedirect();
        $contract = Contract::firstOrFail();
        DB::table('contract_inspectors')->where('legacy_id', 1)->update(['raw_payload->Situacao' => 'Inativo']);
        $this->getJson('/contracts/inspector-defaults')->assertOk()->assertJsonPath('values.nome_fiscal', null);
        $this->post('/contracts', $this->data(['processo' => 'NOVO']))->assertSessionHasErrors('nome_fiscal');
        $this->assertSame('Fiscal Saúde', $contract->fresh()->nome_fiscal);
        DB::table('contract_inspectors')->where('legacy_id', 1)->update(['raw_payload->Situacao' => 'Ativo']);
        $this->getJson('/contracts/inspector-defaults')->assertOk()->assertJsonPath('values.nome_fiscal', 'Fiscal Saúde');
    }

    public function test_deleted_inspector_is_not_suggested_and_contract_history_is_preserved(): void
    {
        $this->post('/contracts', $this->data())->assertRedirect();
        $contract = Contract::firstOrFail();
        $record = DB::table('contract_inspectors')->where('legacy_id', 1)->first();
        $registration = app(InspectorRegistration::class);
        $registration->delete($this->admin, $record->id, ['revision' => $registration->revision($record), 'confirmation' => 1]);
        $this->getJson('/contracts/inspector-defaults')->assertOk()->assertJsonPath('values.nome_fiscal', null);
        $this->assertSame('Fiscal Saúde', $contract->fresh()->nome_fiscal);
        $this->post('/contracts', $this->data(['processo' => 'NOVO']))->assertSessionHasErrors('nome_fiscal');
    }

    public function test_inspector_import_is_idempotent_preserves_conflicts_and_rolls_back_invalid_data(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fiscal-test-');
        try {
            $sql = "INSERT INTO `tb_Fiscal` (`ID_Fiscal`, `Nome_Fiscal`, `Fundo`, `Secretaria`, `CPF_Fiscal`, `RG_Fiscal`, `Decreto`, `Email`) VALUES (10, 'Fiscal importado', 'Fundo Saúde', '', '52998224725', 'RG', '123/26', 'PRIVADO');";
            file_put_contents($path, $sql);
            $importer = app(InspectorImporter::class);
            $this->assertSame(1, $importer->import($path)['imported_count']);
            $this->assertSame(1, $importer->import($path)['skipped_count']);
            file_put_contents($path, str_replace('Fiscal importado', 'Fiscal alterado', $sql));
            $this->assertSame(1, $importer->import($path)['rejected_count']);
            $this->assertDatabaseHas('contract_inspectors', ['legacy_id' => 10, 'name' => 'Fiscal importado']);
            $this->assertDatabaseHas('legacy_records', ['source_table' => 'tb_Fiscal', 'legacy_id' => '10', 'status' => 'conflict']);
            file_put_contents($path, str_replace(['(10,', "'Fiscal importado'"], ['(12,', "''"], $sql));
            $this->assertSame(1, $importer->import($path)['rejected_count']);
            $this->assertDatabaseMissing('contract_inspectors', ['legacy_id' => 12]);
            $this->assertDatabaseHas('legacy_records', ['source_table' => 'tb_Fiscal', 'legacy_id' => '12', 'status' => 'rejected']);
            file_put_contents($path, str_replace('(10,', '(11,', $sql).str_replace('(10,', '(0,', $sql));
            try {
                $importer->import($path);
                $this->fail('Importação inválida deveria falhar.');
            } catch (\RuntimeException) {
                $this->assertDatabaseMissing('contract_inspectors', ['legacy_id' => 11]);
            }
        } finally {
            unlink($path);
        }
    }
}
