<?php

namespace Tests\Feature;

use App\Infrastructure\Atas\AtaReportQuery;
use App\Infrastructure\Atas\AtaWritePolicy;
use App\Infrastructure\Atas\RemoveAta;
use App\Infrastructure\Atas\RemoveAtaAmendment;
use App\Infrastructure\Atas\SaveAtaAmendment;
use App\Infrastructure\Atas\UpdateAta;
use App\Infrastructure\Atas\UpdateAtaValidity;
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

    public function test_existing_ata_can_receive_a_budget_date_and_partial_changes_preserve_it(): void
    {
        $this->change(['Data_Orçamento' => null])->assertUnprocessable()->assertJsonValidationErrors('Data_Orçamento');
        $this->change(['Data_Orçamento' => '2026-10-20'])->assertOk()->assertJsonPath('version', 2);
        $this->change(['version' => 2, 'bidding_number' => '46'])->assertOk();
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'DATA_ORCAMENTO' => '2026-10-20', 'N_LICIT' => '46']);
        $events = ActivityEvent::where('action', 'ata_updated')->orderBy('id')->get();
        $this->assertSame('2026-10-20', $events->first()->metadata['after']['DATA_ORCAMENTO']);
        $this->assertArrayNotHasKey('DATA_ORCAMENTO', $events->last()->metadata['after']);
    }

    public function test_supplier_change_captures_catalog_data_audits_the_previous_supplier_and_preserves_the_ata(): void
    {
        DB::table('tb_ATA')->where('ID', $this->ata)->update([
            'FORNECEDOR' => 'Fornecedor anterior', 'CNPJ_FORNECEDOR' => '11222333000181', 'SOCIO' => 'Representante anterior',
        ]);
        DB::table('suppliers')->insert([
            'name' => 'Fornecedor correto', 'name_key' => 'FORNECEDOR CORRETO',
            'document' => '52998224725', 'document_key' => '52998224725',
            'raw_payload' => json_encode(['Endereco' => 'Rua correta', 'Email_Fornecedor' => 'contato@example.test',
                'Fone_Celular' => '62999990000', 'RG' => 'RG de teste'], JSON_THROW_ON_ERROR),
        ]);
        $this->change(['fornecedor_documento_snapshot' => '---'])->assertUnprocessable()
            ->assertJsonValidationErrors('fornecedor_documento_snapshot');
        $this->change(['fornecedor_documento_snapshot' => '529.982.247-25', 'FORNECEDOR' => 'Forjado',
            'EMAIL' => 'forjado@example.test'])->assertOk()->assertJsonPath('version', 2)->assertJsonMissingPath('FORNECEDOR');
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'ATA' => '107/2025', 'PROCESSO' => '2025007440',
            'FORNECEDOR' => 'Fornecedor correto', 'CNPJ_FORNECEDOR' => '52998224725', 'END_FORNECEDOR' => 'Rua correta',
            'EMAIL' => 'contato@example.test', 'SOCIO' => null, 'RG_FORNECEDOR' => 'RG de teste']);
        $event = ActivityEvent::where('action', 'ata_updated')->sole();
        $this->assertSame('Fornecedor anterior', $event->metadata['before']['FORNECEDOR']);
        $this->assertSame('Fornecedor correto', $event->metadata['after']['FORNECEDOR']);
        $this->change(['version' => 2, 'fornecedor_documento_snapshot' => '52998224725'])->assertOk()->assertJsonPath('version', 2);
        $this->change(['version' => 2, 'fornecedor_documento_snapshot' => '11.222.333/0001-81'])
            ->assertUnprocessable()->assertJsonValidationErrors('fornecedor_documento_snapshot');
        $this->assertDatabaseCount('activity_events', 1);
    }

    public function test_publication_date_is_saved_with_the_last_edit_and_locks_all_editor_mutations(): void
    {
        $this->change(['publication_date' => '2026-02-30'])->assertUnprocessable()->assertJsonValidationErrors('publication_date');
        $this->change(['publication_date' => '2026-11-20'])->assertOk()->assertJsonPath('version', 2);
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'DATA_PUBLIC_INPC' => '2026-11-20', 'N_LICIT' => '45']);
        $this->getJson(route('atas.lookup', ['number' => '107/2025']))->assertOk()->assertJsonPath('publication_date', '2026-11-20');
        $this->assertSame('2026-11-20', ActivityEvent::where('action', 'ata_updated')->sole()->metadata['after']['DATA_PUBLIC_INPC']);
        $payload = ['version' => 2, 'publication_date' => null, 'bidding_number' => '99', 'participant_fund_id' => $this->fund];
        foreach ([
            ['PATCH', 'atas.update'],
            ['PATCH', 'atas.validity.update'],
            ['PATCH', 'atas.admission.update'],
            ['PATCH', 'atas.special-quantity.update'],
            ['DELETE', 'atas.special-quantity.destroy'],
            ['POST', 'atas.products.store'],
            ['DELETE', 'atas.products.destroy'],
            ['POST', 'atas.saved-participants.store'],
            ['DELETE', 'atas.saved-participants.destroy'],
            ['DELETE', 'atas.destroy'],
        ] as [$method, $route]) {
            $arguments = $route === 'atas.saved-participants.destroy' ? ['ata' => $this->ata, 'participant' => 1] : ['ata' => $this->ata];
            $this->json($method, route($route, $arguments), $payload)->assertConflict()->assertJsonPath('message', AtaWritePolicy::PUBLISHED_MESSAGE);
        }
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'DATA_PUBLIC_INPC' => '2026-11-20',
            'N_LICIT' => '45', 'version' => 2, 'deleted_at' => null]);
        $this->assertDatabaseCount('activity_events', 1);
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

    public function test_deleting_only_the_latest_amendment_reduces_total_and_recovers_the_previous_expiration(): void
    {
        $records = $this->linkedRecords();
        DB::table('tb_ATA')->where('ID', $this->ata)->update([
            'INICIO_ATA' => '2025-11-07', 'FINAL_ATA' => '2027-11-07', 'PERIODO_ATA' => '1 ano',
        ]);
        DB::table('tb_ADT_ATA')->where('ID', $records['tb_ADT_ATA'][0])->update(['FINAL' => '2027-02-07']);
        $latest = DB::table('tb_ADT_ATA')->insertGetId(['ATA' => '107/25', 'Proc_ATA' => '2025007440',
            'ADT' => 2, 'FINAL' => '2027-11-07', 'Doc_ADT' => '%PDF-latest'], 'ID');
        $product = (array) DB::table('tb_PRODUTOS_ATA')->where('ID', $records['tb_PRODUTOS_ATA'][0])->first();
        unset($product['ID'], $product['ficha_identity'], $product['amendment_active_slot']);
        $item = DB::table('tb_PRODUTOS_ATA')->insertGetId(array_replace($product, ['ADITIVO' => 2, 'VL_TOTAL' => '0.1001']), 'ID');
        $delete = fn (int $number, int $version) => $this->deleteJson(route('atas.amendments.destroy', ['ata' => $this->ata, 'amendment' => $number]), ['version' => $version]);
        $delete(1, 1)->assertConflict();
        $delete(2, 2)->assertConflict();
        $this->assertDatabaseCount('activity_events', 0);
        $delete(2, 1)->assertOk()->assertJsonPath('version', 2);
        $this->assertSoftDeleted('tb_PRODUTOS_ATA', ['ID' => $item]);
        $this->assertSoftDeleted('tb_ADT_ATA', ['ID' => $latest, 'Doc_ADT' => '%PDF-latest']);
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'FINAL_ATA' => '2027-02-07', 'deleted_at' => null]);
        $this->getJson(route('atas.lookup', ['number' => '107/25']))->assertOk()
            ->assertJsonPath('amendment_numbers', [1])->assertJsonPath('amendment_updated_total', '4.0000');
        $event = ActivityEvent::where('action', 'ata_amendment_deleted')->sole();
        $this->assertSame($this->actor->id, $event->actor_id);
        $this->assertSame('0.1001', $event->metadata['removed_amount']);

        $delete(1, 2)->assertOk()->assertJsonPath('version', 3);
        $this->assertSoftDeleted('tb_ADITIVOS_ATA', ['ID' => $records['tb_ADITIVOS_ATA'][0]]);
        $this->assertSoftDeleted('tb_PRODUTOS_ATA', ['ID' => $records['tb_PRODUTOS_ATA'][1]]);
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'FINAL_ATA' => '2026-11-07', 'deleted_at' => null]);
        $this->getJson(route('atas.lookup', ['number' => '107/25']))->assertOk()
            ->assertJsonPath('amendment_numbers', [])->assertJsonPath('amendment_updated_total', '2.0000');
        $delete(1, 3)->assertNotFound();
    }

    public function test_amendment_delete_requires_the_current_fund_and_respects_the_publication_lock(): void
    {
        $records = $this->linkedRecords();
        $url = route('atas.amendments.destroy', ['ata' => $this->ata, 'amendment' => 1]);
        $this->withSession(['fund_id' => null])->deleteJson($url, ['version' => 1])->assertForbidden();
        DB::table('tb_ATA')->where('ID', $this->ata)->update(['DATA_PUBLIC_INPC' => '2026-01-01']);
        $this->withSession(['fund_id' => $this->fund])->deleteJson($url, ['version' => 1])->assertConflict();
        $this->assertDatabaseHas('tb_ADT_ATA', ['ID' => $records['tb_ADT_ATA'][0], 'deleted_at' => null]);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_saving_a_quantitative_amendment_adds_original_products_and_can_restore_the_exact_original_expiration(): void
    {
        $records = $this->linkedRecords();
        DB::table('tb_ATA')->where('ID', $this->ata)->update([
            'INICIO_ATA' => '2025-11-07', 'FINAL_ATA' => '2026-11-06', 'PERIODO_ATA' => '12 meses',
        ]);
        DB::table('tb_PRODUTOS_ATA')->where('ID', $records['tb_PRODUTOS_ATA'][0])->update(['QTD' => '1.1111', 'VL_UNIT' => '2.3333', 'VL_TOTAL' => '2.5925']);
        DB::table('tb_ADT_ATA')->where('ID', $records['tb_ADT_ATA'][0])->update(['Data_ADT' => '2026-11-06']);
        $url = route('atas.amendments.store', $this->ata);
        $this->postJson($url, ['version' => 1])->assertUnprocessable()
            ->assertJsonValidationErrors(['number', 'date', 'process', 'type', 'start', 'end', 'period']);
        $input = ['version' => 1, 'number' => 2, 'date' => '2026-11-05', 'process' => 'PRORROGACAO-02',
            'type' => SaveAtaAmendment::EQUAL_QUANTITY, 'start' => '2026-11-07', 'end' => '2027-11-06', 'period' => '12 meses'];
        $this->postJson($url, $input)->assertUnprocessable()->assertJsonValidationErrors('advantage');
        $this->postJson($url, $input + ['advantage' => 'Outra justificativa'])->assertUnprocessable()->assertJsonValidationErrors('advantage');
        $input['advantage'] = 'Pesquisa de Preços';
        $this->postJson($url, $input)->assertCreated()->assertJsonPath('amendment_amount', '2.5925')
            ->assertJsonPath('amendment_updated_total', '7.1850')->assertJsonPath('version', 2)
            ->assertJsonPath('amendment.advantage', 'Pesquisa de Preços');
        $this->postJson($url, $input)->assertConflict();
        $this->postJson($url, array_replace($input, ['version' => 2]))->assertConflict();
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ID_ATA' => $this->ata, 'ADITIVO' => 2, 'ITEM' => '1', 'VL_TOTAL' => 2.5925]);
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'FINAL_ATA' => '2027-11-06']);
        $this->getJson(route('atas.lookup', ['number' => '107/25']))->assertOk()
            ->assertJsonPath('end_date', '2026-11-06')->assertJsonPath('end_date_derived', true)
            ->assertJsonPath('amendments.2.type', SaveAtaAmendment::EQUAL_QUANTITY)
            ->assertJsonPath('amendments.2.advantage', 'Pesquisa de Preços')
            ->assertJsonPath('amendment_updated_total', '7.1850');
        $report = app(AtaReportQuery::class)->for($this->actor, ['report_year' => 'all'], false);
        $this->assertSame('2027-11-06', $report['rows']->sole()['ends']);
        $this->assertSame('2', $report['rows']->sole()['amendment']['number']);
        $this->assertSame('7.1850', $report['rows']->sole()['amount']);
        $event = ActivityEvent::where('action', 'ata_amendment_saved')->sole();
        $this->assertSame($this->actor->id, $event->actor_id);
        $this->assertCount(1, $event->metadata['product_ids']);
        $this->assertSame('Pesquisa de Preços', $event->metadata['amendment']['advantage']);
        $edit = route('atas.amendments.update', ['ata' => $this->ata, 'amendment' => 2]);
        $this->patchJson($edit, array_replace($input, ['version' => 2, 'process' => 'PRORROGACAO-02-EDITADO', 'advantage' => 'Declaração do Gestor']))
            ->assertOk()->assertJsonPath('version', 3)->assertJsonPath('amendment_updated_total', '7.1850');
        $this->assertDatabaseHas('tb_ADT_ATA', ['ID' => $event->metadata['amendment_id'], 'Proc_ADT' => 'PRORROGACAO-02-EDITADO', 'VANTAJOSIDADE' => 'Declaração do Gestor']);
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ID' => $event->metadata['product_ids'][0], 'PROC_ADT' => 'PRORROGACAO-02-EDITADO']);
        $this->assertSame(1, DB::table('tb_ADT_ATA')->where('ADT', 2)->count());
        $this->assertSame(1, DB::table('tb_PRODUTOS_ATA')->where('ADITIVO', 2)->count());
        $this->patchJson($edit, array_replace($input, ['version' => 3, 'type' => SaveAtaAmendment::EXTENSION, 'end' => '2027-10-01']))
            ->assertOk()->assertJsonPath('version', 4)->assertJsonPath('amendment_updated_total', '4.5925');
        $this->assertDatabaseHas('tb_ADT_ATA', ['ID' => $event->metadata['amendment_id'], 'VANTAJOSIDADE' => 'Pesquisa de Preços']);
        $this->assertSoftDeleted('tb_PRODUTOS_ATA', ['ID' => $event->metadata['product_ids'][0]]);
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'FINAL_ATA' => '2027-10-01']);
        $this->deleteJson(route('atas.amendments.destroy', ['ata' => $this->ata, 'amendment' => 2]), ['version' => 4])->assertOk();
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'FINAL_ATA' => '2026-11-06']);
    }

    public function test_extension_without_new_quantities_keeps_the_amount_and_other_types_have_no_implicit_effects(): void
    {
        $input = ['version' => 1, 'number' => 1, 'date' => '2026-11-05', 'process' => 'PRORROGACAO',
            'type' => 'Apostilamento/Reajuste Anual INDICE', 'start' => '2026-11-07', 'end' => '2027-11-06', 'period' => '12 meses'];
        $url = route('atas.amendments.store', $this->ata);
        $this->postJson($url, $input)->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->assertDatabaseCount('tb_ADT_ATA', 0);
        $this->assertDatabaseCount('activity_events', 0);
        $extension = array_replace($input, ['type' => SaveAtaAmendment::EXTENSION]);
        $this->postJson($url, $extension)->assertUnprocessable()->assertJsonValidationErrors('advantage');
        $this->postJson($url, $extension + ['advantage' => 'Outra justificativa'])->assertUnprocessable()->assertJsonValidationErrors('advantage');
        DB::table('tb_ATA')->where('ID', $this->ata)->update(['INICIO_ATA' => '2025-11-07']);
        $this->postJson($url, $extension + ['advantage' => 'Declaração do Gestor'])
            ->assertCreated()->assertJsonPath('amendment_amount', '0.0000')->assertJsonPath('amendment.advantage', 'Declaração do Gestor');
        $this->assertDatabaseHas('tb_ADT_ATA', ['ADT' => 1, 'VANTAJOSIDADE' => 'Declaração do Gestor']);
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 0);
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'FINAL_ATA' => '2027-11-06']);
    }

    public function test_a_deleted_amendment_number_can_be_reused_without_reactivating_its_products(): void
    {
        $records = $this->linkedRecords();
        $this->deleteJson(route('atas.amendments.destroy', ['ata' => $this->ata, 'amendment' => 1]), ['version' => 1])->assertOk();
        DB::table('tb_ATA')->where('ID', $this->ata)->update(['INICIO_ATA' => '2025-11-07']);
        $oldItem = $records['tb_PRODUTOS_ATA'][1];
        // Recreate with the exact same item identity that was used by the former amendment.
        DB::table('tb_PRODUTOS_ATA')->where('ID', $records['tb_PRODUTOS_ATA'][0])->update(['ITEM' => '2']);
        $input = ['version' => 2, 'number' => 1, 'date' => '2026-11-05', 'process' => 'NOVO-01',
            'type' => SaveAtaAmendment::EQUAL_QUANTITY, 'start' => '2026-11-07', 'end' => '2027-11-06', 'period' => '12 meses',
            'advantage' => 'Declaração do Gestor'];
        $url = route('atas.amendments.store', $this->ata);
        $this->postJson($url, $input)->assertCreated()->assertJsonPath('amendment_number', 1);
        $this->postJson($url, array_replace($input, ['version' => 3]))->assertConflict();
        $this->assertSoftDeleted('tb_PRODUTOS_ATA', ['ID' => $oldItem, 'ID_ADITIVO' => $records['tb_ADITIVOS_ATA'][0]]);
        $this->assertSoftDeleted('tb_ADT_ATA', ['ID' => $records['tb_ADT_ATA'][0]]);
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ID_ATA' => $this->ata, 'ADITIVO' => 1, 'ITEM' => '2', 'deleted_at' => null, 'PROC_ADT' => 'NOVO-01']);
        $this->getJson(route('atas.lookup', ['number' => '107/25']))->assertOk()
            ->assertJsonPath('amendment_numbers', [1])->assertJsonPath('amendments.1.process', 'NOVO-01')
            ->assertJsonPath('amendment_updated_total', '4.0000');
    }

    public function test_amendment_final_cannot_extend_the_ata_beyond_two_calendar_years(): void
    {
        $store = route('atas.amendments.store', $this->ata);
        $input = ['version' => 1, 'number' => 1, 'date' => '2025-02-27', 'process' => 'SALDO',
            'type' => SaveAtaAmendment::EXTENSION, 'start' => '2025-03-01', 'end' => '2026-03-01',
            'period' => '12 meses', 'advantage' => 'Pesquisa de Preços'];
        $this->postJson($store, $input)->assertUnprocessable()->assertJsonValidationErrors('end');
        DB::table('tb_ATA')->where('ID', $this->ata)->update(['INICIO_ATA' => '2024-02-29', 'FINAL_ATA' => '2025-02-28']);
        $this->getJson(route('atas.lookup', ['number' => '107/25']))->assertOk()
            ->assertJsonPath('amendment_maximum_end', '2026-02-28');
        $this->postJson($store, $input)->assertUnprocessable()
            ->assertJsonPath('errors.end.0', 'A vigência total da ATA não pode ultrapassar 2 anos. Informe FINAL até 28/02/2026.');
        $this->assertDatabaseCount('tb_ADT_ATA', 0);
        $this->assertDatabaseCount('activity_events', 0);
        $this->postJson($store, array_replace($input, ['end' => '2026-02-28']))->assertCreated();
        $edit = route('atas.amendments.update', ['ata' => $this->ata, 'amendment' => 1]);
        $this->patchJson($edit, array_replace($input, ['version' => 2]))->assertUnprocessable()->assertJsonValidationErrors('end');
        $this->assertDatabaseHas('tb_ADT_ATA', ['ADT' => 1, 'FINAL' => '2026-02-28']);
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'version' => 2, 'FINAL_ATA' => '2026-02-28']);
    }

    public function test_positive_balance_records_the_excluded_original_and_preserves_its_snapshot_in_history(): void
    {
        $records = $this->linkedRecords();
        [$original, $otherAmendmentProduct] = $records['tb_PRODUTOS_ATA'];
        DB::table('tb_ATA')->where('ID', $this->ata)->update([
            'INICIO_ATA' => '2025-11-07', 'FINAL_ATA' => '2026-11-06', 'PERIODO_ATA' => '12 meses',
        ]);
        $input = ['version' => 1, 'number' => 2, 'date' => '2026-11-05', 'process' => 'SALDO-02',
            'type' => SaveAtaAmendment::EXTENSION, 'start' => '2026-11-07', 'end' => '2027-11-06',
            'period' => '12 meses', 'advantage' => 'Pesquisa de Preços'];
        $url = route('atas.amendments.store', $this->ata);
        $this->postJson($url, $input + ['excluded_product_ids' => [$otherAmendmentProduct]])
            ->assertUnprocessable()->assertJsonValidationErrors('excluded_product_ids');
        $this->assertDatabaseCount('tb_ADT_ATA', 1);
        $this->assertDatabaseCount('ata_amendment_excluded_products', 0);

        $this->postJson($url, $input + ['excluded_product_ids' => [$original]])->assertCreated()
            ->assertJsonPath('amendment.excluded_product_ids', [$original])->assertJsonPath('amendment_amount', '0.0000');
        $row = DB::table('ata_amendment_excluded_products')->sole();
        $this->assertSame($this->ata, (int) $row->ata_id);
        $this->assertSame($original, (int) $row->product_id);
        $this->assertSame(2, (int) $row->amendment_number);
        $this->assertDatabaseHas('tb_ADT_ATA', ['ID' => $row->amendment_id, 'ADT' => 2, 'Proc_ADT' => 'SALDO-02']);
        $snapshot = json_decode($row->product_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('Produto', $snapshot['DISCRIMINACAO']);
        $this->assertSame('1.0000', $snapshot['QTD']);
        $this->assertSame('2.0000', $snapshot['VL_UNIT']);
        $this->assertSame([$original], ActivityEvent::where('action', 'ata_amendment_saved')->sole()->metadata['excluded_product_ids']);
        $lookup = route('atas.lookup', ['number' => '107/25']);
        $this->getJson($lookup)->assertOk()->assertJsonPath('amendments.2.excluded_product_ids', [$original]);

        $edit = route('atas.amendments.update', ['ata' => $this->ata, 'amendment' => 2]);
        $this->patchJson($edit, array_replace($input, ['version' => 2]))
            ->assertOk()->assertJsonPath('amendment.excluded_product_ids', [$original]);
        $this->patchJson($edit, array_replace($input, ['version' => 3, 'excluded_product_ids' => []]))
            ->assertOk()->assertJsonPath('amendment.excluded_product_ids', []);
        $this->assertSoftDeleted('ata_amendment_excluded_products', ['id' => $row->id]);
        $this->assertSame($row->product_snapshot, DB::table('ata_amendment_excluded_products')->where('id', $row->id)->value('product_snapshot'));
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ID' => $original, 'deleted_at' => null]);
        $this->getJson($lookup)->assertOk()->assertJsonMissingPath('amendments.2.excluded_product_ids');
    }

    public function test_positive_balance_quantities_are_validated_saved_and_recalled_per_amendment(): void
    {
        $records = $this->linkedRecords();
        $original = $records['tb_PRODUTOS_ATA'][0];
        DB::table('tb_ATA')->where('ID', $this->ata)->update([
            'INICIO_ATA' => '2025-11-07', 'FINAL_ATA' => '2026-11-06', 'PERIODO_ATA' => '12 meses',
        ]);
        $input = ['version' => 1, 'number' => 2, 'date' => '2026-11-05', 'process' => 'SALDO-02',
            'type' => SaveAtaAmendment::EXTENSION, 'start' => '2026-11-07', 'end' => '2027-11-06',
            'period' => '12 meses', 'advantage' => 'Declaração do Gestor'];
        $url = route('atas.amendments.store', $this->ata);
        $this->postJson($url, $input + ['balance_quantities' => [['product_id' => $original, 'quantity' => '1,0001']]])
            ->assertUnprocessable()->assertJsonValidationErrors('balance_quantities');
        $quantities = ['balance_quantities' => [['product_id' => $original, 'quantity' => '0,7500']]];
        $this->postJson($url, $input + $quantities + ['excluded_product_ids' => [$original]])
            ->assertUnprocessable()->assertJsonValidationErrors('balance_quantities');
        $this->assertDatabaseCount('tb_ADT_ATA', 1);
        $this->assertDatabaseCount('ata_amendment_excluded_products', 0);
        $this->postJson($url, $input + $quantities)->assertCreated()
            ->assertJsonPath('amendment.balance_products.0.balance_quantity', '0.7500');
        DB::table('tb_PRODUTOS_ATA')->where('ID', $original)->update(['DISCRIMINACAO' => 'Descrição atualizada']);
        $lookup = route('atas.lookup', ['number' => '107/25']);
        $this->getJson($lookup)->assertOk()
            ->assertJsonPath('amendments.2.balance_products.0.id', $original)
            ->assertJsonPath('amendments.2.balance_products.0.balance_quantity', '0.7500')
            ->assertJsonPath('amendments.2.balance_products.0.description', 'Produto');
        $edit = route('atas.amendments.update', ['ata' => $this->ata, 'amendment' => 2]);
        $this->patchJson($edit, array_replace($input, ['version' => 2]))->assertOk()
            ->assertJsonPath('amendment.balance_products.0.balance_quantity', '0.7500');
        $this->patchJson($edit, array_replace($input, ['version' => 3, 'balance_quantities' => []]))->assertOk()
            ->assertJsonMissingPath('amendment.balance_products');
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ID' => $original, 'QTD' => '1.0000', 'deleted_at' => null]);
    }

    public function test_recreating_a_positive_balance_amendment_saves_quantities_and_exclusions_under_its_new_id(): void
    {
        $records = $this->linkedRecords();
        $original = $records['tb_PRODUTOS_ATA'][0];
        $product = (array) DB::table('tb_PRODUTOS_ATA')->where('ID', $original)->sole([
            'ID_ATA', 'ATA', 'PROCESSO', 'LICITACAO', 'N_LICIT', 'DISCRIMINACAO', 'UND', 'QTD', 'VL_UNIT', 'VL_TOTAL', 'source_row',
        ]);
        $excluded = DB::table('tb_PRODUTOS_ATA')->insertGetId($product + ['ITEM' => '3', 'ADITIVO' => 0], 'ID');
        DB::table('tb_ATA')->where('ID', $this->ata)->update([
            'INICIO_ATA' => '2025-11-07', 'FINAL_ATA' => '2026-11-06', 'PERIODO_ATA' => '12 meses',
        ]);
        $input = ['version' => 1, 'number' => 2, 'date' => '2026-11-05', 'process' => 'SALDO-02',
            'type' => SaveAtaAmendment::EXTENSION, 'start' => '2026-11-07', 'end' => '2027-11-06',
            'period' => '12 meses', 'advantage' => 'Declaração do Gestor', 'excluded_product_ids' => [$excluded]];
        $url = route('atas.amendments.store', $this->ata);
        $oldId = $this->postJson($url, $input)->assertCreated()->json('signed_amendment_id');
        $this->deleteJson(route('atas.amendments.destroy', ['ata' => $this->ata, 'amendment' => 2]), ['version' => 2])->assertOk();

        $new = $this->postJson($url, array_replace($input, [
            'version' => 3, 'balance_quantities' => [['product_id' => $original, 'quantity' => '0,7500']],
        ]))->assertCreated()
            ->assertJsonPath('amendment.balance_products.0.balance_quantity', '0.7500')
            ->assertJsonPath('amendment.excluded_product_ids', [$excluded])
            ->assertJsonPath('amendment_amount', '0.0000')
            ->assertJsonPath('amendment_updated_total', '6.0000');
        $newId = $new->json('signed_amendment_id');
        $this->assertNotSame($oldId, $newId);
        $this->assertSoftDeleted('tb_ADT_ATA', ['ID' => $oldId]);
        $this->assertDatabaseHas('ata_amendment_excluded_products', ['amendment_id' => $oldId, 'product_id' => $excluded]);
        $this->assertDatabaseHas('ata_amendment_excluded_products', ['amendment_id' => $newId, 'product_id' => $excluded]);
        $this->getJson(route('atas.lookup', ['number' => '107/25']))->assertOk()
            ->assertJsonPath('amendments.2.balance_products.0.id', $original)
            ->assertJsonPath('amendments.2.balance_products.0.balance_quantity', '0.7500')
            ->assertJsonPath('amendments.2.excluded_product_ids', [$excluded])
            ->assertJsonPath('amendment_updated_total', '6.0000');
    }

    public function test_amendment_delete_preserves_a_manually_corrected_expiration_and_is_atomic_with_audit(): void
    {
        $records = $this->linkedRecords();
        $importedId = $records['tb_ADITIVOS_ATA'][0];
        $legacyId = $records['tb_ADT_ATA'][0];
        DB::table('tb_ADT_ATA')->where('ID', $legacyId)->update(['FINAL' => '2027-11-06']);
        $basis = json_encode(['rule' => UpdateAtaValidity::RULE, 'start' => '2025-11-07', 'end' => '2027-11-06',
            'period' => '1 ano', 'amendments' => [['id' => $importedId, 'number' => 1, 'end' => '2027-11-06']]], JSON_THROW_ON_ERROR);
        DB::table('tb_ATA')->where('ID', $this->ata)->update([
            'INICIO_ATA' => '2025-11-07', 'FINAL_ATA' => '2026-11-06', 'validity_basis' => $basis,
        ]);
        Event::listen('eloquent.creating: '.ActivityEvent::class, function () {
            throw new RuntimeException('Audit unavailable');
        });
        try {
            app(RemoveAtaAmendment::class)->remove($this->actor, $this->ata, 1, ['version' => 1], $this->fund);
            $this->fail('Audit failure must abort the amendment deletion.');
        } catch (RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.ActivityEvent::class);
        }
        $this->assertDatabaseHas('tb_ADT_ATA', ['ID' => $legacyId, 'deleted_at' => null]);
        $this->assertDatabaseHas('tb_ADITIVOS_ATA', ['ID' => $importedId, 'deleted_at' => null]);
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ID' => $records['tb_PRODUTOS_ATA'][1], 'deleted_at' => null]);
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'version' => 1, 'FINAL_ATA' => '2026-11-06', 'validity_basis' => $basis]);
        $this->deleteJson(route('atas.amendments.destroy', ['ata' => $this->ata, 'amendment' => 1]), ['version' => 1])->assertOk();
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'version' => 2, 'FINAL_ATA' => '2026-11-06', 'validity_basis' => null]);
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
