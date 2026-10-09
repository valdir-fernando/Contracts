<?php

namespace Tests\Feature;

use App\Models\AccessScope;
use App\Models\Contract;
use App\Models\ImportRun;
use App\Models\LegacyRecord;
use App\Models\User;
use App\Policies\ContractPolicy;
use App\Services\Legacy\ContractImporter;
use App\Services\Legacy\Normalizer;
use App\Services\Legacy\SqlDumpReader;
use App\Support\ContractFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ContractImportTest extends TestCase
{
    use RefreshDatabase;

    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            unlink($file);
        }
        parent::tearDown();
    }

    private function dump(array $changes = []): string
    {
        $row = array_fill_keys(array_keys(ContractFields::MAP), '');
        $row = array_replace($row, ['ID_Contrato' => '1', 'Contrato' => '019/26', 'VL_Total' => '1.908,6300',
            'Inicio_Vig' => '2026-01-01', 'Final_Vig' => '2026-12-31', 'Final_Vig_Atualiz' => '0000-00-00',
            'Objeto' => "Texto com 'aspas'; e quebra\nsegunda linha"], $changes);
        $values = array_map(fn ($value) => "'".str_replace(['\\', "'", "\n"], ['\\\\', "\\'", '\\n'], $value)."'", $row);
        $path = tempnam(sys_get_temp_dir(), 'contracts-test-');
        $this->files[] = $path;
        file_put_contents($path, 'INSERT INTO `TB_Contratos` (`'.implode('`, `', array_keys($row)).'`) VALUES ('.implode(', ', $values).');');

        return $path;
    }

    public function test_import_preserves_all_fields_and_is_idempotent_even_after_soft_deletion(): void
    {
        $path = $this->dump();
        $run = app(ContractImporter::class)->import($path);
        $this->assertSame(1, $run->source_count);
        $this->assertSame(1, $run->imported_count);
        $contract = Contract::firstOrFail();
        $this->assertSame('019/26', $contract->numero);
        $this->assertSame('1908.6300', $contract->valor_total);
        $this->assertNull($contract->vigencia_fim_atual);
        $this->assertSame("Texto com 'aspas'; e quebra\nsegunda linha", $contract->objeto);
        $this->assertCount(80, LegacyRecord::firstOrFail()->raw_payload);
        $this->assertDatabaseHas('import_issues', ['code' => 'zero_date']);
        $contract->delete();
        $second = app(ContractImporter::class)->import($path);
        $this->assertSame(1, $second->skipped_count);
        $this->assertSame(1, Contract::withTrashed()->count());
        $this->assertSame(0, Contract::count());
    }

    public function test_simulation_does_not_persist_and_changed_source_does_not_overwrite(): void
    {
        $path = $this->dump();
        $simulation = app(ContractImporter::class)->import($path, true);
        $this->assertSame('simulated', $simulation->status);
        $this->assertDatabaseCount('contracts', 0);
        $this->assertDatabaseCount('legacy_records', 0);
        $this->assertDatabaseCount('import_runs', 0);
        app(ContractImporter::class)->import($path);
        $changed = app(ContractImporter::class)->import($this->dump(['Contrato' => 'ALTERADO']));
        $this->assertSame(1, $changed->rejected_count);
        $this->assertSame('019/26', Contract::firstOrFail()->numero);
        $this->assertDatabaseHas('import_issues', ['code' => 'source_changed']);
    }

    public function test_truncated_source_rolls_back_imported_rows_and_records_failure(): void
    {
        $path = $this->dump();
        file_put_contents($path, "\nINSERT INTO `TB_Contratos` (", FILE_APPEND);
        try {
            app(ContractImporter::class)->import($path);
            $this->fail('A origem incompleta deve ser rejeitada.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Arquivo SQL incompleto.', $exception->getMessage());
        }
        $this->assertDatabaseCount('contracts', 0);
        $this->assertDatabaseCount('legacy_records', 0);
        $this->assertSame('failed', ImportRun::firstOrFail()->status);
    }

    public function test_reader_rejects_expressions_instead_of_executing_source_sql(): void
    {
        $path = $this->dump();
        file_put_contents($path, 'INSERT INTO `TB_Contratos` (`ID_Contrato`) VALUES (SLEEP(1));');
        $this->expectException(RuntimeException::class);
        iterator_to_array((new SqlDumpReader)->rows($path, ['TB_Contratos']));
    }

    public function test_only_resolved_user_scopes_can_view_imported_contracts(): void
    {
        $path = $this->dump(['Fundo' => 'Fundo A', 'Secretaria' => 'Secretaria A']);
        $catalogs = "INSERT INTO `tb_Fundo` (`ID_Fundo`, `Fundo`, `CNPJ_Fundo`) VALUES (1, 'Fundo A', '');\n"
            ."INSERT INTO `tb_Secretaria` (`ID_Secretaria`, `Secretaria`) VALUES (1, 'Secretaria A');\n";
        file_put_contents($path, $catalogs.file_get_contents($path));
        $scope = AccessScope::create(['scope_key' => hash('sha256', 'scope-a'), 'fund' => 'Fundo A', 'department' => 'Secretaria A']);
        $unknownScope = AccessScope::create(['scope_key' => hash('sha256', 'unknown'), 'fund' => 'Fundo A', 'department' => 'Desconhecida']);
        app(ContractImporter::class)->import($path);
        $user = User::create(['name' => 'Teste', 'username' => 'TESTE', 'password' => 'SenhaTeste123', 'user_type' => 'Usuario']);
        $contract = Contract::firstOrFail();
        $policy = new ContractPolicy;
        $this->assertFalse($policy->view($user, $contract));
        $user->scopes()->attach($unknownScope);
        $this->assertFalse($policy->view($user, $contract));
        $user->scopes()->attach($scope);
        $this->assertTrue($policy->view($user, $contract));
        $user->forceFill(['is_active' => false])->save();
        $this->assertFalse($policy->view($user, $contract));
        $this->assertDatabaseHas('import_issues', ['code' => 'unresolved_user_scope']);
    }

    public function test_normalization_uses_exact_decimals_and_valid_calendar_dates(): void
    {
        $this->assertSame('1908.6300', Normalizer::money('1.908,6300'));
        $this->assertSame('99999999999999.9999', Normalizer::money('99999999999999.9999'));
        $this->assertNull(Normalizer::money('100000000000000'));
        $this->assertNull(Normalizer::money('1,23456'));
        $this->assertNull(Normalizer::date('2026-02-29'));
        $this->assertNull(Normalizer::date('0000-00-00'));
        $this->assertSame('2024-02-29', Normalizer::date('2024-02-29'));
        $this->assertTrue(Normalizer::validDocument('11222333000181'));
        $this->assertFalse(Normalizer::validDocument('11111111111'));
    }
}
