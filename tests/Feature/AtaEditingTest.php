<?php

namespace Tests\Feature;

use App\Infrastructure\Atas\RemoveAta;
use App\Infrastructure\Atas\UpdateAta;
use App\Models\AccessScope;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AtaEditingTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private int $fund;

    private int $ata;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::create(['name' => 'Admin', 'username' => 'ATA-EDITOR', 'password' => 'SyntheticPassword123', 'user_type' => 'Administrador'])->fresh();
        $this->fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo', 'name_key' => 'FUNDO', 'raw_payload' => '{}']);
        $this->ata = DB::table('tb_ATA')->insertGetId(['ATA' => '107/2025', 'PROCESSO' => '2025007440', 'LICITACAO' => 'Pregão',
            'N_LICIT' => '12', 'fund_id' => $this->fund, 'CNPJ_FUNDO' => '04252011000110', 'CPF_GESTOR' => '52998224725',
            'source_files' => '[]', 'quality_issues' => '[]', 'import_hash' => str_repeat('a', 64), 'version' => 1], 'ID');
        $this->actingAs($this->actor)->withSession(['fund_id' => $this->fund]);
    }

    private function change(array $input = [])
    {
        return $this->patchJson(route('atas.update', $this->ata), array_replace(['version' => 1, 'bidding_number' => '45'], $input));
    }

    public function test_edit_preserves_number_snapshots_and_counter_and_audits_only_allowed_fields(): void
    {
        $before = (array) DB::table('tb_ATA')->sole();
        $counter = DB::table('tb_N_ATA')->get()->toArray();
        $this->change(['ATA' => '999/26', 'fund_id' => 999, 'CPF_GESTOR' => 'forjado', 'Doc_ATA' => 'forjado',
            'Data_ATA' => '2026-11-18', 'ata_process' => 'NOVO', 'start_date' => '2026-11-18', 'end_date' => '2027-11-18',
            'validity_period' => '12 meses', 'one_year_inpc' => false])->assertOk()
            ->assertExactJson(['id' => $this->ata, 'number' => '107/2025', 'version' => 2])
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'ATA' => '107/2025', 'PROCESSO' => 'NOVO',
            'N_LICIT' => '45', 'CPF_GESTOR' => '52998224725', 'fund_id' => $this->fund, 'Doc_ATA' => null]);
        $event = ActivityEvent::where('action', 'ata_updated')->sole();
        $this->assertSame($this->actor->id, $event->actor_id);
        $this->assertSame('12', $event->metadata['before']['N_LICIT']);
        $this->assertSame('45', $event->metadata['after']['N_LICIT']);
        $this->assertSame($counter, DB::table('tb_N_ATA')->get()->toArray());
        $this->assertDatabaseCount('tb_ATA', 1);
        $this->assertSame($before['import_hash'], DB::table('tb_ATA')->sole()->import_hash);
    }

    public function test_omitted_values_are_preserved_null_clears_and_unchanged_edit_does_not_increment_version(): void
    {
        DB::table('tb_ATA')->where('ID', $this->ata)->update(['PERIODO_ATA' => 'histórico', 'VIGENCIA_ANO_INPC' => false]);
        $this->change(['bidding_number' => '12'])->assertOk()->assertJsonPath('version', 1);
        $this->assertDatabaseCount('activity_events', 0);
        $this->change(['validity_period' => null])->assertOk()->assertJsonPath('version', 2);
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'PERIODO_ATA' => null, 'PROCESSO' => '2025007440']);
    }

    public function test_invalid_values_stale_version_and_process_change_with_amendment_are_rejected(): void
    {
        $this->change(['Data_ATA' => '2026-02-30'])->assertUnprocessable();
        $this->change(['start_date' => '2026-11-18', 'end_date' => '2025-11-18', 'one_year_inpc' => false])->assertUnprocessable();
        $this->change(['version' => 2])->assertConflict()->assertJsonMissingPath('number');
        DB::table('tb_ADT_ATA')->insert(['ATA' => '107/2025', 'Proc_ATA' => '2025007440', 'ADT' => 1]);
        $this->change(['ata_process' => 'OUTRO'])->assertUnprocessable()->assertJsonValidationErrors('ata_process');
        $this->assertDatabaseCount('activity_events', 0);
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'N_LICIT' => '12', 'version' => 1]);
    }

    public function test_soft_delete_preserves_history_children_and_counter_and_blocks_further_access(): void
    {
        $child = DB::table('tb_FUNDOS_ATA')->insertGetId(['ID_ATA' => $this->ata, 'CNPJ_FUNDO' => '04252011000110', 'FUNDO' => 'Fundo', 'papel' => 'gerenciador'], 'ID');
        $counter = DB::table('tb_N_ATA')->get()->toArray();
        $url = route('atas.destroy', $this->ata);
        $this->deleteJson($url, ['version' => 2])->assertConflict();
        $this->deleteJson($url, ['version' => 1])->assertOk()->assertExactJson(['id' => $this->ata, 'number' => '107/2025', 'version' => 2]);
        $this->assertNotNull(DB::table('tb_ATA')->sole()->deleted_at);
        $this->assertDatabaseHas('tb_FUNDOS_ATA', ['ID' => $child, 'deleted_at' => DB::table('tb_ATA')->sole()->deleted_at]);
        $this->assertDatabaseCount('tb_ATA', 1);
        $this->assertSame($counter, DB::table('tb_N_ATA')->get()->toArray());
        $this->assertSame($this->actor->id, ActivityEvent::where('action', 'ata_deleted')->sole()->actor_id);
        $this->getJson(route('atas.lookup', ['number' => '107/2025']))->assertNotFound();
        $this->change()->assertNotFound();
        $this->deleteJson($url, ['version' => 2])->assertNotFound();
    }

    private function linkedRecords(): array
    {
        $draft = '11111111-1111-4111-8111-111111111111';
        DB::table('ata_participant_drafts')->insert(['id' => $draft, 'actor_id' => $this->actor->id,
            'managing_fund_id' => $this->fund, 'process' => '2025007440', 'ata_id' => $this->ata]);
        $participant = DB::table('tb_Participantes')->insertGetId(['draft_id' => $draft, 'fund_id' => $this->fund,
            'ATA' => '107/2025', 'Processo_ATA' => '2025007440', 'CNPJ_Fundo' => '04252011000110', 'FUNDO' => 'Fundo', 'Tipo_Fundo' => 'Participante']);
        $fund = DB::table('tb_FUNDOS_ATA')->insertGetId(['ID_ATA' => $this->ata, 'CNPJ_FUNDO' => '04252011000110', 'FUNDO' => 'Fundo', 'papel' => 'gerenciador'], 'ID');
        $amendment = DB::table('tb_ADITIVOS_ATA')->insertGetId(['ID_ATA' => $this->ata, 'ATA' => '107/2025',
            'NUMERO' => 1, 'PROCESSO_ORIGEM' => '2025007440', 'source_path' => 'synthetic.pdf', 'source_sha256' => str_repeat('b', 64),
            'source_size' => 10, 'extraction' => 'text', 'link_status' => 'vinculado', 'issues' => '[]'], 'ID');
        $legacy = DB::table('tb_ADT_ATA')->insertGetId(['ATA' => '107/25', 'Proc_ATA' => '2025007440', 'ADT' => 1, 'Doc_ADT' => '%PDF-synthetic'], 'ID');
        $product = ['ID_ATA' => $this->ata, 'ATA' => '107/2025', 'PROCESSO' => '2025007440', 'LICITACAO' => 'Pregão',
            'N_LICIT' => '12', 'ITEM' => '1', 'DISCRIMINACAO' => 'Produto', 'UND' => 'UN',
            'QTD' => '1.0000', 'VL_UNIT' => '2.0000', 'VL_TOTAL' => '2.0000', 'source_row' => '{}'];
        $original = DB::table('tb_PRODUTOS_ATA')->insertGetId($product, 'ID');
        $additional = DB::table('tb_PRODUTOS_ATA')->insertGetId(array_replace($product, ['ITEM' => '2', 'ADITIVO' => 1, 'ID_ADITIVO' => $amendment]), 'ID');

        return ['tb_FUNDOS_ATA' => [$fund], 'tb_Participantes' => [$participant], 'tb_ADITIVOS_ATA' => [$amendment],
            'tb_ADT_ATA' => [$legacy], 'tb_PRODUTOS_ATA' => [$original, $additional]];
    }

    public function test_delete_marks_all_linked_records_with_same_timestamp_and_audits_ids_without_removing_pdfs(): void
    {
        $records = $this->linkedRecords();
        $other = DB::table('tb_ADT_ATA')->insertGetId(['ATA' => '107/25', 'Proc_ATA' => 'OUTRO', 'ADT' => 1], 'ID');
        $this->deleteJson(route('atas.destroy', $this->ata), ['version' => 1])->assertOk()->assertJsonMissingPath('related_deleted_ids');
        $deletedAt = DB::table('tb_ATA')->where('ID', $this->ata)->value('deleted_at');
        $event = ActivityEvent::where('action', 'ata_deleted')->sole();
        foreach ($records as $table => $ids) {
            $primary = $table === 'tb_Participantes' ? 'id' : 'ID';
            foreach ($ids as $id) {
                $this->assertDatabaseHas($table, [$primary => $id, 'deleted_at' => $deletedAt]);
            }
            $this->assertSame($ids, $event->metadata['related_deleted_ids'][$table]);
        }
        $this->assertDatabaseHas('tb_ADT_ATA', ['ID' => $other, 'deleted_at' => null]);
        $this->assertDatabaseHas('tb_ADT_ATA', ['ID' => $records['tb_ADT_ATA'][0], 'Doc_ADT' => '%PDF-synthetic']);
        $this->assertSame(2, DB::table('tb_PRODUTOS_ATA')->count());
    }

    public function test_delete_preserves_previous_deletion_timestamps_and_excludes_them_from_new_audit(): void
    {
        $records = $this->linkedRecords();
        $previous = '2020-01-02 03:04:05';
        foreach ($records as $table => $ids) {
            DB::table($table)->where($table === 'tb_Participantes' ? 'id' : 'ID', $ids[0])->update(['deleted_at' => $previous]);
        }
        $this->deleteJson(route('atas.destroy', $this->ata), ['version' => 1])->assertOk();
        $event = ActivityEvent::where('action', 'ata_deleted')->sole();
        foreach ($records as $table => $ids) {
            $this->assertDatabaseHas($table, [$table === 'tb_Participantes' ? 'id' : 'ID' => $ids[0], 'deleted_at' => $previous]);
            $this->assertNotContains($ids[0], $event->metadata['related_deleted_ids'][$table]);
        }
    }

    public function test_audit_failure_rolls_back_parent_and_all_related_soft_deletes(): void
    {
        $records = $this->linkedRecords();
        Event::listen('eloquent.creating: '.ActivityEvent::class, function () {
            throw new RuntimeException('Audit unavailable');
        });
        try {
            app(RemoveAta::class)->remove($this->actor, $this->ata, ['version' => 1], $this->fund);
            $this->fail('Audit failure must abort the cascade.');
        } catch (RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.ActivityEvent::class);
        }
        foreach ($records as $table => $ids) {
            foreach ($ids as $id) {
                $this->assertDatabaseHas($table, [$table === 'tb_Participantes' ? 'id' : 'ID' => $id, 'deleted_at' => null]);
            }
        }
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'deleted_at' => null, 'version' => 1]);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_ambiguous_legacy_amendments_abort_cascade_and_stale_version_does_not_touch_children(): void
    {
        $records = $this->linkedRecords();
        $url = route('atas.destroy', $this->ata);
        $this->deleteJson($url, ['version' => 2])->assertConflict();
        DB::table('tb_ATA')->insert(['ATA' => '107/25', 'PROCESSO' => '2025007440', 'LICITACAO' => 'Pregão',
            'N_LICIT' => '12', 'CNPJ_FUNDO' => '11222333000181', 'source_files' => '[]', 'quality_issues' => '[]', 'import_hash' => str_repeat('c', 64)]);
        $this->deleteJson($url, ['version' => 1])->assertConflict()->assertJsonMissingPath('related_deleted_ids');
        foreach ($records as $table => $ids) {
            foreach ($ids as $id) {
                $this->assertDatabaseHas($table, [$table === 'tb_Participantes' ? 'id' : 'ID' => $id, 'deleted_at' => null]);
            }
        }
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'version' => 1, 'deleted_at' => null]);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_current_fund_and_full_scope_are_required_for_edit_and_delete(): void
    {
        DB::table('users')->where('id', $this->actor->id)->update(['user_type' => 'Usuario']);
        $other = DB::table('contract_funds')->insertGetId(['name' => 'Outro', 'name_key' => 'OUTRO', 'raw_payload' => '{}']);
        $scope = AccessScope::create(['scope_key' => 'EDIT-FUND', 'fund_id' => $other, 'fund' => 'Outro']);
        $this->actor->scopes()->attach($scope);
        $this->actingAs($this->actor->fresh())->withSession(['fund_id' => $other]);
        $this->change(['fund_id' => $other, 'user_type' => 'Administrador'])->assertForbidden();
        $this->deleteJson(route('atas.destroy', $this->ata), ['version' => 1])->assertForbidden();
        auth()->logout();
        $this->change()->assertUnauthorized();
        $this->deleteJson(route('atas.destroy', $this->ata), ['version' => 1])->assertUnauthorized();
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_stale_actor_and_audit_failure_abort_both_mutations(): void
    {
        DB::table('users')->where('id', $this->actor->id)->update(['session_version' => 1]);
        foreach ([UpdateAta::class => 'update', RemoveAta::class => 'remove'] as $class => $method) {
            try {
                app($class)->{$method}($this->actor, $this->ata, ['version' => 1, 'bidding_number' => '45'], $this->fund);
                $this->fail('Stale actor must be rejected.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
        $actor = $this->actor->fresh();
        Event::listen('eloquent.creating: '.ActivityEvent::class, function () {
            throw new RuntimeException('Audit unavailable');
        });
        try {
            foreach ([UpdateAta::class => 'update', RemoveAta::class => 'remove'] as $class => $method) {
                try {
                    app($class)->{$method}($actor, $this->ata, ['version' => 1, 'bidding_number' => '45'], $this->fund);
                    $this->fail('Audit failure must abort the mutation.');
                } catch (RuntimeException $error) {
                    $this->assertSame('Audit unavailable', $error->getMessage());
                }
                $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'version' => 1, 'N_LICIT' => '12', 'deleted_at' => null]);
            }
        } finally {
            Event::forget('eloquent.creating: '.ActivityEvent::class);
        }
    }

    public function test_fund_scope_can_edit_but_department_only_scope_cannot_edit_or_delete(): void
    {
        DB::table('users')->where('id', $this->actor->id)->update(['user_type' => 'Usuario']);
        $scope = AccessScope::create(['scope_key' => 'EDITOR-FULL-FUND', 'fund_id' => $this->fund, 'fund' => 'Fundo']);
        $this->actor->scopes()->attach($scope);
        $this->actingAs($this->actor->fresh());
        $this->change()->assertOk()->assertJsonPath('version', 2)->assertJsonMissingPath('CPF_GESTOR');
        $department = DB::table('contract_departments')->insertGetId(['name' => 'Secretaria', 'name_key' => 'SECRETARIA', 'raw_payload' => '{}']);
        DB::table('access_scopes')->where('id', $scope->id)->update(['department' => 'Secretaria', 'department_id' => $department]);
        $this->change(['version' => 2])->assertForbidden();
        $this->deleteJson(route('atas.destroy', $this->ata), ['version' => 2])->assertForbidden();
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'version' => 2, 'deleted_at' => null]);
    }

    private function originalProduct(array $extra = []): int
    {
        return DB::table('tb_PRODUTOS_ATA')->insertGetId(array_replace(['ID_ATA' => $this->ata, 'ATA' => '107/2025', 'PROCESSO' => '2025007440',
            'LICITACAO' => 'Pregão', 'N_LICIT' => '12', 'ITEM' => '1', 'DISCRIMINACAO' => 'Produto', 'UND' => 'UN',
            'QTD' => '1.0000', 'VL_UNIT' => '1.0000', 'VL_TOTAL' => '1.0000', 'source_row' => '{}'], $extra), 'ID');
    }

    public function test_process_change_moves_original_products_including_soft_deleted_without_changing_values(): void
    {
        $active = $this->originalProduct();
        $deleted = $this->originalProduct(['ITEM' => '2', 'deleted_at' => '2020-01-02 03:04:05']);
        $before = DB::table('tb_PRODUTOS_ATA')->orderBy('ID')->get()->map(fn ($row) => (array) $row)->all();
        $this->change(['ata_process' => 'OUTRO'])->assertOk()->assertJsonPath('version', 2);
        foreach ($before as $row) {
            $expected = $row;
            $expected['PROCESSO'] = 'OUTRO';
            $actual = (array) DB::table('tb_PRODUTOS_ATA')->where('ID', $row['ID'])->first();
            unset($expected['updated_at'], $actual['updated_at']);
            $this->assertSame($expected, $actual);
        }
        $event = ActivityEvent::where('action', 'ata_updated')->sole();
        $this->assertSame([$active, $deleted], array_column($event->metadata['related_process_changes']['products_before'], 'ID'));
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'PROCESSO' => 'OUTRO', 'ATA' => '107/2025']);
        $this->deleteJson(route('atas.products.destroy', $this->ata), ['version' => 2, 'item' => '1', 'quantity' => '1.0000'])->assertOk();
    }

    public function test_process_change_keeps_drafts_and_participants_consistent_and_audit_failure_rolls_everything_back(): void
    {
        $this->originalProduct();
        $draft = '22222222-2222-4222-8222-222222222222';
        DB::table('ata_participant_drafts')->insert(['id' => $draft, 'actor_id' => $this->actor->id, 'managing_fund_id' => $this->fund, 'process' => '2025007440', 'ata_id' => $this->ata]);
        $participant = DB::table('tb_Participantes')->insertGetId(['draft_id' => $draft, 'fund_id' => $this->fund,
            'ATA' => '107/2025', 'Processo_ATA' => '2025007440', 'CNPJ_Fundo' => '04252011000110', 'FUNDO' => 'Fundo', 'Tipo_Fundo' => 'Participante']);
        Event::listen('eloquent.creating: '.ActivityEvent::class, function () {
            throw new RuntimeException('Audit unavailable');
        });
        try {
            app(UpdateAta::class)->update($this->actor, $this->ata, ['version' => 1, 'ata_process' => 'OUTRO'], $this->fund);
            $this->fail('Audit failure must abort process changes.');
        } catch (RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.ActivityEvent::class);
        }
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'PROCESSO' => '2025007440', 'version' => 1]);
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ID_ATA' => $this->ata, 'PROCESSO' => '2025007440']);
        $this->assertDatabaseHas('ata_participant_drafts', ['id' => $draft, 'process' => '2025007440']);
        $this->assertDatabaseHas('tb_Participantes', ['id' => $participant, 'Processo_ATA' => '2025007440']);
        $this->assertDatabaseCount('activity_events', 0);
        $this->change(['ata_process' => 'OUTRO'])->assertOk();
        $this->assertDatabaseHas('ata_participant_drafts', ['id' => $draft, 'process' => 'OUTRO']);
        $this->assertDatabaseHas('tb_Participantes', ['id' => $participant, 'Processo_ATA' => 'OUTRO']);
    }

    public function test_normalized_amendments_deleted_amendments_and_amendment_products_block_only_process_change(): void
    {
        $this->originalProduct(['ADITIVO' => 1]);
        $this->change(['ata_process' => 'OUTRO'])->assertUnprocessable()->assertJsonValidationErrors('ata_process');
        $this->change()->assertOk()->assertJsonPath('version', 2);
        DB::table('tb_PRODUTOS_ATA')->where('ID_ATA', $this->ata)->update(['ADITIVO' => 0]);
        DB::table('tb_ADT_ATA')->insert(['ATA' => '107/25', 'Proc_ATA' => '2025007440', 'ADT' => 1, 'deleted_at' => now()]);
        $this->change(['version' => 2, 'ata_process' => 'OUTRO'])->assertUnprocessable()
            ->assertJsonPath('errors.ata_process.0', 'O Processo só pode mudar se não houver aditivos vinculados. Esta ATA possui aditivos vinculados.');
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'PROCESSO' => '2025007440', 'version' => 2]);
    }

    public function test_linked_imported_amendment_prevents_process_change_without_changing_original_products(): void
    {
        $product = $this->originalProduct();
        DB::table('tb_ADITIVOS_ATA')->insert(['ID_ATA' => $this->ata, 'ATA' => '107/2025', 'NUMERO' => 1,
            'PROCESSO_ORIGEM' => '2025007440', 'source_path' => 'linked.pdf', 'source_sha256' => str_repeat('d', 64),
            'source_size' => 10, 'extraction' => 'text', 'link_status' => 'vinculado', 'issues' => '[]']);
        $this->change(['ata_process' => 'OUTRO'])->assertUnprocessable()->assertJsonValidationErrors('ata_process');
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ID' => $product, 'PROCESSO' => '2025007440']);
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'PROCESSO' => '2025007440', 'version' => 1]);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_duplicate_target_identity_reports_reason_and_leaves_both_atas_and_products_unchanged(): void
    {
        $product = $this->originalProduct();
        $other = DB::table('tb_ATA')->insertGetId(['ATA' => '107/25', 'PROCESSO' => 'OUTRO', 'LICITACAO' => 'Pregão',
            'N_LICIT' => '12', 'CNPJ_FUNDO' => '11222333000181', 'source_files' => '[]', 'quality_issues' => '[]', 'import_hash' => str_repeat('e', 64)], 'ID');
        $this->change(['ata_process' => 'OUTRO'])->assertUnprocessable()
            ->assertJsonPath('errors.ata_process.0', 'Já existe outra ATA com esse número e Processo. Informe um Processo diferente.');
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'PROCESSO' => '2025007440', 'version' => 1]);
        $this->assertDatabaseHas('tb_ATA', ['ID' => $other, 'PROCESSO' => 'OUTRO', 'version' => 1]);
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ID' => $product, 'PROCESSO' => '2025007440']);
        $this->assertDatabaseCount('activity_events', 0);
    }
}
