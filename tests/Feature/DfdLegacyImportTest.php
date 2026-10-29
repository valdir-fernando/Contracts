<?php

namespace Tests\Feature;

use App\Infrastructure\Dfd\DfdLegacyImport;
use App\Infrastructure\Dfd\DfdNumbering;
use App\Models\ActivityEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class DfdLegacyImportTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    private int $fund;

    private int $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = tempnam(sys_get_temp_dir(), 'dfd-import-');
        $this->fund = DB::table('contract_funds')->insertGetId(['name' => 'Município de Niquelândia', 'name_key' => 'MUNICIPIO DE NIQUELANDIA', 'raw_payload' => '{}']);
        $this->department = DB::table('contract_departments')->insertGetId(['name' => 'Secretaria de Compras', 'name_key' => 'SECRETARIA DE COMPRAS', 'raw_payload' => '{}']);
        file_put_contents($this->path, <<<'SQL'
INSERT INTO `tb_N_DFD` (`ID`, `N_DFD`) VALUES (1, 5);
INSERT INTO `tb_DFD` (`ID`, `N_DFD`, `Data_DFD`, `Fundo`, `Requisitante`, `Categoria`, `Necessidade`, `Desricao`, `Responsavel`, `Ficha`) VALUES
(101, '001/25', '2025-01-10', 'MUNICIPIO DE NIQUELÂNDIA', 'SECRETARIA DE COMPRAS', 'Material', 'Atender a unidade', 'Papel', 'Pessoa exemplo', '00012'),
(102, '005/26', '2026-02-10', 'MUNICIPIO DE NIQUELÂNDIA', 'Área histórica desconhecida', 'Material', 'Reposição', 'Canetas', 'Pessoa exemplo', '00013');
INSERT INTO `tb_Produtos_DFD` (`ID`, `ID_DFD`, `N_DFD`, `ID_PROD`, `ITEM`, `COD_PROD`, `PROD_SIMPLES`, `CARACT`, `PRODUTO`, `UND`, `QTD`, `VL_UNIT`, `VL_TOTAL`) VALUES
(201, 101, '001/25', 20, 1, '00020', 'Papel', 'A4', 'Papel A4', 'UN', '2,5000', '10,2500', '25,6250');
SQL);
    }

    protected function tearDown(): void
    {
        unlink($this->path);
        parent::tearDown();
    }

    public function test_dry_run_reports_all_tables_without_writes(): void
    {
        $result = app(DfdLegacyImport::class)->run($this->path, false);
        $this->assertSame(['tb_Produtos_Prodata' => 0, 'tb_N_DFD' => 1, 'tb_DFD' => 2, 'tb_Produtos_DFD' => 1], $result['source_count']);
        foreach (['tb_DFD', 'tb_Produtos_DFD', 'tb_N_DFD', 'legacy_records', 'import_runs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_import_preserves_history_links_departments_and_seeds_global_annual_counters(): void
    {
        $result = app(DfdLegacyImport::class)->run($this->path, true);
        $this->assertSame(4, $result['imported_count']);
        $this->assertSame(0, $result['rejected_count']);
        $record = DB::table('tb_DFD')->where('legacy_id', 101)->sole();
        $this->assertSame('001/25', $record->N_DFD);
        $this->assertSame($this->fund, $record->fund_id);
        $this->assertSame($this->department, $record->department_id);
        $this->assertSame('00012', $record->Ficha);
        $this->assertDatabaseHas('tb_DFD', ['legacy_id' => 102, 'department_id' => null, 'Requisitante' => 'Área histórica desconhecida']);
        $this->assertDatabaseHas('tb_Produtos_DFD', ['legacy_id' => 201, 'ID_DFD' => $record->ID, 'N_DFD' => '001/25', 'QTD' => '2,5000', 'VL_UNIT' => '10,2500', 'VL_TOTAL' => '25,6250']);
        $this->assertDatabaseHas('tb_N_DFD', ['ANO' => null, 'N_DFD' => 5]);
        $this->assertDatabaseHas('tb_N_DFD', ['ANO' => 2025, 'N_DFD' => 1]);
        $this->assertDatabaseHas('tb_N_DFD', ['ANO' => 2026, 'N_DFD' => 5]);
        $this->assertSame('006/26', DB::transaction(fn () => app(DfdNumbering::class)->next(2026)));
        $this->assertSame('001/27', DB::transaction(fn () => app(DfdNumbering::class)->next(2027)));
        $this->assertDatabaseHas('legacy_records', ['source_table' => 'tb_N_DFD', 'legacy_id' => '1', 'status' => 'imported']);
        $this->assertDatabaseHas('activity_events', ['action' => 'dfd_legacy_imported']);
    }

    public function test_repeat_import_is_idempotent_and_never_reduces_counters_or_overwrites_edits(): void
    {
        $importer = app(DfdLegacyImport::class);
        $importer->run($this->path, true);
        DB::table('tb_DFD')->where('legacy_id', 101)->update(['Necessidade' => 'Edição posterior']);
        DB::transaction(fn () => app(DfdNumbering::class)->reserveAtLeast(2026, 20));
        $again = $importer->run($this->path, true);
        $this->assertSame(0, $again['imported_count']);
        $this->assertSame(4, $again['skipped_count']);
        $this->assertDatabaseCount('tb_DFD', 2);
        $this->assertDatabaseCount('tb_Produtos_DFD', 1);
        $this->assertDatabaseCount('tb_N_DFD', 3);
        $this->assertDatabaseHas('tb_DFD', ['legacy_id' => 101, 'Necessidade' => 'Edição posterior']);
        $this->assertDatabaseHas('tb_N_DFD', ['ANO' => 2026, 'N_DFD' => 20]);
    }

    public function test_source_counter_with_year_reserves_maximum_and_changed_origin_conflicts(): void
    {
        file_put_contents($this->path, 'INSERT INTO `tb_N_DFD` (`ID`, `N_DFD`, `ANO`) VALUES (1, 8, 2026);');
        DB::table('tb_N_DFD')->insert(['ANO' => 2026, 'N_DFD' => 10]);
        $importer = app(DfdLegacyImport::class);
        $this->assertSame(1, $importer->run($this->path, true)['imported_count']);
        $this->assertDatabaseCount('tb_N_DFD', 1);
        $this->assertDatabaseHas('tb_N_DFD', ['ANO' => 2026, 'N_DFD' => 10]);
        file_put_contents($this->path, 'INSERT INTO `tb_N_DFD` (`ID`, `N_DFD`, `ANO`) VALUES (1, 90, 2026);');
        $this->assertSame(1, $importer->run($this->path, true)['rejected_count']);
        $this->assertDatabaseHas('tb_N_DFD', ['ANO' => 2026, 'N_DFD' => 10]);
    }

    public function test_unknown_fund_and_unmatched_products_are_preserved_as_conflicts(): void
    {
        DB::table('contract_funds')->delete();
        $result = app(DfdLegacyImport::class)->run($this->path, true);
        $this->assertSame(3, $result['rejected_count']);
        $this->assertSame(1, $result['imported_count']);
        $this->assertDatabaseCount('tb_DFD', 0);
        $this->assertDatabaseCount('tb_Produtos_DFD', 0);
        $this->assertDatabaseCount('tb_N_DFD', 1);
        $this->assertDatabaseHas('legacy_records', ['source_table' => 'tb_Produtos_DFD', 'legacy_id' => '201', 'status' => 'conflict']);
    }

    public function test_audit_failure_rolls_back_documents_products_origins_and_counters(): void
    {
        ActivityEvent::creating(fn () => throw new RuntimeException('Audit failed'));
        try {
            app(DfdLegacyImport::class)->run($this->path, true);
            $this->fail('Audit failure must roll back the import.');
        } catch (RuntimeException $error) {
            $this->assertSame('Audit failed', $error->getMessage());
        } finally {
            ActivityEvent::flushEventListeners();
        }
        foreach (['tb_DFD', 'tb_Produtos_DFD', 'tb_N_DFD', 'legacy_records', 'import_runs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }
}
