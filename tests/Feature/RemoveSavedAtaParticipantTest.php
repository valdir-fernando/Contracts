<?php

namespace Tests\Feature;

use App\Infrastructure\Atas\RemoveSavedAtaParticipant;
use App\Models\AccessScope;
use App\Models\ActivityEvent;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RemoveSavedAtaParticipantTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private int $ata;

    private int $manager;

    private int $participant;

    private int $replacement;

    private int $managerRow;

    private int $participantRow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->actor = User::create(['name' => 'Admin', 'username' => 'ATA-REMOVE', 'password' => 'SyntheticPassword123', 'user_type' => 'Administrador'])->fresh();
        $this->manager = $this->fund('Gerenciador antigo', '04252011000110');
        $this->participant = $this->fund('Participante', '11222333000181');
        $this->replacement = $this->fund('Novo gerenciador', '11444777000161');
        $this->ata = DB::table('tb_ATA')->insertGetId(['ATA' => '107/25', 'PROCESSO' => 'PROC', 'LICITACAO' => 'Pregão', 'N_LICIT' => '12',
            'fund_id' => $this->manager, 'CNPJ_FUNDO' => '04252011000110', 'FUNDO' => 'Gerenciador histórico', 'GESTOR' => 'Gestor histórico',
            'source_files' => '[]', 'quality_issues' => '[]', 'import_hash' => str_repeat('a', 64), 'version' => 1,
            'INICIO_ATA' => '2025-01-01', 'FINAL_ATA' => '2026-01-01'], 'ID');
        $this->managerRow = $this->link($this->manager, 'gerenciador');
        $this->participantRow = $this->link($this->participant);
        $this->actingAs($this->actor)->withSession(['fund_id' => $this->manager]);
    }

    private function fund(string $name, string $document): int
    {
        $id = DB::table('contract_funds')->insertGetId(['name' => $name, 'name_key' => Normalizer::name($name),
            'document' => $document, 'raw_payload' => json_encode(['End_Fundo' => 'Endereço '.$name])]);
        DB::table('tb_Gestor')->insert(['name' => 'Gestor '.$name, 'name_key' => Normalizer::name('Gestor '.$name),
            'fund_name_key' => Normalizer::name($name), 'raw_payload' => json_encode([
                'CPF_Gestor' => '52998224725', 'RG_Gestor' => 'RG '.$name, 'Cargo' => 'Gestor', 'Decreto' => '12/2026', 'Matricula' => '123',
            ])]);

        return $id;
    }

    private function link(int $fundId, string $role = 'participante', bool $deleted = false): int
    {
        $fund = DB::table('contract_funds')->find($fundId);

        return DB::table('tb_FUNDOS_ATA')->insertGetId(['ID_ATA' => $this->ata, 'ATA' => '107/25', 'fund_id' => $fundId,
            'CNPJ_FUNDO' => $fund->document, 'FUNDO' => $fund->name, 'papel' => $role, 'deleted_at' => $deleted ? now() : null], 'ID');
    }

    private function remove(int $row, array $input = [])
    {
        return $this->deleteJson(route('atas.saved-participants.destroy', [$this->ata, $row]), array_replace(['version' => 1], $input));
    }

    public function test_regular_participant_is_soft_deleted_by_line_and_manager_is_unchanged(): void
    {
        $this->remove($this->participantRow)->assertOk()->assertJsonCount(1, 'participants')->assertJsonPath('version', 2)
            ->assertJsonPath('participants.0.id', $this->managerRow)->assertJsonPath('manager_changed', false)
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonMissingPath('manager_before');
        $this->assertNotNull(DB::table('tb_FUNDOS_ATA')->where('ID', $this->participantRow)->value('deleted_at'));
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'fund_id' => $this->manager, 'GESTOR' => 'Gestor histórico', 'version' => 2]);
        $this->assertDatabaseHas('tb_FUNDOS_ATA', ['ID' => $this->managerRow, 'deleted_at' => null, 'papel' => 'gerenciador']);
        $this->assertDatabaseHas('activity_events', ['action' => 'ata_saved_participant_removed', 'actor_id' => $this->actor->id]);
        $this->assertDatabaseCount('tb_FUNDOS_ATA', 2);
    }

    public function test_manager_cannot_be_removed_without_different_valid_replacement(): void
    {
        $this->remove($this->managerRow)->assertUnprocessable()->assertJsonValidationErrors('replacement_fund_id');
        foreach ([$this->manager, 999] as $replacement) {
            $this->remove($this->managerRow, ['replacement_fund_id' => $replacement])->assertUnprocessable();
        }
        $alias = $this->fund('Mesmo órgão', '04.252.011/0001-10');
        $this->remove($this->managerRow, ['replacement_fund_id' => $alias])->assertUnprocessable();
        DB::table('contract_funds')->where('id', $this->replacement)->update(['deleted_at' => now()]);
        $this->remove($this->managerRow, ['replacement_fund_id' => $this->replacement])->assertUnprocessable();
        $this->assertDatabaseHas('tb_FUNDOS_ATA', ['ID' => $this->managerRow, 'deleted_at' => null]);
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'version' => 1]);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_historical_wrong_role_cannot_bypass_manager_rule_and_missing_manager_must_be_repaired(): void
    {
        DB::table('tb_FUNDOS_ATA')->where('ID', $this->managerRow)->update(['papel' => 'participante']);
        $this->remove($this->managerRow)->assertUnprocessable()->assertJsonValidationErrors('replacement_fund_id');
        $this->remove($this->participantRow)->assertUnprocessable()->assertJsonValidationErrors('replacement_fund_id');
        $this->remove($this->participantRow, ['replacement_fund_id' => $this->manager])->assertOk();
        $this->assertDatabaseHas('tb_FUNDOS_ATA', ['ID' => $this->managerRow, 'papel' => 'gerenciador', 'deleted_at' => null]);
    }

    public function test_existing_participant_is_promoted_and_parent_snapshots_are_updated_together(): void
    {
        $this->remove($this->managerRow, ['replacement_fund_id' => $this->participant, 'FUNDO' => 'FORJADO', 'CPF_GESTOR' => 'FORJADO'])
            ->assertOk()->assertJsonPath('manager_changed', true)->assertJsonCount(1, 'participants')
            ->assertJsonPath('participants.0.id', $this->participantRow)->assertJsonPath('participants.0.Tipo_Fundo', 'Gerenciador');
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'fund_id' => $this->participant,
            'CNPJ_FUNDO' => '11222333000181', 'FUNDO' => 'Participante', 'END_FUNDO' => 'Endereço Participante',
            'GESTOR' => 'Gestor Participante', 'CPF_GESTOR' => '52998224725', 'RG_GESTOR' => 'RG Participante',
            'DECRETO_GESTOR' => '12/2026', 'MATRICULA_GESTOR' => '123', 'CARGO_GESTOR' => 'Gestor',
            'INICIO_ATA' => '2025-01-01', 'FINAL_ATA' => '2026-01-01', 'version' => 2]);
        $event = ActivityEvent::where('action', 'ata_saved_participant_removed')->sole();
        $this->assertSame('Gerenciador histórico', $event->metadata['manager_before']['FUNDO']);
        $this->assertSame('Participante', $event->metadata['manager_after']['FUNDO']);
        $this->getJson(route('atas.lookup', ['number' => '107/25']))->assertOk()
            ->assertJsonPath('managing_fund_id', (string) $this->participant)->assertJsonPath('contractor.nome_gestor', 'Gestor Participante');
        $this->assertDatabaseCount('tb_FUNDOS_ATA', 2);
        $this->assertDatabaseCount('tb_N_ATA', 0);
    }

    public function test_replacement_can_be_new_or_restored_and_always_leaves_one_manager(): void
    {
        $restored = $this->link($this->replacement, deleted: true);
        $this->remove($this->managerRow, ['replacement_fund_id' => $this->replacement])->assertOk();
        $this->assertDatabaseHas('tb_FUNDOS_ATA', ['ID' => $restored, 'papel' => 'gerenciador', 'deleted_at' => null, 'ATA' => '107/25']);
        $this->assertSame(1, DB::table('tb_FUNDOS_ATA')->where('ID_ATA', $this->ata)->whereNull('deleted_at')->where('papel', 'gerenciador')->count());
        $this->remove($restored, ['version' => 2, 'replacement_fund_id' => $this->manager])->assertOk();
        $this->assertDatabaseHas('tb_FUNDOS_ATA', ['ID' => $this->managerRow, 'deleted_at' => null, 'papel' => 'gerenciador']);
    }

    public function test_new_fund_is_added_as_manager_when_not_previously_linked(): void
    {
        $this->remove($this->managerRow, ['replacement_fund_id' => $this->replacement])->assertOk()->assertJsonCount(2, 'participants');
        $this->assertDatabaseCount('tb_FUNDOS_ATA', 3);
        $this->assertDatabaseHas('tb_FUNDOS_ATA', ['ID_ATA' => $this->ata, 'fund_id' => $this->replacement, 'ATA' => '107/25', 'papel' => 'gerenciador', 'deleted_at' => null]);
    }

    public function test_incomplete_manager_catalog_or_identity_collision_does_not_delete_old_manager(): void
    {
        DB::table('tb_Gestor')->where('fund_name_key', Normalizer::name('Novo gerenciador'))->update(['raw_payload' => '{}']);
        $this->remove($this->managerRow, ['replacement_fund_id' => $this->replacement])->assertUnprocessable()->assertJsonValidationErrors('CPF_GESTOR');
        $ata = (array) DB::table('tb_ATA')->where('ID', $this->ata)->first();
        unset($ata['ID']);
        $ata['CNPJ_FUNDO'] = '11222333000181';
        $ata['fund_id'] = $this->participant;
        DB::table('tb_ATA')->insert($ata);
        $this->remove($this->managerRow, ['replacement_fund_id' => $this->participant])->assertUnprocessable()->assertJsonValidationErrors('replacement_fund_id');
        $this->assertDatabaseHas('tb_FUNDOS_ATA', ['ID' => $this->managerRow, 'deleted_at' => null]);
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'version' => 1]);
    }

    public function test_wrong_ata_row_stale_version_and_unauthorized_actor_cannot_delete(): void
    {
        $other = (array) DB::table('tb_ATA')->where('ID', $this->ata)->first();
        unset($other['ID']);
        $other['PROCESSO'] = 'OUTRA-ATA';
        $otherId = DB::table('tb_ATA')->insertGetId($other, 'ID');
        $foreignRow = DB::table('tb_FUNDOS_ATA')->insertGetId(['ID_ATA' => $otherId, 'ATA' => '107/25', 'fund_id' => $this->participant,
            'CNPJ_FUNDO' => '11222333000181', 'FUNDO' => 'Outro vínculo', 'papel' => 'participante'], 'ID');
        $this->remove($foreignRow)->assertNotFound();
        $this->remove(999)->assertNotFound();
        $this->remove($this->participantRow, ['version' => 99])->assertConflict()->assertJsonMissingPath('participants');
        $this->actor->forceFill(['user_type' => 'Usuario'])->save();
        $scope = AccessScope::create(['scope_key' => 'OTHER-FUND', 'fund_id' => $this->replacement, 'fund' => 'Novo gerenciador']);
        $this->actor->scopes()->attach($scope);
        $this->actingAs($this->actor->fresh())->withSession(['fund_id' => $this->replacement]);
        $this->remove($this->participantRow)->assertForbidden();
        $scope = AccessScope::create(['scope_key' => 'MANAGER-FUND', 'fund_id' => $this->manager, 'fund' => 'Gerenciador']);
        $this->actor->scopes()->attach($scope);
        $this->remove($this->managerRow, ['replacement_fund_id' => $this->participant])->assertForbidden();
        $this->assertDatabaseCount('activity_events', 0);
        $this->assertDatabaseHas('tb_FUNDOS_ATA', ['ID' => $this->participantRow, 'deleted_at' => null]);
        $this->remove($this->participantRow)->assertOk();
    }

    public function test_audit_failure_rolls_back_replacement_deletion_snapshots_and_version(): void
    {
        ActivityEvent::creating(fn () => throw new RuntimeException('Synthetic audit failure'));
        try {
            app(RemoveSavedAtaParticipant::class)->remove($this->actor, $this->ata, $this->managerRow,
                ['version' => 1, 'replacement_fund_id' => $this->replacement], $this->manager);
            $this->fail('Audit failure ignored');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic audit failure', $error->getMessage());
        } finally {
            ActivityEvent::flushEventListeners();
        }
        $this->assertDatabaseHas('tb_FUNDOS_ATA', ['ID' => $this->managerRow, 'papel' => 'gerenciador', 'deleted_at' => null]);
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'fund_id' => $this->manager, 'GESTOR' => 'Gestor histórico', 'version' => 1]);
        $this->assertDatabaseMissing('tb_FUNDOS_ATA', ['fund_id' => $this->replacement]);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_stale_authenticated_snapshot_is_rejected_inside_service(): void
    {
        $expected = $this->actor->fresh();
        $this->actor->forceFill(['session_version' => 1])->save();
        try {
            app(RemoveSavedAtaParticipant::class)->remove($expected, $this->ata, $this->participantRow, ['version' => 1], $this->manager);
            $this->fail('Stale actor accepted');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertDatabaseHas('tb_FUNDOS_ATA', ['ID' => $this->participantRow, 'deleted_at' => null]);
    }
}
