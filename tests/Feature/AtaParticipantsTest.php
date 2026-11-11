<?php

namespace Tests\Feature;

use App\Infrastructure\Atas\AtaParticipants;
use App\Models\AccessScope;
use App\Models\ActivityEvent;
use App\Models\User;
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
        $this->get(route('atas.create', ['draft' => $token]))->assertOk()->assertViewHas('draftState', fn ($state) => $state['version'] === 1 && count($state['participants']) === 1 && $state['process'] === '001/2026');
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

    public function test_manager_duplicates_deleted_funds_and_invalid_cnpj_are_rejected_without_writes(): void
    {
        $token = $this->token();
        $this->postJson(route('atas.participants.store'), $this->payload($token, ['participant_fund_id' => $this->manager]))
            ->assertUnprocessable()->assertJsonValidationErrors('participant_fund_id');
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
