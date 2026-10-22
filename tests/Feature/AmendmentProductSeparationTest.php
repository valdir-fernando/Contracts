<?php

namespace Tests\Feature;

use App\Infrastructure\Contracts\OriginalContractProducts;
use App\Infrastructure\Contracts\ProductDisplayAvailability;
use App\Infrastructure\Contracts\SeparateAmendmentProducts;
use App\Models\Contract;
use App\Models\ContractAmendmentProduct;
use App\Models\ContractAudit;
use App\Models\ContractProduct;
use App\Models\ImportRun;
use App\Models\LegacyRecord;
use App\Models\ProcurementProduct;
use App\Models\User;
use App\Services\Legacy\ContractProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class AmendmentProductSeparationTest extends TestCase
{
    use RefreshDatabase;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();
        $fund = DB::table('contract_funds')->insertGetId(['name' => 'FMS', 'name_key' => 'FMS', 'raw_payload' => '{}']);
        $this->contract = Contract::create(['numero' => '005/25', 'processo' => '2025000453', 'fund_id' => $fund,
            'fundo_nome_snapshot' => 'FMS', 'fornecedor_documento_snapshot' => '11222333000181',
            'valor_total' => '198000.0000', 'version' => 1, 'quality_issues' => [], 'ownership_resolved' => true]);
    }

    private function item(string $adt = '1', string $process = '2025024745'): ContractProduct
    {
        $run = ImportRun::create(['source_hash' => hash('sha256', $adt.$process), 'status' => 'completed', 'started_at' => now()]);
        $raw = ['ADT' => $adt, 'Proc_ADT' => $process, 'Contrato' => '005/25', 'Processo' => '2025000453', 'FUNDO' => 'FMS'];
        $source = LegacyRecord::create(['source_table' => 'tb_Produtos_Contratos', 'legacy_id' => (string) $run->id,
            'source_hash' => hash('sha256', json_encode($raw)), 'raw_payload' => $raw, 'import_run_id' => $run->id]);

        return ContractProduct::create(['contract_id' => $this->contract->id, 'product_id' => null, 'item' => '1',
            'description' => 'Consultoria jurídica', 'unit' => 'Mês', 'quantity' => '12.0000', 'unit_price' => '16500.0000',
            'total' => '198000.0000', 'process_number' => '2025000453', 'supplier_document' => '11222333000181',
            'Ficha' => '20260058', 'Contrato' => '005/25', 'Processo' => '2025000453', 'FUNDO' => 'FMS', 'legacy_record_id' => $source->id]);
    }

    public function test_schema_preserves_every_product_column_and_adds_amendment_references(): void
    {
        $original = Schema::getColumnListing('tb_Produtos_Contratos');
        $amendments = Schema::getColumnListing('tb_Prtodutos_Aditivos');
        $this->assertSame([], array_diff($original, $amendments));
        $this->assertSame(['ADT', 'Proc_ADT'], array_values(array_diff($amendments, $original)));
        $this->assertNotContains('ADT', $original);
        $this->assertNotContains('Proc_ADT', $original);
    }

    public function test_separation_preserves_ids_values_sources_and_deleted_state_and_is_idempotent(): void
    {
        $item = $this->item();
        $deleted = $this->item('0', '');
        $deleted->delete();
        $original = $this->item(' ', '');
        $original->update(['Ficha' => '20250084']);
        $before = $item->fresh()->getAttributes();
        $source = LegacyRecord::findOrFail($item->legacy_record_id)->getAttributes();
        $separator = new SeparateAmendmentProducts;
        $this->assertSame(['copied' => 2, 'archived' => 1, 'already_separated' => 0, 'contracts' => 1], $separator->run(false));
        $copy = ContractAmendmentProduct::findOrFail($item->id);
        foreach ($before as $field => $value) {
            $this->assertSame((string) $value, (string) $copy->getRawOriginal($field), $field);
        }
        $this->assertSame('1', $copy->ADT);
        $this->assertSame('2025024745', $copy->Proc_ADT);
        $this->assertSame('0', ContractAmendmentProduct::withTrashed()->findOrFail($deleted->id)->ADT);
        $this->assertTrue(ContractAmendmentProduct::withTrashed()->findOrFail($deleted->id)->trashed());
        $this->assertTrue($item->fresh()->trashed());
        $this->assertFalse($original->fresh()->trashed());
        $this->assertSame($source, LegacyRecord::findOrFail($item->legacy_record_id)->getAttributes());
        $this->assertSame('198000.0000', $this->contract->fresh()->valor_total);
        $this->assertSame(2, $this->contract->fresh()->version);
        $audit = ContractAudit::sole();
        $this->assertNull($audit->actor_id);
        $this->assertNotNull($audit->import_run_id);
        $this->assertSame([$item->id, $deleted->id], $audit->changes['product_ids']);
        $this->assertSame([$original->id], (new OriginalContractProducts)->forContract($this->contract)->pluck('id')->all());
        $this->assertSame(['20260058'], (new OriginalContractProducts)->amendmentOnlyFichas($this->contract));
        $this->assertSame(['copied' => 0, 'archived' => 0, 'already_separated' => 2, 'contracts' => 0], $separator->run(false));
        $this->assertSame(2, $this->contract->fresh()->version);
        $this->assertDatabaseCount('contract_audits', 1);
    }

    public function test_simulation_rolls_back_every_write(): void
    {
        $item = $this->item();
        $this->artisan('contracts:separate-amendment-products')->assertSuccessful();
        $this->assertFalse($item->fresh()->trashed());
        $this->assertDatabaseCount('tb_Prtodutos_Aditivos', 0);
        $this->assertDatabaseCount('contract_audits', 0);
        $this->assertDatabaseCount('import_runs', 1);
        $this->assertSame(1, $this->contract->fresh()->version);
    }

    public function test_audit_failure_rolls_back_copy_archive_and_contract_version(): void
    {
        $item = $this->item();
        ContractAudit::creating(fn () => throw new RuntimeException('Audit failure'));
        try {
            (new SeparateAmendmentProducts)->run(false);
            $this->fail('Audit failure must roll back the operation.');
        } catch (RuntimeException $error) {
            $this->assertSame('Audit failure', $error->getMessage());
        }
        $this->assertFalse($item->fresh()->trashed());
        $this->assertDatabaseCount('tb_Prtodutos_Aditivos', 0);
        $this->assertDatabaseCount('import_runs', 1);
        $this->assertSame(1, $this->contract->fresh()->version);
    }

    public function test_destination_collision_aborts_instead_of_overwriting_history(): void
    {
        $item = $this->item();
        $attributes = $item->fresh()->getAttributes();
        $attributes['description'] = 'Existing history';
        $attributes['ADT'] = '1';
        $attributes['Proc_ADT'] = '2025024745';
        DB::table('tb_Prtodutos_Aditivos')->insert($attributes);
        try {
            (new SeparateAmendmentProducts)->run(false);
            $this->fail('Conflicting destination must abort.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Conflito', $error->getMessage());
        }
        $this->assertFalse($item->fresh()->trashed());
        $this->assertSame('Existing history', ContractAmendmentProduct::sole()->description);
        $this->assertDatabaseCount('contract_audits', 0);
    }

    public function test_display_availability_is_preserved_after_separation_and_keeps_actor_scope(): void
    {
        $item = $this->item();
        $item->update(['CNPJ' => '11222333000181']);
        $productId = DB::table('tb_Produtos')->insertGetId(['ID_Produto' => 1, 'item' => '1', 'Processo' => '2025000453',
            'CNPJ_Fornec' => '11222333000181', 'Discriminacao' => 'Consultoria', 'Unidade' => 'Mês', 'QTD' => '1', 'VL_Unitario' => '16500', 'VL_Total' => '16500']);
        $product = ProcurementProduct::findOrFail($productId);
        $admin = User::create(['name' => 'Admin', 'username' => 'SEPARATION_ADMIN', 'password' => 'TestPassword12345', 'user_type' => 'Administrador']);
        $reader = new ProductDisplayAvailability;
        $this->assertSame('11.0000', $reader->calculate($product, $this->contract, $admin));
        (new SeparateAmendmentProducts)->run(false);
        $this->assertSame('11.0000', $reader->calculate($product, $this->contract, $admin));
        $unauthorized = User::create(['name' => 'No scope', 'username' => 'SEPARATION_NO_SCOPE', 'password' => 'TestPassword12345']);
        $this->assertSame('0.0000', $reader->calculate($product, $this->contract, $unauthorized));
    }

    public function test_import_routes_amendments_to_new_table_and_reimport_stays_idempotent(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'amendment-products-');
        try {
            file_put_contents($path, "INSERT INTO `tb_Produtos_Contratos` (`ID`, `Item`, `Discriminacao`, `UND`, `QTD`, `VL_UNIT`, `TOTAL_UNIT`, `CNPJ`, `FUNDO`, `Contrato`, `Processo`, `Ficha`, `ADT`, `Proc_ADT`) VALUES (9001,'1','Juridico','Mes','12','16500','198000','11222333000181','FMS','005/25','2025000453','20260058','1','2025024745'),(9002,'1','Juridico','Mes','12','16500','198000','11222333000181','FMS','005/25','2025000453','20250084','','');");
            $importer = new ContractProductImporter;
            $this->assertSame(2, $importer->import($path)['imported']);
            $this->assertSame('20250084', ContractProduct::sole()->Ficha);
            $this->assertSame('20260058', ContractAmendmentProduct::sole()->Ficha);
            $this->assertSame('1', ContractAmendmentProduct::sole()->ADT);
            $this->assertSame('2025024745', ContractAmendmentProduct::sole()->Proc_ADT);
            $this->assertSame('198000.0000', $this->contract->fresh()->valor_total);
            $this->assertSame(2, $importer->import($path)['skipped']);
            $this->assertDatabaseCount('tb_Produtos_Contratos', 1);
            $this->assertDatabaseCount('tb_Prtodutos_Aditivos', 1);
            $this->assertDatabaseCount('contract_audits', 1);
        } finally {
            unlink($path);
        }
    }
}
