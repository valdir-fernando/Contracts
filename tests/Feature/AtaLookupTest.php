<?php

namespace Tests\Feature;

use App\Infrastructure\Atas\AtaEditorQuery;
use App\Infrastructure\Atas\UpdateAtaValidity;
use App\Models\AccessScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AtaLookupTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private int $fund;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->actor = User::create(['name' => 'Consulta', 'username' => 'ATA_LOOKUP', 'password' => 'SyntheticPassword123', 'user_type' => 'Administrador']);
        $this->fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo atual', 'name_key' => 'ATUAL', 'raw_payload' => '{}']);
        $this->actingAs($this->actor)->withSession(['fund_id' => $this->fund]);
    }

    private function ata(array $extra = []): int
    {
        return DB::table('tb_ATA')->insertGetId(array_replace(['ATA' => '001/2026', 'DATA_ATA' => '2026-01-02',
            'PROCESSO' => 'PROC-SALVO', 'LICITACAO' => 'PREGÃO', 'N_LICIT' => '123/2026', 'fund_id' => $this->fund,
            'FUNDO' => 'Fundo histórico', 'CNPJ_FUNDO' => '11222333000181', 'END_FUNDO' => 'Endereço histórico',
            'GESTOR' => 'Gestor histórico', 'CPF_GESTOR' => '52998224725', 'RG_GESTOR' => 'RG-PRIVADO',
            'CPF_SOCIO' => '52998224725', 'RG_SOCIO' => 'SOCIO-PRIVADO', 'FORNECEDOR' => 'Fornecedor salvo',
            'CNPJ_FORNECEDOR' => '11222333000181', 'EMAIL' => 'salvo@example.test', 'TELEFONE1' => '62999990000',
            'source_files' => '["CAMINHO-PRIVADO"]', 'quality_issues' => '["INTERNO"]', 'import_hash' => str_repeat('a', 64), 'Doc_ATA' => 'PDF-PRIVADO'], $extra), 'ID');
    }

    public function test_lookup_normalizes_number_and_projects_saved_snapshots_with_exact_total_without_writing(): void
    {
        $id = $this->ata();
        DB::table('tb_FUNDOS_ATA')->insert(['ID_ATA' => $id, 'CNPJ_FUNDO' => '11222333000181', 'FUNDO' => 'Participante salvo', 'papel' => 'gerenciador']);
        foreach (['0.1001', '0.2002'] as $item => $amount) {
            DB::table('tb_PRODUTOS_ATA')->insert(['ID_ATA' => $id, 'ATA' => '001/2026', 'PROCESSO' => 'PROC', 'LICITACAO' => 'PREGÃO',
                'N_LICIT' => '123', 'ITEM' => (string) $item, 'DISCRIMINACAO' => '<Produto>', 'UND' => 'UN',
                'QTD' => '0.0001', 'VL_UNIT' => '1.1234', 'VL_TOTAL' => $amount, 'source_row' => '["FONTE-PRIVADA"]']);
        }
        $this->getJson(route('atas.lookup', ['number' => '1 / 26']))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('id', $id)->assertJsonPath('process', 'PROC-SALVO')->assertJsonPath('date', '2026-01-02')
            ->assertJsonPath('contractor.nome_gestor', 'Gestor histórico')->assertJsonPath('contractor.cpf_gestor', '52998224725')
            ->assertJsonPath('supplier.fornecedor_nome_snapshot', 'Fornecedor salvo')->assertJsonPath('supplier.supplier_email', 'salvo@example.test')
            ->assertJsonPath('participants.0.FUNDO', 'Participante salvo')->assertJsonPath('total', '0.3003')->assertJsonCount(2, 'products')
            ->assertJsonPath('amendment_updated_total', '0.3003')->assertJsonPath('amendment_base_total', '0.0002')
            ->assertJsonMissingPath('Doc_ATA')->assertJsonMissingPath('source_files')->assertDontSee('PDF-PRIVADO')->assertDontSee('CAMINHO-PRIVADO')->assertDontSee('FONTE-PRIVADA');
        $this->assertDatabaseCount('activity_events', 0);
        $this->assertDatabaseCount('ata_participant_drafts', 0);
        $this->assertDatabaseCount('tb_Participantes', 0);
    }

    public function test_lookup_only_includes_original_active_products_in_list_and_total(): void
    {
        $id = $this->ata();
        $base = ['ID_ATA' => $id, 'ATA' => '001/2026', 'PROCESSO' => 'PROC', 'LICITACAO' => 'PREGAO',
            'N_LICIT' => '123', 'ITEM' => '1', 'DISCRIMINACAO' => 'Original', 'UND' => 'UN',
            'QTD' => '1.0000', 'VL_UNIT' => '10.1234', 'VL_TOTAL' => '10.1234', 'source_row' => '{}'];
        DB::table('tb_PRODUTOS_ATA')->insert($base);
        DB::table('tb_PRODUTOS_ATA')->insert(array_replace($base, ['ADITIVO' => 1, 'DISCRIMINACAO' => 'Aditivo', 'VL_TOTAL' => '999.0000']));
        DB::table('tb_PRODUTOS_ATA')->insert(array_replace($base, ['ADITIVO' => 2, 'VL_TOTAL' => '0.0001']));
        DB::table('tb_PRODUTOS_ATA')->insert(array_replace($base, ['ADITIVO' => 3, 'deleted_at' => now(), 'VL_TOTAL' => '200.0000']));
        DB::table('tb_PRODUTOS_ATA')->insert(array_replace($base, ['ADITIVO' => 4, 'VL_TOTAL' => '']));
        DB::table('tb_ADT_ATA')->insert(['ATA' => '001/2026', 'Proc_ATA' => 'PROC-SALVO', 'ADT' => 5]);
        DB::table('tb_PRODUTOS_ATA')->insert(array_replace($base, ['ITEM' => '2', 'deleted_at' => now(), 'VL_TOTAL' => '100.0000']));

        $this->getJson(route('atas.lookup', ['number' => '1/26']))->assertOk()
            ->assertJsonCount(1, 'products')->assertJsonPath('products.0.description', 'Original')
            ->assertJsonPath('total', '10.1234')->assertJsonPath('amendment_updated_total', '1009.1235')->assertJsonCount(1, 'amendment_base_products')
            ->assertJsonPath('amendment_numbers', [5])
            ->assertJsonPath('amendment_base_products.0.description', 'Original')
            ->assertJsonPath('amendment_base_products.0.quantity', '1.0000')->assertJsonPath('amendment_base_products.0.special_quantity', null);
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 6);
    }

    public function test_a_new_amendment_uses_original_active_products_and_quantities(): void
    {
        $ata = $this->ata(['QTD_ESPECIAL_NOME' => 'veículos']);
        $other = $this->ata(['ATA' => '002/2026']);
        $base = ['ID_ATA' => $ata, 'ATA' => '001/2026', 'PROCESSO' => 'PROC-SALVO', 'LICITACAO' => 'PREGAO', 'N_LICIT' => '123',
            'DISCRIMINACAO' => 'Produto', 'UND' => 'UN', 'QTD' => '5.0000', 'QTD_ESPECIAL' => '7.0000', 'VL_UNIT' => '10.1234',
            'VL_TOTAL' => '50.6100', 'source_row' => '{}'];
        DB::table('tb_PRODUTOS_ATA')->insert(array_replace($base, ['ITEM' => '10']));
        DB::table('tb_PRODUTOS_ATA')->insert(array_replace($base, ['ITEM' => '11', 'deleted_at' => now()]));
        foreach ([1, 2, 3] as $number) {
            $amendment = DB::table('tb_ADT_ATA')->insertGetId(['ID_ATA' => $ata, 'ATA' => '001/2026', 'Proc_ATA' => 'PROC-SALVO', 'ADT' => $number, 'source_path' => "adt-{$number}",
                'source_sha256' => str_repeat((string) $number, 64), 'source_size' => 1, 'extraction' => 'pdf',
                'link_status' => 'vinculado', 'issues' => '[]', 'deleted_at' => $number === 3 ? now() : null], 'ID');
            DB::table('tb_PRODUTOS_ATA')->insert(array_replace($base, ['amendment_id' => $amendment, 'ADITIVO' => $number,
                'ITEM' => (string) $number, 'deleted_at' => $number === 2 ? now() : null]));
        }
        DB::table('tb_PRODUTOS_ATA')->insert(array_replace($base, ['ADITIVO' => 2, 'ITEM' => '4', 'deleted_at' => now()]));
        DB::table('tb_PRODUTOS_ATA')->insert(array_replace($base, ['ID_ATA' => $other, 'ITEM' => '5']));
        $this->getJson(route('atas.lookup', ['number' => '1/26']))->assertOk()
            ->assertJsonPath('amendment_numbers', [2, 1])->assertJsonCount(1, 'amendment_base_products')
            ->assertJsonPath('amendment_base_products.0.item', '10')->assertJsonPath('amendment_base_products.0.unit_price', '10.1234')
            ->assertJsonPath('special_quantity_name', 'veículos')
            ->assertJsonPath('amendment_base_products.0.special_quantity', '7.0000')
            ->assertJsonPath('amendment_base_products.0.quantity', '5.0000')
            ->assertJsonPath('products.0.total', '50.6100')
            ->assertJsonPath('amendment_base_products.0.total', '50.6170');
    }

    public function test_lookup_recovers_the_original_end_from_an_amended_legacy_validity_basis(): void
    {
        $basis = ['rule' => UpdateAtaValidity::RULE, 'start' => '2025-06-02',
            'end' => '2027-06-02', 'period' => '1 (um) ano, contado a partir da divulgação no PNCP',
            'amendments' => [['number' => 1, 'end' => '2027-06-02']]];
        $id = $this->ata(['INICIO_ATA' => '2025-06-02', 'FINAL_ATA' => '2027-06-02', 'validity_basis' => json_encode($basis)]);
        $lookup = route('atas.lookup', ['number' => '1/26']);
        $this->getJson($lookup)->assertOk()->assertJsonPath('end_date', '2026-06-02')
            ->assertJsonPath('end_date_derived', true)->assertJsonMissingPath('validity_basis');
        $this->assertDatabaseHas('tb_ATA', ['ID' => $id, 'FINAL_ATA' => '2027-06-02']);

        DB::table('tb_ATA')->where('ID', $id)->update(['validity_basis' => json_encode(array_replace($basis, ['period' => 'Indefinido']))]);
        $this->getJson($lookup)->assertOk()->assertJsonPath('end_date', null)->assertJsonPath('end_date_derived', true);

        DB::table('tb_ATA')->where('ID', $id)->update(['FINAL_ATA' => '2026-05-29']);
        $this->getJson($lookup)->assertOk()->assertJsonPath('end_date', '2026-05-29')->assertJsonPath('end_date_derived', false);
    }

    public function test_lookup_preserves_saved_amendment_dates_without_supplying_date_constraints(): void
    {
        $this->ata(['INICIO_ATA' => '2026-01-01', 'FINAL_ATA' => '2026-12-31']);
        DB::table('tb_ADT_ATA')->insert(['ATA' => '1/26', 'Proc_ATA' => 'PROC-SALVO', 'ADT' => 1,
            'INICIO' => '2027-01-03', 'FINAL' => '2028-03-01']);
        $this->getJson(route('atas.lookup', ['number' => '1/26']))->assertOk()
            ->assertJsonPath('amendments.1.start', '2027-01-03')->assertJsonPath('amendments.1.end', '2028-03-01')
            ->assertJsonMissingPath('amendment_required_start')->assertJsonMissingPath('amendment_date_limit');
    }

    public function test_lookup_lists_only_distinct_active_amendment_numbers_for_the_open_ata(): void
    {
        $id = $this->ata();
        $this->getJson(route('atas.lookup', ['number' => '1/26']))->assertOk()->assertJsonPath('amendment_numbers', []);
        $legacy = ['ATA' => '1/26', 'Proc_ATA' => 'PROC-SALVO', 'ADT' => 2, 'Doc_ADT' => 'PDF-ADITIVO-PRIVADO',
            'Data_ADT' => '2026-06-01', 'Proc_ADT' => 'PROC-ADITIVO', 'Tipo_ADT' => 'Prorrogação',
            'INICIO' => '2026-07-01', 'FINAL' => '2027-06-30', 'Período' => '12 meses'];
        DB::table('tb_ADT_ATA')->insert($legacy);
        DB::table('tb_ADT_ATA')->insert(array_replace($legacy, ['ADT' => 3, 'deleted_at' => now()]));
        DB::table('tb_ADT_ATA')->insert(array_replace($legacy, ['ADT' => 4, 'Proc_ATA' => 'OUTRO']));
        DB::table('tb_ADT_ATA')->insert(array_replace($legacy, ['ADT' => 5, 'ATA' => '2/26']));
        $import = ['ID_ATA' => $id, 'ATA' => '1/26', 'Proc_ATA' => 'PROC-SALVO', 'source_size' => 1, 'extraction' => 'pdf', 'link_status' => 'vinculado', 'issues' => '[]'];
        foreach ([1, 2] as $number) {
            $metadata = $import + ['ADT' => $number, 'source_path' => "PRIVADO-{$number}", 'source_sha256' => str_repeat((string) $number, 64)];
            if ($number === 2) {
                DB::table('tb_ADT_ATA')->where('ATA', '1/26')->where('Proc_ATA', 'PROC-SALVO')->where('ADT', 2)->update($metadata);
            } else {
                DB::table('tb_ADT_ATA')->insert($metadata + ['Proc_ADT' => 'IMPORTADO']);
            }
        }
        DB::table('tb_ADT_ATA')->insert(array_replace($legacy, ['ADT' => 12]));
        $this->getJson(route('atas.lookup', ['number' => '1/26']))->assertOk()
            ->assertJsonPath('amendment_numbers', [12, 2, 1])
            ->assertJsonPath('amendments.2', ['date' => '2026-06-01', 'process' => 'PROC-ADITIVO', 'type' => 'Prorrogação',
                'start' => '2026-07-01', 'end' => '2027-06-30', 'period' => '12 meses'])
            ->assertJsonPath('amendments.1.process', 'IMPORTADO')->assertJsonMissingPath('amendments.1.date')
            ->assertJsonMissingPath('amendments.3')->assertJsonMissingPath('amendments.4')
            ->assertDontSee('PRIVADO-1')->assertDontSee('PDF-ADITIVO-PRIVADO');

        $other = $this->ata(['ATA' => '1/26', 'CNPJ_FUNDO' => '04252011000110']);
        DB::table('tb_ATA')->where('ID', $other)->update(['deleted_at' => now()]);
        $this->getJson(route('atas.lookup', ['number' => '1/26']))->assertOk()->assertJsonPath('amendment_numbers', [2, 1])
            ->assertJsonPath('amendments.2.process', 'PROC-ADITIVO')->assertJsonMissingPath('amendments.12');
    }

    public function test_lookup_preserves_unknown_admission_on_existing_atas_and_returns_explicit_choices(): void
    {
        $id = $this->ata();
        $this->getJson(route('atas.lookup', ['number' => '1/26']))->assertOk()->assertJsonPath('admitir_adesao', null);
        foreach (['SIM', 'NAO'] as $value) {
            DB::table('tb_ATA')->where('ID', $id)->update(['ADMITIR_ADESAO' => $value]);
            $this->getJson(route('atas.lookup', ['number' => '1/26']))->assertOk()->assertJsonPath('admitir_adesao', $value);
        }
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_ambiguous_numbers_never_select_an_arbitrary_ata_and_deleted_rows_are_ignored(): void
    {
        $id = $this->ata();
        $other = $this->ata(['ATA' => '1/26', 'PROCESSO' => 'OUTRO']);
        $this->getJson(route('atas.lookup', ['number' => '001/2026']))->assertConflict()->assertJsonMissingPath('supplier');
        DB::table('tb_ATA')->where('ID', $other)->update(['deleted_at' => now()]);
        $this->getJson(route('atas.lookup', ['number' => '1/26']))->assertOk()->assertJsonPath('id', $id);
        DB::table('tb_ATA')->where('ID', $id)->update(['deleted_at' => now()]);
        $this->getJson(route('atas.lookup', ['number' => '1/26']))->assertNotFound();
    }

    public function test_regular_user_only_reads_authorized_funds_with_personal_fields_masked(): void
    {
        $this->ata();
        $this->actor->update(['user_type' => 'Usuario']);
        $scope = AccessScope::create(['scope_key' => 'ata-lookup', 'fund_id' => $this->fund, 'fund' => 'Fundo atual']);
        $this->actor->scopes()->attach($scope);
        $otherFund = DB::table('contract_funds')->insertGetId(['name' => 'Outro', 'name_key' => 'OUTRO', 'raw_payload' => '{}']);
        $this->ata(['ATA' => '002/2026', 'fund_id' => $otherFund]);
        $this->getJson(route('atas.lookup', ['number' => '1/26']))->assertOk()->assertDontSee('52998224725')
            ->assertDontSee('RG-PRIVADO')->assertDontSee('SOCIO-PRIVADO')->assertJsonMissingPath('supplier.supplier_email');
        $this->getJson(route('atas.lookup', ['number' => '2/26', 'fund_id' => $this->fund]))->assertNotFound()->assertJsonMissingPath('contractor');
        $this->actor->scopes()->detach();
        $this->assertThrows(fn () => app(AtaEditorQuery::class)->find($this->actor, '1/26'), HttpException::class);
    }

    public function test_lookup_rejects_invalid_numbers_guests_and_stale_actor_sessions(): void
    {
        $this->ata();
        $this->getJson(route('atas.lookup', ['number' => '001']))->assertUnprocessable();
        $this->getJson(route('atas.lookup', ['number' => str_repeat('1', 33)]))->assertUnprocessable();
        DB::table('users')->where('id', $this->actor->id)->update(['session_version' => 1]);
        $this->getJson(route('atas.lookup', ['number' => '1/26']))->assertForbidden();
        auth()->logout();
        $this->getJson(route('atas.lookup', ['number' => '1/26']))->assertUnauthorized();
    }
}
