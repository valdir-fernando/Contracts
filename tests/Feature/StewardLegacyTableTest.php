<?php

namespace Tests\Feature;

use App\Infrastructure\ContractStewards\ContractStewardRegistration;
use App\Infrastructure\ContractStewards\CopyFundManager;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class StewardLegacyTableTest extends TestCase
{
    use RefreshDatabase;

    private int $fund;

    private int $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fund = DB::table('contract_funds')->insertGetId(['name' => 'MUNICIPIO DE NIQUELÂNDIA',
            'name_key' => 'MUNICIPIO DE NIQUELANDIA', 'raw_payload' => '{}']);
        $this->source = DB::table('tb_Gestor')->insertGetId(['legacy_id' => 32, 'name' => 'GESTORA DE TESTE',
            'name_key' => 'GESTORA DE TESTE', 'fund_name_key' => 'MUNICIPIO DE NIQUELANDIA', 'raw_payload' => json_encode([
                'ID_Gestor' => 32, 'CPF_Gestor' => '529.982.247-25', 'Nome_Gestor' => 'GESTORA DE TESTE', 'RG_Gestor' => 'RG privado',
                'Data_Nasc' => '30/11/2001', 'Sexo' => 'Feminino', 'Fundo' => 'MUNICIPIO DE NIQUELÂNDIA',
                'Fone_Celular' => '(62) 99999-1111', 'WhatsApp' => '(62) 99999-2222', 'Email' => 'gestora@example.test',
                'Decreto' => '103/2026', 'Data_Decreto' => '01/04/2026', 'Endereco' => 'Endereço privado',
                'Assinatura' => 1, 'Cargo' => 'Gestora do Executivo', 'Matricula' => '158370',
            ])]);
    }

    public function test_copy_maps_fiscal_equivalent_fields_and_preserves_source_and_contracts(): void
    {
        $source = DB::table('tb_Gestor')->find($this->source);
        $result = app(CopyFundManager::class)->copy('Gestora de teste', 'MUNICIPIO DE NIQUELÂNDIA');
        $this->assertTrue($result['created']);
        $row = DB::table('tb_Gestor_Cont')->sole();
        $this->assertEquals($result['table_id'], $row->ID_Gestor_Cont);
        $this->assertEquals($result['id'], $row->contract_steward_id);
        $this->assertSame('GESTORA DE TESTE', $row->Nome_Gestor);
        $this->assertSame('52998224725', $row->CPF_Gestor);
        $this->assertSame('RG privado', $row->RG_Gestor);
        $this->assertSame('2001-11-30', $row->Data_Nasc);
        $this->assertSame('2026-04-01', $row->Data_Decreto);
        $this->assertSame('Feminino', $row->Sexo);
        $this->assertSame('gestora@example.test', $row->Email);
        $this->assertSame('(62) 99999-1111', $row->Celular);
        $this->assertSame('(62) 99999-2222', $row->WhatsApp);
        $this->assertSame('MUNICIPIO DE NIQUELÂNDIA', $row->Fundo);
        $this->assertSame('103/2026', $row->Decreto);
        $this->assertNull($row->Secretaria);
        $this->assertNull($row->Cod_Prodata);
        $this->assertFalse(Schema::hasColumn('tb_Gestor_Cont', 'Assinatura'));
        $raw = json_decode(DB::table('contract_stewards')->sole()->raw_payload, true);
        $this->assertArrayNotHasKey('Assinatura', $raw);
        $this->assertSame('Endereço privado', $raw['Endereco']);
        $this->assertEquals($source, DB::table('tb_Gestor')->find($this->source));
        $this->assertDatabaseCount('contracts', 0);
        $audit = ActivityEvent::sole();
        $this->assertNull($audit->actor_id);
        $this->assertSame('contract_steward_imported', $audit->action);
        $this->assertDatabaseHas('import_runs', ['id' => $audit->metadata['import_run_id'], 'status' => 'completed', 'imported_count' => 1]);
        $this->assertFalse(app(CopyFundManager::class)->copy('GESTORA DE TESTE', 'MUNICIPIO DE NIQUELANDIA')['created']);
        $this->assertDatabaseCount('tb_Gestor_Cont', 1);
        $this->assertDatabaseCount('contract_stewards', 1);
        $this->assertDatabaseCount('activity_events', 1);
        $this->assertDatabaseCount('import_runs', 1);
    }

    public function test_application_edits_and_soft_delete_sync_both_tables_and_preserve_omitted_data(): void
    {
        $result = app(CopyFundManager::class)->copy('Gestora de teste', 'MUNICIPIO DE NIQUELÂNDIA');
        $admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'Synthetic123', 'user_type' => 'Administrador']);
        $registration = app(ContractStewardRegistration::class);
        $record = DB::table('contract_stewards')->find($result['id']);
        $registration->save($admin, $record->id, ['Nome_Gestor' => 'Novo nome', 'fund_id' => $this->fund,
            'revision' => $registration->revision($record), 'Celular' => null]);
        $legacy = DB::table('tb_Gestor_Cont')->sole();
        $this->assertSame('NOVO NOME', $legacy->Nome_Gestor);
        $this->assertNull($legacy->Celular);
        $this->assertSame('gestora@example.test', $legacy->Email);
        $record = DB::table('contract_stewards')->find($result['id']);
        $registration->delete($admin, $record->id, ['revision' => $registration->revision($record), 'confirmation' => 1]);
        $this->assertNotNull(DB::table('tb_Gestor_Cont')->sole()->deleted_at);
        $this->assertSame($legacy->ID_Gestor_Cont, DB::table('tb_Gestor_Cont')->sole()->ID_Gestor_Cont);
    }

    public function test_audit_failure_rolls_back_copy_in_both_tables_and_import_run(): void
    {
        ActivityEvent::creating(fn () => throw new RuntimeException('Audit unavailable'));
        try {
            app(CopyFundManager::class)->copy('Gestora de teste', 'MUNICIPIO DE NIQUELÂNDIA');
            $this->fail('Copy must fail without audit.');
        } catch (RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
        } finally {
            ActivityEvent::flushEventListeners();
        }
        $this->assertDatabaseCount('tb_Gestor_Cont', 0);
        $this->assertDatabaseCount('contract_stewards', 0);
        $this->assertDatabaseCount('import_runs', 0);
    }

    public function test_invalid_source_cpf_is_rejected(): void
    {
        DB::table('tb_Gestor')->where('id', $this->source)->update(['raw_payload->CPF_Gestor' => '11111111111']);
        $this->expectException(ValidationException::class);
        app(CopyFundManager::class)->copy('Gestora de teste', 'MUNICIPIO DE NIQUELÂNDIA');
    }

    public function test_copy_refuses_missing_fund_missing_source_and_ambiguous_sources_without_writes(): void
    {
        foreach ([['Gestora de teste', 'Fundo inexistente'], ['Pessoa inexistente', 'MUNICIPIO DE NIQUELÂNDIA']] as [$name, $fund]) {
            try {
                app(CopyFundManager::class)->copy($name, $fund);
                $this->fail('Missing sources must prevent copying.');
            } catch (ValidationException $error) {
                $this->assertNotEmpty($error->errors());
            }
        }
        $source = (array) DB::table('tb_Gestor')->find($this->source);
        unset($source['id'], $source['legacy_id']);
        DB::table('tb_Gestor')->insert($source);
        try {
            app(CopyFundManager::class)->copy('Gestora de teste', 'MUNICIPIO DE NIQUELÂNDIA');
            $this->fail('Ambiguous sources must prevent copying.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('name', $error->errors());
        }
        $this->assertDatabaseCount('contract_stewards', 0);
        $this->assertDatabaseCount('tb_Gestor_Cont', 0);
        $this->assertDatabaseCount('import_runs', 0);
    }

    public function test_deleted_target_is_not_silently_recreated(): void
    {
        $result = app(CopyFundManager::class)->copy('Gestora de teste', 'MUNICIPIO DE NIQUELÂNDIA');
        DB::table('contract_stewards')->where('id', $result['id'])->update(['deleted_at' => now()]);
        $this->expectException(ValidationException::class);
        app(CopyFundManager::class)->copy('Gestora de teste', 'MUNICIPIO DE NIQUELÂNDIA');
    }

    public function test_command_requires_explicit_fund_and_copies_idempotently(): void
    {
        $this->artisan('contracts:copy-contract-steward', ['name' => 'Gestora de teste'])->assertFailed();
        $this->artisan('contracts:copy-contract-steward', ['name' => 'Gestora de teste', '--fund' => 'MUNICIPIO DE NIQUELÂNDIA'])->assertSuccessful();
        $this->artisan('contracts:copy-contract-steward', ['name' => 'Gestora de teste', '--fund' => 'MUNICIPIO DE NIQUELÂNDIA'])->assertSuccessful();
        $this->assertDatabaseCount('tb_Gestor_Cont', 1);
    }
}
