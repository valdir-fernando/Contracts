<?php

namespace Tests\Feature;

use App\Infrastructure\Atas\AtaEditorQuery;
use App\Infrastructure\Atas\ConsolidateAtaAmendments;
use App\Infrastructure\Atas\SaveAtaAmendment;
use App\Infrastructure\Atas\UpdateAtaValidity;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class ConsolidateAtaAmendmentsTest extends TestCase
{
    use RefreshDatabase;

    private function ata(): int
    {
        return DB::table('tb_ATA')->insertGetId(['ATA' => '107/2025', 'PROCESSO' => '2025007440', 'LICITACAO' => 'Pregão', 'N_LICIT' => '12',
            'DATA_ATA' => '2025-11-07', 'INICIO_ATA' => '2025-11-07', 'FINAL_ATA' => '2027-11-06',
            'PERIODO_ATA' => '12 meses', 'CNPJ_FUNDO' => '04252011000110', 'source_files' => '[]', 'quality_issues' => '[]',
            'import_hash' => str_repeat('a', 64), 'version' => 1], 'ID');
    }

    private function imported(?int $ata, array $changes = []): int
    {
        return DB::table('tb_ADITIVOS_ATA')->insertGetId(array_replace(['ID_ATA' => $ata, 'ATA' => '107/2025',
            'NUMERO' => 1, 'PROCESSO_ORIGEM' => '2025007440', 'PROCESSO_ADITIVO' => 'ADT-01',
            'INICIO_ADITIVO' => '2026-11-07', 'FINAL_ADITIVO' => '2027-11-06',
            'source_path' => 'original.pdf', 'source_sha256' => str_repeat('b', 64), 'source_size' => 123,
            'extraction' => 'pdf_text', 'link_status' => $ata === null ? 'ata_ausente' : 'vinculado',
            'issues' => '[]', 'products_import' => '{"total":"0.0001"}'], $changes), 'ID');
    }

    private function registered(array $changes = []): int
    {
        return DB::table('tb_ADT_ATA')->insertGetId(array_replace(['ATA' => '107/25', 'Proc_ATA' => '2025007440',
            'ADT' => 1, 'Data_ADT' => '2026-11-01', 'Proc_ADT' => 'ADT-01', 'Tipo_ADT' => SaveAtaAmendment::EQUAL_QUANTITY,
            'INICIO' => '2026-11-07', 'FINAL' => '2027-11-06', 'Período' => '12 meses',
            'Doc_ADT' => "%PDF-\0binary", 'VANTAJOSIDADE' => 'Pesquisa de Preços'], $changes), 'ID');
    }

    private function product(int $ata, int $import, array $changes = []): int
    {
        return DB::table('tb_PRODUTOS_ATA')->insertGetId(array_replace(['ID_ATA' => $ata, 'ID_ADITIVO' => $import,
            'ATA' => '107/2025', 'ADITIVO' => 1, 'PROCESSO' => '2025007440', 'LICITACAO' => 'Pregão', 'N_LICIT' => '12',
            'ITEM' => '1', 'DISCRIMINACAO' => 'Produto', 'UND' => 'UN', 'QTD' => '0.0001',
            'VL_UNIT' => '1.0000', 'VL_TOTAL' => '0.0001', 'source_row' => '{"original":true}'], $changes), 'ID');
    }

    public function test_merges_by_identity_without_assuming_equal_ids_and_preserves_all_evidence(): void
    {
        $ata = $this->ata();
        $this->registered(['ATA' => '999/25', 'ADT' => 8]);
        $registered = $this->registered();
        $import = $this->imported($ata);
        $product = $this->product($ata, $import);
        $archive = (array) DB::table('tb_ADITIVOS_ATA')->sole();
        $beforeProduct = (array) DB::table('tb_PRODUTOS_ATA')->sole();
        DB::table('tb_ATA')->where('ID', $ata)->update(['validity_basis' => json_encode([
            'rule' => UpdateAtaValidity::RULE, 'start' => '2025-11-07', 'end' => '2027-11-06',
            'amendments' => [['id' => $import, 'number' => 1, 'end' => '2027-11-06']]], JSON_THROW_ON_ERROR)]);

        $this->assertSame([$import => $registered], (new ConsolidateAtaAmendments)->run());
        $unified = DB::table('tb_ADT_ATA')->where('ID', $registered)->first();
        $this->assertSame($ata, $unified->ID_ATA);
        $this->assertSame("%PDF-\0binary", $unified->Doc_ADT);
        $this->assertSame('Pesquisa de Preços', $unified->VANTAJOSIDADE);
        $this->assertSame('0.0001', json_decode($unified->products_import, true)['total']);
        $this->assertSame($archive, json_decode($unified->import_snapshot, true));
        $this->assertSame($archive, (array) DB::table('tb_ADITIVOS_ATA')->sole());
        $afterProduct = (array) DB::table('tb_PRODUTOS_ATA')->where('ID', $product)->first();
        $this->assertSame($registered, $afterProduct['amendment_id']);
        unset($afterProduct['amendment_id'], $beforeProduct['amendment_id']);
        $this->assertSame($beforeProduct, $afterProduct);
        $basis = json_decode(DB::table('tb_ATA')->where('ID', $ata)->value('validity_basis'), true);
        $this->assertSame($registered, $basis['amendments'][0]['id']);
        $this->assertSame($import, $basis['amendments'][0]['imported_id']);
        $this->assertDatabaseHas('tb_ATA', ['ID' => $ata, 'version' => 1, 'FINAL_ATA' => '2027-11-06']);
        $this->assertSame([$import => $registered], (new ConsolidateAtaAmendments)->run());
        $this->assertDatabaseCount('tb_ADT_ATA', 2);
        $this->assertSame(1, ActivityEvent::where('action', 'ata_amendments_consolidated')->count());
    }

    public function test_unmatched_and_soft_deleted_imports_are_preserved_without_reactivation(): void
    {
        $ata = $this->ata();
        $missing = $this->imported(null);
        $deleted = $this->imported($ata, ['NUMERO' => 2, 'source_path' => 'deleted.pdf', 'deleted_at' => now()]);
        $map = (new ConsolidateAtaAmendments)->run();
        $this->assertDatabaseHas('tb_ADT_ATA', ['ID' => $map[$missing], 'imported_id' => $missing, 'ID_ATA' => null, 'link_status' => 'ata_ausente']);
        $this->assertSoftDeleted('tb_ADT_ATA', ['ID' => $map[$deleted], 'imported_id' => $deleted]);
        $this->assertDatabaseCount('tb_ADITIVOS_ATA', 2);
        $actor = User::create(['name' => 'Admin', 'username' => 'PENDING', 'password' => 'SyntheticPassword123', 'user_type' => 'Administrador'])->fresh();
        $result = app(AtaEditorQuery::class)->find($actor, '107/25');
        $this->assertSame([], $result['amendment_numbers']);
    }

    public function test_divergent_or_ambiguous_records_abort_without_partial_merges(): void
    {
        $ata = $this->ata();
        $registered = $this->registered(['FINAL' => '2027-10-01']);
        $this->imported($ata);
        try {
            (new ConsolidateAtaAmendments)->run();
            $this->fail('Divergent dates must block conversion.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Campos divergentes', $error->getMessage());
        }
        $this->assertDatabaseHas('tb_ADT_ATA', ['ID' => $registered, 'imported_id' => null, 'FINAL' => '2027-10-01']);
        $this->assertDatabaseCount('activity_events', 0);
        $this->registered();
        $this->expectExceptionMessage('Aditivo ambíguo');
        (new ConsolidateAtaAmendments)->run();
    }

    public function test_audit_failure_rolls_back_records_products_and_validity_mapping(): void
    {
        $ata = $this->ata();
        $registered = $this->registered();
        $import = $this->imported($ata);
        $product = $this->product($ata, $import);
        Event::listen('eloquent.creating: '.ActivityEvent::class, fn () => throw new RuntimeException('Audit unavailable'));
        try {
            (new ConsolidateAtaAmendments)->run();
            $this->fail('Audit failure must roll back consolidation.');
        } catch (RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.ActivityEvent::class);
        }
        $this->assertDatabaseHas('tb_ADT_ATA', ['ID' => $registered, 'imported_id' => null]);
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ID' => $product, 'amendment_id' => null, 'ID_ADITIVO' => $import]);
    }

    public function test_last_imported_amendment_can_be_edited_without_duplicate_products_or_archive_writes(): void
    {
        $ata = $this->ata();
        $registered = $this->registered();
        $import = $this->imported($ata);
        $product = $this->product($ata, $import);
        DB::table('tb_ATA')->where('ID', $ata)->update(['validity_basis' => json_encode([
            'rule' => UpdateAtaValidity::RULE, 'start' => '2025-11-07', 'end' => '2027-11-06', 'period' => '12 meses',
            'amendments' => [['id' => $import, 'number' => 1, 'end' => '2027-11-06']]], JSON_THROW_ON_ERROR)]);
        (new ConsolidateAtaAmendments)->run();
        $archive = (array) DB::table('tb_ADITIVOS_ATA')->sole();
        $actor = User::create(['name' => 'Admin', 'username' => 'CONSOLIDATION', 'password' => 'SyntheticPassword123', 'user_type' => 'Administrador'])->fresh();
        $fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo', 'name_key' => 'FUNDO', 'raw_payload' => '{}']);
        $this->actingAs($actor)->withSession(['fund_id' => $fund]);
        $payload = ['version' => 1, 'number' => 1, 'date' => '2026-11-02', 'process' => 'CORRIGIDO',
            'type' => SaveAtaAmendment::EQUAL_QUANTITY, 'start' => '2026-11-07', 'end' => '2027-11-06',
            'period' => '12 meses', 'advantage' => 'Declaração do Gestor'];
        $this->patchJson(route('atas.amendments.update', ['ata' => $ata, 'amendment' => 1]), $payload)->assertOk()
            ->assertJsonPath('amendment_updated_total', '0.0001');
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 1);
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ID' => $product, 'amendment_id' => $registered, 'PROC_ADT' => 'CORRIGIDO']);
        $this->assertSame($archive, (array) DB::table('tb_ADITIVOS_ATA')->sole());
        $this->assertSame("%PDF-\0binary", DB::table('tb_ADT_ATA')->where('ID', $registered)->value('Doc_ADT'));
        $this->assertDatabaseHas('tb_ATA', ['ID' => $ata, 'version' => 2, 'FINAL_ATA' => '2027-11-06']);
        $this->patchJson(route('atas.amendments.update', ['ata' => $ata, 'amendment' => 1]), $payload)->assertConflict();
        $this->assertSame(1, ActivityEvent::where('action', 'ata_amendment_updated')->count());
    }

    public function test_historical_amendment_keeps_its_type_and_products_when_edited(): void
    {
        $ata = $this->ata();
        $registered = $this->registered(['Tipo_ADT' => 'Prorrogação de vigência']);
        $import = $this->imported($ata);
        $product = $this->product($ata, $import);
        DB::table('tb_ATA')->where('ID', $ata)->update(['validity_basis' => json_encode([
            'rule' => UpdateAtaValidity::RULE, 'start' => '2025-11-07', 'end' => '2027-11-06', 'period' => '12 meses',
            'amendments' => [['id' => $import, 'number' => 1, 'end' => '2027-11-06']]], JSON_THROW_ON_ERROR)]);
        (new ConsolidateAtaAmendments)->run();
        $actor = User::create(['name' => 'Admin', 'username' => 'HISTORICAL', 'password' => 'SyntheticPassword123', 'user_type' => 'Administrador'])->fresh();
        $fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo', 'name_key' => 'FUNDO', 'raw_payload' => '{}']);
        $this->actingAs($actor)->withSession(['fund_id' => $fund]);
        $url = route('atas.amendments.update', ['ata' => $ata, 'amendment' => 1]);
        $payload = [
            'version' => 1, 'number' => 1, 'date' => '2026-11-02', 'process' => 'CORRIGIDO',
            'type' => 'Prorrogação de vigência', 'start' => '2026-11-07', 'end' => '2027-11-06', 'period' => '12 meses',
        ];
        $this->postJson(route('atas.amendments.store', $ata), array_replace($payload, ['number' => 2, 'end' => '2027-11-07']))
            ->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->patchJson($url, array_replace($payload, ['type' => SaveAtaAmendment::EXTENSION, 'advantage' => 'Pesquisa de Preços']))
            ->assertConflict()->assertJsonPath('message', 'Para editar este aditivo, mantenha o Tipo_ADT original. A troca de tipo exige conciliar os produtos e valores já registrados.');
        $this->assertDatabaseHas('tb_ATA', ['ID' => $ata, 'version' => 1]);
        $this->assertDatabaseHas('tb_ADT_ATA', ['ID' => $registered, 'Tipo_ADT' => 'Prorrogação de vigência']);
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ID' => $product, 'deleted_at' => null, 'amendment_id' => $registered]);
        $this->assertSame(0, ActivityEvent::where('action', 'ata_amendment_updated')->count());

        $before = (array) DB::table('tb_PRODUTOS_ATA')->where('ID', $product)->first();
        $this->patchJson($url, $payload)->assertOk()
            ->assertJsonPath('amendment.type', 'Prorrogação de vigência')
            ->assertJsonPath('amendment_updated_total', '0.0001');
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 1);
        $after = (array) DB::table('tb_PRODUTOS_ATA')->where('ID', $product)->first();
        $this->assertSame(array_replace($before, ['DATA_ADT' => '2026-11-02', 'PROC_ADT' => 'CORRIGIDO', 'updated_at' => $after['updated_at']]), $after);
        $this->assertDatabaseHas('tb_ADT_ATA', ['ID' => $registered, 'Proc_ADT' => 'CORRIGIDO', 'Tipo_ADT' => 'Prorrogação de vigência', 'VANTAJOSIDADE' => 'Pesquisa de Preços']);
        $this->assertSame("%PDF-\0binary", DB::table('tb_ADT_ATA')->where('ID', $registered)->value('Doc_ADT'));
        $this->assertDatabaseHas('tb_ATA', ['ID' => $ata, 'version' => 2, 'FINAL_ATA' => '2027-11-06']);
        $this->assertSame([$product], ActivityEvent::where('action', 'ata_amendment_updated')->sole()->metadata['product_ids']);
    }
}
