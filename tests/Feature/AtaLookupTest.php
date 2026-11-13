<?php

namespace Tests\Feature;

use App\Infrastructure\Atas\AtaEditorQuery;
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
            ->assertJsonMissingPath('Doc_ATA')->assertJsonMissingPath('source_files')->assertDontSee('PDF-PRIVADO')->assertDontSee('CAMINHO-PRIVADO')->assertDontSee('FONTE-PRIVADA');
        $this->assertDatabaseCount('activity_events', 0);
        $this->assertDatabaseCount('ata_participant_drafts', 0);
        $this->assertDatabaseCount('tb_Participantes', 0);
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
