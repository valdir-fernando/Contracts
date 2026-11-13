<?php

namespace Tests\Feature;

use App\Infrastructure\Atas\SavedAtaParticipants;
use App\Models\AccessScope;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SavedAtaParticipantsTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private int $manager;

    private int $participant;

    private int $ata;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->actor = User::create(['name' => 'Admin', 'username' => 'SAVED-ATA', 'password' => 'SyntheticPassword123', 'user_type' => 'Administrador'])->fresh();
        $this->manager = DB::table('contract_funds')->insertGetId(['name' => 'Gerenciador', 'name_key' => 'GERENCIADOR', 'document' => '04252011000110', 'raw_payload' => '{}']);
        $this->participant = DB::table('contract_funds')->insertGetId(['name' => 'Participante atual', 'name_key' => 'PARTICIPANTE', 'document' => '11222333000181', 'raw_payload' => '{"private":"PRIVADO"}']);
        $this->ata = DB::table('tb_ATA')->insertGetId(['ATA' => '107/25', 'PROCESSO' => 'PROCESSO HISTÓRICO', 'LICITACAO' => 'Pregão', 'N_LICIT' => '12',
            'fund_id' => $this->manager, 'CNPJ_FUNDO' => '04252011000110', 'FUNDO' => 'Gerenciador histórico',
            'source_files' => '[]', 'import_hash' => str_repeat('a', 64), 'quality_issues' => '[]', 'version' => 1], 'ID');
        $this->actingAs($this->actor)->withSession(['fund_id' => $this->manager]);
    }

    private function add(array $extra = [])
    {
        return $this->postJson(route('atas.saved-participants.store', $this->ata), array_replace([
            'version' => 1, 'participant_fund_id' => $this->participant,
        ], $extra));
    }

    public function test_historical_ata_adds_canonical_fund_audits_and_lookup_keeps_the_saved_list(): void
    {
        $this->add(['ATA' => 'FALSO', 'Processo_ATA' => 'FALSO', 'FUNDO' => 'FALSO', 'actor_id' => 999])
            ->assertCreated()->assertJsonPath('version', 2)->assertJsonCount(1, 'participants')
            ->assertJsonPath('participants.0.FUNDO', 'Participante atual')->assertJsonPath('participants.0.ATA', '107/25')
            ->assertJsonPath('participants.0.Processo_ATA', 'PROCESSO HISTÓRICO')->assertDontSee('PRIVADO')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseHas('tb_FUNDOS_ATA', ['ID_ATA' => $this->ata, 'ATA' => '107/25', 'fund_id' => $this->participant, 'papel' => 'participante', 'CNPJ_FUNDO' => '11222333000181']);
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'version' => 2, 'FUNDO' => 'Gerenciador histórico']);
        $this->assertDatabaseHas('activity_events', ['actor_id' => $this->actor->id, 'action' => 'ata_saved_participant_added']);
        $this->getJson(route('atas.lookup', ['number' => '107/25']))->assertOk()->assertJsonPath('participants.0.fund_id', $this->participant)->assertJsonPath('version', 2);
        $this->assertDatabaseCount('ata_participant_drafts', 0);
        $this->assertDatabaseCount('tb_N_ATA', 0);
    }

    public function test_stale_version_duplicates_and_alias_document_do_not_create_another_link(): void
    {
        $this->add()->assertCreated();
        $this->add()->assertConflict()->assertJsonMissingPath('participants');
        $this->add(['version' => 2])->assertUnprocessable()->assertJsonValidationErrors('participant_fund_id');
        $alias = DB::table('contract_funds')->insertGetId(['name' => 'Mesmo CNPJ', 'name_key' => 'ALIAS', 'document' => '11.222.333/0001-81', 'raw_payload' => '{}']);
        $this->add(['version' => 2, 'participant_fund_id' => $alias])->assertUnprocessable();
        $this->assertDatabaseCount('tb_FUNDOS_ATA', 1);
        $this->assertDatabaseCount('activity_events', 1);
    }

    public function test_removed_link_is_restored_without_erasing_history(): void
    {
        $id = DB::table('tb_FUNDOS_ATA')->insertGetId(['ID_ATA' => $this->ata, 'fund_id' => $this->participant,
            'FUNDO' => 'Nome antigo', 'CNPJ_FUNDO' => '11.222.333/0001-81', 'papel' => 'participante', 'deleted_at' => now()], 'ID');
        $this->add()->assertCreated()->assertJsonPath('participants.0.id', $id);
        $this->assertDatabaseCount('tb_FUNDOS_ATA', 1);
        $this->assertDatabaseHas('tb_FUNDOS_ATA', ['ID' => $id, 'ATA' => '107/25', 'deleted_at' => null, 'FUNDO' => 'Participante atual']);
        $event = ActivityEvent::where('action', 'ata_saved_participant_added')->sole();
        $this->assertSame('Nome antigo', $event->metadata['before']['FUNDO']);
    }

    public function test_legacy_ata_without_fund_id_allows_admin_and_recognizes_its_manager_document(): void
    {
        DB::table('tb_ATA')->where('ID', $this->ata)->update(['fund_id' => null]);
        $this->add(['participant_fund_id' => $this->manager])->assertCreated()->assertJsonPath('participants.0.Tipo_Fundo', 'Gerenciador');
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'fund_id' => null, 'FUNDO' => 'Gerenciador histórico']);
    }

    public function test_missing_deleted_or_invalid_fund_and_deleted_ata_are_rejected(): void
    {
        $this->add(['participant_fund_id' => 999])->assertUnprocessable();
        DB::table('contract_funds')->where('id', $this->participant)->update(['document' => '123']);
        $this->add()->assertUnprocessable();
        DB::table('contract_funds')->where('id', $this->participant)->update(['document' => '11222333000181', 'deleted_at' => now()]);
        $this->add()->assertUnprocessable();
        DB::table('tb_ATA')->where('ID', $this->ata)->update(['deleted_at' => now()]);
        $this->add()->assertNotFound();
        $this->assertDatabaseCount('tb_FUNDOS_ATA', 0);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_scope_is_enforced_for_current_ata_and_legacy_atas_without_fund_link(): void
    {
        $this->actor->forceFill(['user_type' => 'Usuario'])->save();
        $scope = AccessScope::create(['scope_key' => 'SAVED-ATA-SCOPE', 'fund_id' => $this->participant, 'fund' => 'Participante']);
        $this->actor->scopes()->attach($scope);
        $this->actingAs($this->actor->fresh())->withSession(['fund_id' => $this->participant]);
        $this->add()->assertForbidden();
        $full = AccessScope::create(['scope_key' => 'SAVED-ATA-MANAGER', 'fund_id' => $this->manager, 'fund' => 'Gerenciador']);
        $this->actor->scopes()->attach($full);
        $this->add()->assertCreated();
        DB::table('tb_ATA')->where('ID', $this->ata)->update(['fund_id' => null]);
        $this->add(['version' => 2])->assertForbidden();
    }

    public function test_actor_session_and_audit_are_revalidated_transactionally(): void
    {
        $expected = $this->actor->fresh();
        $this->actor->forceFill(['session_version' => 1])->save();
        try {
            app(SavedAtaParticipants::class)->add($expected, $this->ata, ['version' => 1, 'participant_fund_id' => $this->participant], $this->manager);
            $this->fail('Stale actor accepted');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        ActivityEvent::creating(fn () => throw new RuntimeException('Synthetic audit failure'));
        try {
            app(SavedAtaParticipants::class)->add($this->actor->fresh(), $this->ata, ['version' => 1, 'participant_fund_id' => $this->participant], $this->manager);
            $this->fail('Audit failure ignored');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic audit failure', $error->getMessage());
        } finally {
            ActivityEvent::flushEventListeners();
        }
        $this->assertDatabaseCount('tb_FUNDOS_ATA', 0);
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'version' => 1]);
        $this->assertDatabaseCount('activity_events', 0);
    }
}
