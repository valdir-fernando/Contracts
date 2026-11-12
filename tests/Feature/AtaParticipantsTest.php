<?php

namespace Tests\Feature;

use App\Infrastructure\Atas\AtaParticipants;
use App\Models\AccessScope;
use App\Models\ActivityEvent;
use App\Models\User;
use App\Support\ContractFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AtaParticipantsTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private int $manager;

    private int $participant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->actor = User::create(['name' => 'Usuário', 'username' => 'PARTICIPANTES', 'password' => 'SyntheticPassword123'])->fresh();
        $this->manager = $this->fund('Gerenciador', '04252011000110');
        $this->participant = $this->fund('Fundo <Participante>', '11222333000181');
        $scope = AccessScope::create(['scope_key' => 'participant-fund', 'fund_id' => $this->manager, 'fund' => 'Gerenciador']);
        $this->actor->scopes()->attach($scope);
        $this->actingAs($this->actor)->withSession(['fund_id' => $this->manager]);
    }

    private function fund(string $name, string $document): int
    {
        return DB::table('contract_funds')->insertGetId(['name' => $name, 'name_key' => $name, 'document' => $document,
            'raw_payload' => '{"Nome":"CONTATO-PRIVADO"}']);
    }

    private function token(): string
    {
        return app(AtaParticipants::class)->token($this->actor);
    }

    private function payload(string $token, array $extra = []): array
    {
        return array_replace(['draft_token' => $token, 'version' => 0, 'ata_process' => '001/2026',
            'managing_fund_id' => $this->manager, 'participant_fund_id' => $this->participant], $extra);
    }

    public function test_add_uses_catalog_snapshots_audits_and_leaves_ata_null_until_future_save(): void
    {
        $token = $this->token();
        $response = $this->postJson(route('atas.participants.store'), $this->payload($token, [
            'ATA' => '999/2026', 'FUNDO' => 'FALSO', 'CNPJ_Fundo' => 'FALSO', 'Tipo_Fundo' => 'Gerenciador', 'actor_id' => 999]));
        $response->assertCreated()->assertJsonPath('version', 1)->assertJsonCount(1, 'participants')
            ->assertJsonPath('participants.0.ATA', null)->assertJsonPath('participants.0.Processo_ATA', '001/2026')
            ->assertJsonPath('participants.0.CNPJ_Fundo', '11222333000181')
            ->assertJsonPath('participants.0.FUNDO', 'Fundo <Participante>')->assertJsonPath('participants.0.Tipo_Fundo', 'Participante')
            ->assertJsonMissingPath('participants.0.draft_id')->assertDontSee('CONTATO-PRIVADO')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseHas('tb_Participantes', ['ATA' => null, 'fund_id' => $this->participant, 'Tipo_Fundo' => 'Participante']);
        $this->assertDatabaseHas('activity_events', ['actor_id' => $this->actor->id, 'action' => 'ata_participant_added']);
        $this->assertDatabaseCount('tb_ATA', 0);
        $this->get(route('atas.create', ['draft' => $token]))->assertOk()
            ->assertSee('class="section-tab products-button" disabled title="Salve ou carregue uma ata para acessar os Produtos."', false)
            ->assertViewHas('draftState', fn ($state) => $state['version'] === 1 && count($state['participants']) === 1 && $state['process'] === '001/2026');
    }

    public function test_same_fund_is_saved_as_manager_and_allows_adding_another_participant(): void
    {
        $token = $this->token();
        $this->postJson(route('atas.participants.store'), $this->payload($token, [
            'participant_fund_id' => (string) $this->manager, 'Tipo_Fundo' => 'Participante']))
            ->assertCreated()->assertJsonPath('participants.0.Tipo_Fundo', 'Gerenciador');
        $this->assertDatabaseHas('tb_Participantes', ['fund_id' => $this->manager, 'Tipo_Fundo' => 'Gerenciador']);
        $audit = ActivityEvent::where('action', 'ata_participant_added')->first();
        $this->assertSame('Gerenciador', $audit->metadata['participant']['Tipo_Fundo']);
        $this->postJson(route('atas.participants.store'), $this->payload($token, ['version' => 1]))
            ->assertCreated()->assertJsonPath('participants.0.Tipo_Fundo', 'Gerenciador')
            ->assertJsonPath('participants.1.Tipo_Fundo', 'Participante');
        $this->postJson(route('atas.participants.store'), $this->payload($token, [
            'version' => 2, 'participant_fund_id' => $this->manager]))->assertUnprocessable();
        $this->assertDatabaseCount('tb_Participantes', 2);
    }

    public function test_remove_is_versioned_soft_deleted_audited_and_can_be_added_again(): void
    {
        $token = $this->token();
        $id = $this->postJson(route('atas.participants.store'), $this->payload($token))->json('participants.0.id');
        $url = route('atas.participants.destroy', $id);
        $this->deleteJson($url, ['draft_token' => $token, 'version' => 0])->assertConflict()->assertJsonMissingPath('participants');
        $this->deleteJson($url, ['draft_token' => $this->token(), 'version' => 1])->assertNotFound();
        $this->deleteJson($url, ['draft_token' => $token, 'version' => 1])->assertOk()
            ->assertJsonPath('version', 2)->assertJsonCount(0, 'participants');
        $this->assertSoftDeleted('tb_Participantes', ['id' => $id]);
        $this->assertDatabaseHas('activity_events', ['actor_id' => $this->actor->id, 'action' => 'ata_participant_removed']);
        $this->postJson(route('atas.participants.store'), $this->payload($token, ['version' => 2]))->assertCreated()
            ->assertJsonPath('participants.0.id', $id)->assertJsonPath('version', 3);
        $this->assertDatabaseCount('tb_Participantes', 1);
    }

    public function test_remove_rejects_other_actors_and_audit_failure_rolls_back(): void
    {
        $token = $this->token();
        $id = $this->postJson(route('atas.participants.store'), $this->payload($token))->json('participants.0.id');
        $url = route('atas.participants.destroy', $id);
        $other = User::create(['name' => 'Outro', 'username' => 'REMOVE_OTHER', 'password' => 'SyntheticPassword123', 'user_type' => 'Administrador'])->fresh();
        $this->actingAs($other)->deleteJson($url, ['draft_token' => $token, 'version' => 1])->assertForbidden();
        $this->actingAs($this->actor);
        ActivityEvent::creating(function ($event) {
            if ($event->action === 'ata_participant_removed') {
                throw new RuntimeException('Falha de auditoria');
            }
        });
        try {
            $this->deleteJson($url, ['draft_token' => $token, 'version' => 1])->assertStatus(500);
        } finally {
            ActivityEvent::flushEventListeners();
        }
        $this->assertDatabaseHas('tb_Participantes', ['id' => $id, 'deleted_at' => null]);
        $this->assertDatabaseHas('ata_participant_drafts', ['version' => 1]);
        $this->assertDatabaseMissing('activity_events', ['action' => 'ata_participant_removed']);
    }

    public function test_contractor_defaults_follow_the_selected_fund_and_protect_personal_fields(): void
    {
        DB::table('contract_funds')->where('id', $this->manager)->update(['name_key' => 'GERENCIADOR',
            'raw_payload' => json_encode(['End_Fundo' => 'Endereço institucional', 'secret' => 'NAO-EXIBIR'])]);
        DB::table('tb_Gestor')->insert(['legacy_id' => 100, 'name' => 'Gestora de teste', 'name_key' => 'GESTORA',
            'fund_name_key' => 'GERENCIADOR', 'raw_payload' => json_encode(['Cargo' => 'Gestora', 'CPF_Gestor' => '52998224725', 'RG_Gestor' => 'RG-PRIVADO', 'Decreto' => '123/2026', 'Matricula' => '00123'])]);
        DB::table('contract_inspectors')->insert(['legacy_id' => 100, 'name' => 'Fiscal de teste', 'name_key' => 'FISCAL',
            'fund_name_key' => 'GERENCIADOR', 'department_name_key' => '',
            'raw_payload' => json_encode(['CPF_Fiscal' => '52998224725', 'RG_Fiscal' => 'FISCAL-PRIVADO', 'Decreto' => '123/2026'])]);
        $url = route('atas.contractor-defaults', $this->manager);
        $this->getJson($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonMissingPath('values.fundo_nome_snapshot')
            ->assertJsonPath('values.end_fundo', 'Endereço institucional')
            ->assertJsonPath('values.nome_gestor', 'Gestora de teste')->assertJsonMissingPath('values.nome_fiscal')
            ->assertJsonPath('values.decreto_gestor', '123/2026')->assertJsonPath('values.matricula_gestor', '00123')
            ->assertJsonMissingPath('values.decreto_fiscal')->assertJsonMissingPath('values.cpf_gestor')
            ->assertJsonMissingPath('values.rg_gestor')->assertJsonMissingPath('values.cpf_fiscal')
            ->assertJsonMissingPath('values.rg_fiscal')->assertDontSee('NAO-EXIBIR')->assertDontSee('RG-PRIVADO');
        $this->getJson(route('atas.contractor-defaults', $this->participant))->assertForbidden();
        $this->actor->update(['user_type' => 'Administrador']);
        $this->getJson($url)->assertOk()->assertJsonPath('values.cpf_gestor', '52998224725')
            ->assertJsonPath('values.rg_gestor', 'RG-PRIVADO')->assertJsonMissingPath('values.rg_fiscal');
        DB::table('contract_funds')->where('id', $this->manager)->update(['deleted_at' => now()]);
        $this->withSession(['fund_id' => $this->participant]);
        $this->getJson($url)->assertForbidden();
    }

    public function test_new_ata_supplier_panel_uses_contract_fields_and_existing_catalog_assistance(): void
    {
        $response = $this->get(route('atas.create'))->assertOk()
            ->assertDontSee('Preenchido automaticamente')
            ->assertSee('data-ata-supplier-form', false)
            ->assertSee('data-supplier-lookup-url="'.route('suppliers.lookup').'"', false)
            ->assertSee('data-supplier-list-url="'.route('suppliers.index').'"', false)
            ->assertSee('data-contract-summary-field="fornecedor_nome_snapshot"', false);
        foreach (ContractFields::MAP as $source => $definition) {
            if ($definition['group'] !== 'Dados Fornecedor') {
                continue;
            }
            $column = $definition['column'];
            $response->assertSee('name="'.$column.'"', false);
            if ($source !== 'Fornecedor') {
                $response->assertSee(ContractFields::label($source));
            }
        }
        $this->assertDatabaseCount('tb_ATA', 0);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_drafts_are_isolated_even_for_the_same_process_and_same_actor(): void
    {
        $first = $this->token();
        $second = $this->token();
        foreach ([$first, $second] as $token) {
            $this->postJson(route('atas.participants.store'), $this->payload($token))->assertCreated()->assertJsonCount(1, 'participants');
        }
        $this->assertDatabaseCount('tb_Participantes', 2);
        $this->get(route('atas.create'))->assertOk()->assertViewHas('draftState', fn ($state) => $state['participants'] === []);
    }

    public function test_duplicates_deleted_funds_and_invalid_cnpj_are_rejected_without_writes(): void
    {
        $token = $this->token();
        DB::table('contract_funds')->where('id', $this->participant)->update(['deleted_at' => now()]);
        $this->postJson(route('atas.participants.store'), $this->payload($token))->assertUnprocessable();
        DB::table('contract_funds')->where('id', $this->participant)->update(['deleted_at' => null, 'document' => null]);
        $this->postJson(route('atas.participants.store'), $this->payload($token))->assertUnprocessable();
        $this->assertDatabaseCount('ata_participant_drafts', 0);
        $this->assertDatabaseCount('activity_events', 0);
        DB::table('contract_funds')->where('id', $this->participant)->update(['document' => '11222333000181']);
        $this->postJson(route('atas.participants.store'), $this->payload($token))->assertCreated();
        $this->postJson(route('atas.participants.store'), $this->payload($token, ['version' => 1]))
            ->assertUnprocessable()->assertJsonValidationErrors('participant_fund_id');
        $this->assertDatabaseCount('tb_Participantes', 1);
        $this->assertDatabaseCount('activity_events', 1);
    }

    public function test_validation_and_stale_version_preserve_existing_rows(): void
    {
        $token = $this->token();
        foreach ([['ata_process' => ' '], ['ata_process' => str_repeat('x', 65)], ['version' => null], ['managing_fund_id' => null]] as $extra) {
            $this->postJson(route('atas.participants.store'), $this->payload($token, $extra))->assertUnprocessable();
        }
        $this->postJson(route('atas.participants.store'), $this->payload($token))->assertCreated();
        $this->postJson(route('atas.participants.store'), $this->payload($token))->assertConflict()->assertJsonMissingPath('participants');
        $this->assertDatabaseCount('tb_Participantes', 1);
        $this->assertDatabaseCount('activity_events', 1);
    }

    public function test_process_changes_are_applied_to_all_draft_rows_and_audited(): void
    {
        $token = $this->token();
        $this->postJson(route('atas.participants.store'), $this->payload($token))->assertCreated();
        $other = $this->fund('Outro Fundo', '11444777000161');
        $this->postJson(route('atas.participants.store'), $this->payload($token, ['version' => 1,
            'participant_fund_id' => $other, 'ata_process' => '002/2026']))->assertCreated()->assertJsonCount(2, 'participants')
            ->assertJsonPath('participants.0.Processo_ATA', '002/2026')->assertJsonPath('participants.1.Processo_ATA', '002/2026');
        $audit = ActivityEvent::where('action', 'ata_participant_added')->orderByDesc('id')->first();
        $this->assertSame('001/2026', $audit->metadata['before_context']['process']);
        $this->assertSame('002/2026', $audit->metadata['after_context']['process']);
        $this->postJson(route('atas.participants.store'), $this->payload($token, ['version' => 2,
            'managing_fund_id' => $this->participant, 'participant_fund_id' => $this->manager]))->assertUnprocessable();
    }

    public function test_audit_failure_rolls_back_participant_draft_and_context_changes(): void
    {
        $token = $this->token();
        $this->postJson(route('atas.participants.store'), $this->payload($token))->assertCreated();
        $other = $this->fund('Outro Fundo', '11444777000161');
        ActivityEvent::creating(function ($event) {
            if ($event->action === 'ata_participant_added') {
                throw new RuntimeException('Falha de auditoria');
            }
        });
        try {
            $this->postJson(route('atas.participants.store'), $this->payload($token, ['version' => 1,
                'participant_fund_id' => $other, 'ata_process' => '002/2026']))->assertStatus(500);
        } finally {
            ActivityEvent::flushEventListeners();
        }
        $this->assertDatabaseCount('tb_Participantes', 1);
        $this->assertDatabaseHas('tb_Participantes', ['Processo_ATA' => '001/2026']);
        $this->assertDatabaseHas('ata_participant_drafts', ['process' => '001/2026', 'version' => 1]);
        $this->assertDatabaseCount('activity_events', 1);
    }

    public function test_other_users_tampered_tokens_and_revoked_sessions_cannot_read_or_write_drafts(): void
    {
        $token = $this->token();
        $this->postJson(route('atas.participants.store'), $this->payload($token))->assertCreated();
        $other = User::create(['name' => 'Outro', 'username' => 'OUTRO', 'password' => 'SyntheticPassword123', 'user_type' => 'Administrador'])->fresh();
        $this->actingAs($other)->get(route('atas.create', ['draft' => $token]))->assertForbidden();
        $this->postJson(route('atas.participants.store'), $this->payload($token, ['version' => 1]))->assertForbidden();
        $this->actingAs($this->actor)->postJson(route('atas.participants.store'), $this->payload('invalid'))->assertForbidden();
        $this->actor->scopes()->detach();
        $this->postJson(route('atas.participants.store'), $this->payload($token, ['version' => 1]))->assertUnauthorized();
        $this->assertDatabaseCount('tb_Participantes', 1);
    }

    public function test_service_revalidates_actor_activity_and_session_version_inside_transaction(): void
    {
        $token = $this->token();
        DB::table('users')->where('id', $this->actor->id)->update(['session_version' => 1]);
        $this->expectException(HttpException::class);
        app(AtaParticipants::class)->add($this->actor, $token, $this->payload($token), $this->manager);
    }
}
