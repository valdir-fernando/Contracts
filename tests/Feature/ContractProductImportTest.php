<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractAudit;
use App\Models\ContractProduct;
use App\Services\Legacy\ContractProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContractProductImportTest extends TestCase
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

    private function contract(array $extra = []): Contract
    {
        return Contract::create($extra + ['numero' => '015/25', 'fundo_nome_snapshot' => 'MUNICIPIO DE NIQUELÂNDIA',
            'fornecedor_documento_snapshot' => '11222333000181', 'processo' => '2025000820', 'valor_total' => '2530.9200', 'quality_issues' => []]);
    }

    private function file(array $extra = []): string
    {
        $raw = $extra + ['ID' => '2192', 'CNPJ' => '11.222.333/0001-81', 'Contrato' => '015/25', 'Processo' => '2025000820',
            'FUNDO' => 'MUNICIPIO DE NIQUELÂNDIA', 'Ficha' => '001', 'Item' => '5', 'Cod_Prod' => '100', 'Discriminacao' => 'Produto de teste',
            'Marca' => 'MARCA', 'UND' => 'UN', 'QTD' => '36,000', 'VL_UNIT' => '22,9900', 'TOTAL_UNIT' => '827,6400'];
        $file = tempnam(sys_get_temp_dir(), 'contract-product-import-');
        file_put_contents($file, 'INSERT INTO `tb_Produtos_Contratos` (`'.implode('`,`', array_keys($raw)).'`) VALUES ('.implode(',', array_map(fn ($value) => "'".str_replace("'", "''", $value)."'", array_values($raw))).');');
        $this->files[] = $file;

        return $file;
    }

    public function test_import_is_exact_audited_idempotent_and_preserves_totals_and_catalog_balances(): void
    {
        $contract = $this->contract();
        $other = $this->contract(['fundo_nome_snapshot' => 'OUTRO FUNDO']);
        DB::table('tb_Produtos')->insert(['ID_Produto' => 1, 'QTD' => '100,000']);
        $file = $this->file();
        $importer = new ContractProductImporter;
        $this->assertSame(1, $importer->import($file)['imported']);
        $product = ContractProduct::sole();
        $this->assertSame($contract->id, $product->contract_id);
        $this->assertSame('36.0000', $product->quantity);
        $this->assertSame('22.9900', $product->unit_price);
        $this->assertSame('827.6400', $product->total);
        $this->assertNull($product->product_id);
        $this->assertNotNull($product->legacy_record_id);
        $this->assertSame(['001'], $contract->productFichas());
        $this->assertSame([], $other->productFichas());
        $this->assertSame('2530.9200', $contract->fresh()->valor_total);
        $this->assertSame('100,000', DB::table('tb_Produtos')->value('QTD'));
        $this->assertSame('products_imported', ContractAudit::sole()->action);
        $version = $contract->fresh()->version;
        $this->assertSame(1, $importer->import($file)['skipped']);
        $product->delete();
        $this->assertSame(1, $importer->import($file)['skipped']);
        $this->assertSame($version, $contract->fresh()->version);
        $this->assertSame(0, ContractProduct::count());
        $this->assertSame(1, ContractProduct::withTrashed()->count());
    }

    public function test_changed_source_does_not_overwrite_or_duplicate_existing_product(): void
    {
        $this->contract();
        $importer = new ContractProductImporter;
        $importer->import($this->file());
        $this->assertSame(1, $importer->import($this->file(['QTD' => '2,000']))['conflict']);
        $this->assertSame('36.0000', ContractProduct::sole()->quantity);
        $this->assertDatabaseHas('import_issues', ['code' => 'contract_product_conflict']);
    }

    public function test_dry_run_rolls_back_products_provenance_version_and_audit(): void
    {
        $contract = $this->contract();
        $version = $contract->fresh()->version;
        $this->assertSame(1, (new ContractProductImporter)->import($this->file(), true)['imported']);
        $this->assertSame($version, $contract->fresh()->version);
        foreach (['tb_Produtos_Contratos', 'legacy_records', 'import_runs', 'contract_audits', 'activity_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_incomplete_wrong_or_ambiguous_identity_never_links_a_product(): void
    {
        $this->contract();
        $importer = new ContractProductImporter;
        foreach (['CNPJ' => '', 'FUNDO' => 'OUTRO', 'Processo' => 'ERRADO'] as $field => $value) {
            $this->assertSame(1, $importer->import($this->file([$field => $value]))['unmatched']);
        }
        $this->contract();
        $this->assertSame(1, $importer->import($this->file())['ambiguous']);
        $this->assertDatabaseCount('tb_Produtos_Contratos', 0);
        $this->assertDatabaseCount('contract_audits', 0);
    }

    public function test_invalid_decimals_and_deleted_contracts_are_preserved_for_review(): void
    {
        $contract = $this->contract();
        $importer = new ContractProductImporter;
        $this->assertSame(1, $importer->import($this->file(['QTD' => 'INVÁLIDO']))['invalid']);
        $contract->delete();
        $this->assertSame(1, $importer->import($this->file())['unmatched']);
        $this->assertDatabaseCount('tb_Produtos_Contratos', 0);
    }
}
