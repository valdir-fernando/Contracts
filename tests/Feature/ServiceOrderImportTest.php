<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractAudit;
use App\Models\ContractServiceOrder;
use App\Models\LegacyRecord;
use App\Services\Legacy\ServiceOrderImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class ServiceOrderImportTest extends TestCase
{
    use RefreshDatabase;

    private string $source;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = tempnam(sys_get_temp_dir(), 'os-import-');
        $fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo Teste', 'name_key' => 'FUNDO TESTE', 'raw_payload' => '{}']);
        $this->contract = Contract::create(['numero' => '001/26', 'fund_id' => $fund, 'fundo_nome_snapshot' => 'Fundo Teste',
            'processo' => 'P-1', 'ownership_resolved' => true, 'quality_issues' => [], 'prazo_obra' => 'Prazo preservado', 'valor_total' => '123.0000']);
        $this->contract->refresh();
        $this->dump();
    }

    protected function tearDown(): void
    {
        if (isset($this->source) && is_file($this->source)) {
            unlink($this->source);
        }
        parent::tearDown();
    }

    private function dump(string $number = 'OS-1', string $process = 'P-1', string $start = '2026-01-02'): void
    {
        file_put_contents($this->source, "DROP TABLE contracts;\nINSERT INTO `tb_OS` (`ID_OS`,`N_OS`,`Fundo`,`Contrato`,`Processo`,`Data_OS`,`Data_Inicio`,`Data_Final`,`Periodo`,`Objeto`,`Tipo_Contrato`) VALUES (1,'{$number}','Fundo Teste','001/26','{$process}','2026-01-01','{$start}','2026-02-02','30 dias','Objeto original','Obras');\n");
    }

    public function test_import_is_audited_idempotent_and_preserves_contract_terms_and_raw_source(): void
    {
        $version = $this->contract->version;
        $result = app(ServiceOrderImporter::class)->import($this->source);
        $this->assertSame(1, $result['imported']);
        $order = ContractServiceOrder::firstOrFail();
        $this->assertSame($this->contract->id, $order->contract_id);
        $this->assertSame('2026-01-02', $order->starts_on->format('Y-m-d'));
        $this->assertSame('2026-02-02', $order->ends_on->format('Y-m-d'));
        $this->assertNull($order->created_by);
        $this->assertSame('Objeto original', LegacyRecord::findOrFail($order->legacy_record_id)->raw_payload['Objeto']);
        $this->assertSame($version + 1, $this->contract->fresh()->version);
        $this->assertSame('Prazo preservado', $this->contract->fresh()->prazo_obra);
        $this->assertSame('123.0000', $this->contract->fresh()->valor_total);
        $audit = ContractAudit::firstOrFail();
        $this->assertNull($audit->actor_id);
        $this->assertNotNull($audit->import_run_id);
        $this->assertSame(1, app(ServiceOrderImporter::class)->import($this->source)['skipped']);
        $this->assertSame(1, ContractServiceOrder::count());
        $this->assertSame(1, ContractAudit::count());
        $order->delete();
        $this->assertSame(1, app(ServiceOrderImporter::class)->import($this->source)['skipped']);
        $this->assertSame(0, ContractServiceOrder::count());
        $this->dump('CHANGED');
        $this->assertSame(1, app(ServiceOrderImporter::class)->import($this->source)['conflict']);
        $this->assertSame('OS-1', $order->fresh()->number);
    }

    public function test_dry_run_and_audit_failure_leave_no_import_writes(): void
    {
        $this->assertSame(1, app(ServiceOrderImporter::class)->import($this->source, true)['imported']);
        foreach (['tb_OS', 'legacy_records', 'import_runs', 'contract_audits'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $before = $this->contract->version;
        ContractAudit::creating(fn () => throw new RuntimeException('Audit failure'));
        try {
            app(ServiceOrderImporter::class)->import($this->source);
            $this->fail('Audit failure must roll back');
        } catch (RuntimeException $error) {
            $this->assertSame('Audit failure', $error->getMessage());
            $this->assertDatabaseCount('tb_OS', 0);
            $this->assertDatabaseCount('legacy_records', 0);
            $this->assertSame($before, $this->contract->fresh()->version);
        } finally {
            ContractAudit::flushEventListeners();
        }
    }

    public function test_unsafe_matches_and_invalid_dates_are_preserved_as_issues_without_importing(): void
    {
        $this->dump(process: 'OTHER');
        $this->assertSame(1, app(ServiceOrderImporter::class)->import($this->source)['unmatched']);
        $this->dump(start: '2026-02-30');
        $this->assertSame(1, app(ServiceOrderImporter::class)->import($this->source)['invalid']);
        $duplicate = $this->contract->replicate();
        $duplicate->save();
        $this->dump();
        $this->assertSame(1, app(ServiceOrderImporter::class)->import($this->source)['ambiguous']);
        $this->assertDatabaseCount('tb_OS', 0);
        $this->assertDatabaseCount('import_issues', 3);
    }

    public function test_rename_preserves_existing_rows_and_soft_deletes(): void
    {
        $order = ContractServiceOrder::create(['contract_id' => $this->contract->id, 'number' => 'NATIVE', 'issued_on' => '2026-10-20', 'deadline' => '10 dias']);
        $order->delete();
        Schema::rename('tb_OS', 'contract_service_orders');
        $migration = require database_path('migrations/2026_10_20_000002_rename_service_orders_to_tb_os.php');
        $migration->up();
        $this->assertFalse(Schema::hasTable('contract_service_orders'));
        $this->assertSame('NATIVE', ContractServiceOrder::withTrashed()->findOrFail($order->id)->number);
        $this->assertNotNull(ContractServiceOrder::withTrashed()->findOrFail($order->id)->deleted_at);
    }
}
