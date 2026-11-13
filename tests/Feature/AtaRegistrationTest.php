<?php

namespace Tests\Feature;

use App\Infrastructure\Atas\AtaParticipants;
use App\Infrastructure\Atas\AtaRegistration;
use App\Models\AccessScope;
use App\Models\ActivityEvent;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AtaRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private int $fund;

    private int $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 11, 13));
        $this->actor = User::create(['name' => 'Admin', 'username' => 'ATA-WRITER', 'password' => 'SyntheticPassword123', 'user_type' => 'Administrador'])->fresh();
        $this->fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo Gerenciador', 'name_key' => Normalizer::name('Fundo Gerenciador'),
            'document' => '04252011000110', 'raw_payload' => json_encode(['End_Fundo' => 'Rua do Fundo'])]);
        DB::table('tb_Gestor')->insert(['name' => 'Gestor autorizado', 'name_key' => 'GESTOR AUTORIZADO',
            'fund_name_key' => Normalizer::name('Fundo Gerenciador'), 'raw_payload' => json_encode([
                'CPF_Gestor' => '52998224725', 'RG_Gestor' => 'RG GESTOR', 'Cargo' => 'Gestor', 'Decreto' => '12/2026', 'Matricula' => '123',
            ])]);
        $this->supplier = DB::table('suppliers')->insertGetId(['name' => 'Fornecedor autorizado', 'name_key' => 'FORNECEDOR AUTORIZADO',
            'document' => '11222333000181', 'document_key' => '11222333000181', 'raw_payload' => json_encode($this->supplierFields())]);
        $this->actingAs($this->actor)->withSession(['fund_id' => $this->fund]);
    }

    private function supplierFields(): array
    {
        return ['Endereco' => 'Rua do Fornecedor', 'Email_Fornecedor' => 'supplier@example.test', 'Fone_Celular' => '',
            'WhatsApp_Fornecedor' => '62999998888', 'Nome_Socio' => 'Representante', 'CPF_Socio' => '52998224725',
            'RG_Socio' => 'RG SOCIO', 'RG' => 'RG FORNECEDOR', 'Doc_Prof' => 'DOC PROF', 'PIS_PASEP' => '12345678901'];
    }

    private function input(array $extra = []): array
    {
        return array_replace(['draft_token' => app(AtaParticipants::class)->token($this->actor), 'version' => 0,
            'managing_fund_id' => $this->fund, 'Data_ATA' => '2026-11-13', 'ata_process' => '123/2026',
            'bidding' => 'Pregão', 'bidding_number' => '12/26', 'fornecedor_documento_snapshot' => '11.222.333/0001-81'], $extra);
    }

    public function test_save_numbers_and_audits_canonical_snapshots_and_returns_only_explicit_identifiers(): void
    {
        $input = $this->input(['ATA' => '999/25', 'version_ata' => 99, 'Valor_ATA' => '9999.9999', 'actor_id' => 999,
            'FORNECEDOR' => 'FORJADO', 'nome_gestor' => 'FORJADO', 'cpf_gestor' => 'FORJADO']);
        $result = $this->postJson(route('atas.store'), $input)->assertCreated()->assertExactJson(['id' => 1, 'number' => '001/26', 'version' => 1])
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseHas('tb_ATA', ['ATA' => '001/26', 'DATA_ATA' => '2026-11-13', 'PROCESSO' => '123/2026',
            'GESTOR' => 'Gestor autorizado', 'FORNECEDOR' => 'Fornecedor autorizado', 'CARGO_GESTOR' => 'Gestor',
            'CPF_SOCIO' => '52998224725', 'TELEFONE2' => '62999998888', 'DOC_PROF' => 'DOC PROF', 'version' => 1]);
        $this->assertDatabaseHas('tb_N_ATA', ['ANO' => 2026, 'NUMERO' => 1]);
        $this->assertDatabaseHas('tb_FUNDOS_ATA', ['ID_ATA' => $result['id'], 'ATA' => '001/26', 'fund_id' => $this->fund, 'papel' => 'gerenciador']);
        $this->assertDatabaseHas('activity_events', ['actor_id' => $this->actor->id, 'action' => 'ata_created']);
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 0);
        $this->getJson(route('atas.lookup', ['number' => $result['number']]))->assertOk()
            ->assertJsonPath('contractor.cargo_gestor', 'Gestor')->assertJsonPath('supplier.rg_fornec', 'RG FORNECEDOR')
            ->assertJsonPath('total', '0.0000');
    }

    public function test_retry_of_same_draft_does_not_issue_another_number_or_duplicate_audit(): void
    {
        $input = $this->input();
        $first = $this->postJson(route('atas.store'), $input)->assertCreated()->json();
        $this->postJson(route('atas.store'), $input)->assertCreated()->assertExactJson($first);
        $this->assertDatabaseCount('tb_ATA', 1);
        $this->assertDatabaseCount('activity_events', 1);
        $this->assertDatabaseHas('tb_N_ATA', ['NUMERO' => 1]);
    }

    public function test_retry_reauthorizes_current_manager_after_transfer_instead_of_trusting_draft_fund(): void
    {
        $input = $this->input();
        $ata = $this->postJson(route('atas.store'), $input)->assertCreated()->json();
        $other = DB::table('contract_funds')->insertGetId(['name' => 'Outro gerenciador', 'name_key' => 'OUTRO', 'raw_payload' => '{}']);
        DB::table('tb_ATA')->where('ID', $ata['id'])->update(['fund_id' => $other]);
        $this->actor->forceFill(['user_type' => 'Usuario'])->save();
        $scope = AccessScope::create(['scope_key' => 'DRAFT-OLD-MANAGER', 'fund_id' => $this->fund, 'fund' => 'Fundo antigo']);
        $this->actor->scopes()->attach($scope);
        $this->actingAs($this->actor->fresh())->postJson(route('atas.store'), $input)->assertForbidden()->assertJsonMissingPath('number');
        $this->assertDatabaseCount('tb_ATA', 1);
    }

    public function test_newly_saved_ata_accepts_participant_without_consuming_another_number(): void
    {
        $ata = $this->postJson(route('atas.store'), $this->input())->assertCreated()->json();
        $fund = DB::table('contract_funds')->insertGetId(['name' => 'Participante', 'name_key' => 'PARTICIPANTE',
            'document' => '11222333000181', 'raw_payload' => '{}']);
        $this->postJson(route('atas.saved-participants.store', $ata['id']), ['version' => $ata['version'], 'participant_fund_id' => $fund])
            ->assertCreated()->assertJsonCount(2, 'participants')->assertJsonPath('version', 2);
        $this->assertDatabaseHas('tb_N_ATA', ['NUMERO' => 1]);
        $this->assertDatabaseHas('tb_FUNDOS_ATA', ['ID_ATA' => $ata['id'], 'fund_id' => $fund]);
    }

    public function test_missing_general_fields_or_stale_draft_do_not_consume_a_number(): void
    {
        foreach (['managing_fund_id', 'Data_ATA', 'ata_process', 'bidding', 'bidding_number', 'fornecedor_documento_snapshot'] as $field) {
            $this->postJson(route('atas.store'), $this->input([$field => ' ']))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->postJson(route('atas.store'), $this->input(['version' => 1]))->assertConflict();
        $this->assertDatabaseCount('tb_ATA', 0);
        $this->assertDatabaseCount('tb_N_ATA', 0);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_manager_missing_fields_cannot_be_supplied_by_browser_snapshots(): void
    {
        DB::table('tb_Gestor')->update(['raw_payload' => '{}']);
        $this->postJson(route('atas.store'), $this->input(['cpf_gestor' => '52998224725', 'RG_GESTOR' => 'forjado']))
            ->assertUnprocessable()->assertJsonValidationErrors(['CPF_GESTOR', 'RG_GESTOR', 'CARGO_GESTOR', 'DECRETO_GESTOR', 'MATRICULA_GESTOR']);
        $this->assertDatabaseCount('tb_N_ATA', 0);
        $this->assertDatabaseCount('tb_ATA', 0);
    }

    public function test_supplier_required_fields_and_one_contact_are_validated_from_current_catalog(): void
    {
        foreach (['Endereco', 'Email_Fornecedor', 'Nome_Socio', 'CPF_Socio', 'RG_Socio', 'WhatsApp_Fornecedor'] as $missing) {
            DB::table('suppliers')->where('id', $this->supplier)->update(['raw_payload' => json_encode(array_replace($this->supplierFields(), [$missing => '']))]);
            $this->postJson(route('atas.store'), $this->input(['supplier_whatsapp' => 'forjado']))->assertUnprocessable();
        }
        $this->assertDatabaseCount('tb_ATA', 0);
        $this->assertDatabaseCount('tb_N_ATA', 0);
    }

    public function test_physical_person_requires_own_rg_and_does_not_require_partner(): void
    {
        $fields = array_replace($this->supplierFields(), ['Nome_Socio' => '', 'CPF_Socio' => '', 'RG_Socio' => '']);
        DB::table('suppliers')->where('id', $this->supplier)->update(['document' => '52998224725', 'document_key' => '52998224725', 'raw_payload' => json_encode($fields)]);
        $input = $this->input(['fornecedor_documento_snapshot' => '529.982.247-25']);
        DB::table('suppliers')->where('id', $this->supplier)->update(['raw_payload' => json_encode(array_replace($fields, ['RG' => '']))]);
        $this->postJson(route('atas.store'), $input)->assertUnprocessable()->assertJsonValidationErrors('RG_FORNECEDOR');
        DB::table('suppliers')->where('id', $this->supplier)->update(['raw_payload' => json_encode($fields)]);
        $this->postJson(route('atas.store'), $input)->assertCreated();
    }

    public function test_authorization_rejects_foreign_draft_revoked_scope_and_department_only_scope(): void
    {
        $input = $this->input();
        $other = User::create(['name' => 'Outro', 'username' => 'OTHER', 'password' => 'SyntheticPassword123', 'user_type' => 'Administrador'])->fresh();
        $this->actingAs($other)->postJson(route('atas.store'), $input)->assertForbidden();
        $this->actingAs($this->actor);
        $this->actor->forceFill(['user_type' => 'Usuario'])->save();
        // The session middleware closes access when the selected fund was revoked.
        $this->postJson(route('atas.store'), $input)->assertUnauthorized();
        $department = DB::table('contract_departments')->insertGetId(['name' => 'Secretaria', 'name_key' => 'SECRETARIA', 'raw_payload' => '{}']);
        $scope = AccessScope::create(['scope_key' => 'ATA-DEPT', 'fund_id' => $this->fund, 'fund' => 'Fundo Gerenciador', 'department' => 'Secretaria', 'department_id' => $department]);
        $this->actor->scopes()->attach($scope);
        $this->actingAs($this->actor->fresh())->withSession(['fund_id' => $this->fund])
            ->postJson(route('atas.store'), $input)->assertForbidden();
        $this->assertDatabaseCount('tb_ATA', 0);
        $this->assertDatabaseCount('tb_N_ATA', 0);
    }

    public function test_direct_service_call_revalidates_session_version(): void
    {
        $input = $this->input();
        $expected = $this->actor->replicate();
        $expected->id = $this->actor->id;
        $this->actor->forceFill(['session_version' => 1])->save();
        try {
            app(AtaRegistration::class)->save($expected, $input, $this->fund);
            $this->fail('Stale actor accepted');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertDatabaseCount('tb_ATA', 0);
    }

    public function test_scoped_actor_can_save_and_returned_snapshots_remain_masked(): void
    {
        $this->actor->forceFill(['user_type' => 'Usuario', 'catalog_permissions' => ['supplier']])->save();
        $scope = AccessScope::create(['scope_key' => 'ATA-FULL', 'fund_id' => $this->fund, 'fund' => 'Fundo Gerenciador']);
        $this->actor->scopes()->attach($scope);
        ActivityEvent::create(['actor_id' => $this->actor->id, 'action' => 'catalog_created',
            'metadata' => ['kind' => 'supplier', 'id' => $this->supplier], 'created_at' => now()]);
        $input = $this->input();
        $this->actingAs($this->actor->fresh())->postJson(route('atas.store'), $input)->assertCreated();
        $this->getJson(route('atas.lookup', ['number' => '001/26']))->assertOk()
            ->assertDontSee('52998224725')->assertDontSee('RG FORNECEDOR');
        $this->actor->scopes()->detach();
        $this->postJson(route('atas.store'), $input)->assertUnauthorized();
        $this->assertDatabaseCount('tb_ATA', 1);
    }

    public function test_invalid_or_ambiguous_supplier_and_inactive_actor_cannot_create_an_ata(): void
    {
        $this->postJson(route('atas.store'), $this->input(['fornecedor_documento_snapshot' => '11111111111']))->assertUnprocessable();
        $this->postJson(route('atas.store'), $this->input(['fornecedor_documento_snapshot' => '52998224725']))->assertUnprocessable();
        $supplier = (array) DB::table('suppliers')->find($this->supplier);
        unset($supplier['id']);
        DB::table('suppliers')->insert($supplier);
        $this->postJson(route('atas.store'), $this->input())->assertUnprocessable();
        $expected = $this->actor->fresh();
        $input = $this->input();
        $this->actor->forceFill(['is_active' => false])->save();
        try {
            app(AtaRegistration::class)->save($expected, $input, $this->fund);
            $this->fail('Inactive actor accepted');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertDatabaseCount('tb_ATA', 0);
        $this->assertDatabaseCount('tb_N_ATA', 0);
    }

    public function test_existing_participants_are_linked_and_consumed_draft_cannot_be_edited(): void
    {
        $input = $this->input();
        app(AtaParticipants::class)->add($this->actor, $input['draft_token'], ['version' => 0,
            'managing_fund_id' => $this->fund, 'participant_fund_id' => $this->fund, 'ata_process' => 'old'], $this->fund);
        $input['version'] = 1;
        $this->postJson(route('atas.store'), $input)->assertCreated();
        $this->assertDatabaseCount('tb_FUNDOS_ATA', 1);
        $this->assertDatabaseHas('tb_Participantes', ['ATA' => '001/26', 'Processo_ATA' => '123/2026']);
        $this->postJson(route('atas.participants.store'), ['draft_token' => $input['draft_token'], 'version' => 2,
            'managing_fund_id' => $this->fund, 'participant_fund_id' => $this->fund, 'ata_process' => 'changed'])->assertConflict();
    }

    public function test_audit_failure_rolls_back_ata_sequence_participants_and_draft(): void
    {
        $input = $this->input();
        ActivityEvent::creating(function ($event) {
            if ($event->action === 'ata_created') {
                throw new RuntimeException('Synthetic audit failure');
            }
        });
        try {
            app(AtaRegistration::class)->save($this->actor, $input, $this->fund);
            $this->fail('Audit failure was ignored');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic audit failure', $error->getMessage());
        } finally {
            ActivityEvent::flushEventListeners();
        }
        $this->assertDatabaseCount('tb_ATA', 0);
        $this->assertDatabaseCount('tb_FUNDOS_ATA', 0);
        $this->assertDatabaseCount('tb_N_ATA', 0);
        $this->assertDatabaseCount('ata_participant_drafts', 0);
        $this->assertDatabaseCount('activity_events', 0);
    }
}
