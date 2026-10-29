<?php

namespace Tests\Feature;

use App\Infrastructure\Dfd\DfdLegacyImport;
use App\Infrastructure\Dfd\DfdRegistration;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class DfdNumberingTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private int $fundA;

    private int $fundB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 10, 29)->startOfDay());
        $this->actor = User::create(['name' => 'Admin', 'username' => 'DFD-NUMBER', 'password' => 'SyntheticPassword123', 'user_type' => 'Administrador']);
        $this->fundA = DB::table('contract_funds')->insertGetId(['name' => 'Fundo A', 'name_key' => 'FUNDO A', 'raw_payload' => '{}']);
        $this->fundB = DB::table('contract_funds')->insertGetId(['name' => 'Fundo B', 'name_key' => 'FUNDO B', 'raw_payload' => '{}']);
        DB::table('tb_Produtos_Prodata')->insert(['ID_Prodata' => 10, 'Classe' => 'Material', 'Descricao' => 'Papel', 'UND' => 'UN', 'Inativo' => 0]);
    }

    private function save(int $fund, array $input = [], ?int $id = null): object
    {
        $id = app(DfdRegistration::class)->save($this->actor, $fund, $id, array_replace([
            'Categoria' => 'Material', 'Data_DFD' => '2026-10-29', 'Necessidade' => 'Atender a unidade', 'Desricao' => 'Aquisição de papel',
        ], $input));

        return DB::table('tb_DFD')->where('ID', $id)->first();
    }

    public function test_sequence_is_global_across_funds_and_ignores_client_number_and_year(): void
    {
        $first = $this->save($this->fundA, ['N_DFD' => '900/99', 'ANO' => 1999]);
        $second = $this->save($this->fundB);
        $third = $this->save($this->fundA);
        $this->assertSame(['001/26', '002/26', '003/26'], [$first->N_DFD, $second->N_DFD, $third->N_DFD]);
        $this->assertDatabaseCount('tb_N_DFD', 1);
        $this->assertDatabaseHas('tb_N_DFD', ['ANO' => 2026, 'N_DFD' => 3]);
        $event = ActivityEvent::where('action', 'dfd_created')->orderBy('id')->firstOrFail();
        $this->assertSame('001/26', $event->metadata['after']['N_DFD']);
    }

    public function test_current_year_resets_sequence_independently_of_document_date(): void
    {
        $this->save($this->fundA);
        $this->travelTo(now()->setDate(2027, 1, 1));
        $next = $this->save($this->fundB, ['Data_DFD' => '2026-12-31']);
        $this->assertSame('001/27', $next->N_DFD);
        $this->assertDatabaseHas('tb_N_DFD', ['ANO' => 2026, 'N_DFD' => 1]);
        $this->assertDatabaseHas('tb_N_DFD', ['ANO' => 2027, 'N_DFD' => 1]);
    }

    public function test_history_and_deleted_records_in_any_fund_are_not_reused(): void
    {
        DB::table('tb_DFD')->insert(['fund_id' => $this->fundB, 'N_DFD' => " 0012 / 2026\t", 'deleted_at' => now()]);
        $dfd = $this->save($this->fundA);
        $this->assertSame('013/26', $dfd->N_DFD);
        app(DfdRegistration::class)->delete($this->actor, $this->fundA, $dfd->ID, ['version' => 1, 'confirmation' => true]);
        $this->assertSame('014/26', $this->save($this->fundB)->N_DFD);
        $this->assertDatabaseHas('tb_DFD', ['N_DFD' => " 0012 / 2026\t"]);
    }

    public function test_existing_counter_continues_beyond_three_digits_and_edit_preserves_number(): void
    {
        DB::table('tb_N_DFD')->insert(['ANO' => 2026, 'N_DFD' => 999]);
        $dfd = $this->save($this->fundA);
        $this->assertSame('1000/26', $dfd->N_DFD);
        $updated = $this->save($this->fundA, ['version' => 1, 'N_DFD' => '999/99', 'Necessidade' => 'Necessidade revisada'], $dfd->ID);
        $this->assertSame('1000/26', $updated->N_DFD);
        $this->assertDatabaseHas('tb_N_DFD', ['ANO' => 2026, 'N_DFD' => 1000]);
    }

    public function test_audit_failure_rolls_back_document_and_new_or_existing_counter(): void
    {
        foreach ([false, true] as $existing) {
            if ($existing) {
                DB::table('tb_N_DFD')->insert(['ANO' => 2026, 'N_DFD' => 7]);
            }
            ActivityEvent::creating(fn () => throw new RuntimeException('Audit failed'));
            try {
                $this->save($this->fundA);
                $this->fail('A auditoria deve impedir a gravação.');
            } catch (RuntimeException $error) {
                $this->assertSame('Audit failed', $error->getMessage());
            } finally {
                ActivityEvent::flushEventListeners();
            }
            $this->assertDatabaseCount('tb_DFD', 0);
            $this->assertDatabaseCount('activity_events', 0);
            $this->assertDatabaseCount('tb_N_DFD', $existing ? 1 : 0);
            if ($existing) {
                $this->assertDatabaseHas('tb_N_DFD', ['ANO' => 2026, 'N_DFD' => 7]);
            }
        }
        $this->assertSame('008/26', $this->save($this->fundB)->N_DFD);
    }

    public function test_products_use_the_number_assigned_to_the_parent_without_consuming_a_number(): void
    {
        $dfd = $this->save($this->fundA);
        app(DfdRegistration::class)->product($this->actor, $this->fundA, $dfd->ID, null, [
            'version' => 1, 'product_id' => DB::table('tb_Produtos_Prodata')->value('ID'), 'quantity' => '2.0000',
        ]);
        $this->assertDatabaseHas('tb_Produtos_DFD', ['ID_DFD' => $dfd->ID, 'N_DFD' => '001/26', 'QTD' => '2.0000']);
        $this->assertDatabaseHas('tb_N_DFD', ['ANO' => 2026, 'N_DFD' => 1]);
    }

    public function test_invalid_or_unauthorized_http_request_does_not_allocate_a_number(): void
    {
        $this->actingAs($this->actor)->withSession(['fund_id' => $this->fundA])
            ->post('/dfd-compra', ['Categoria' => 'Material'])->assertSessionHasErrors(['Data_DFD', 'Necessidade', 'Desricao']);
        $this->withSession(['fund_id' => null])->post('/dfd-compra', [])->assertForbidden();
        $this->assertDatabaseCount('tb_N_DFD', 0);
        $this->assertDatabaseCount('tb_DFD', 0);
    }

    public function test_legacy_counter_without_year_is_preserved_and_not_assigned_to_an_arbitrary_year(): void
    {
        $legacy = DB::table('tb_N_DFD')->insertGetId(['N_DFD' => 500], 'ID');
        $this->assertSame('001/26', $this->save($this->fundA)->N_DFD);
        $this->assertDatabaseHas('tb_N_DFD', ['ID' => $legacy, 'ANO' => null, 'N_DFD' => 500]);
        $this->assertDatabaseCount('tb_N_DFD', 2);
    }

    public function test_conflicting_version_does_not_change_the_counter_or_document(): void
    {
        $dfd = $this->save($this->fundA);
        $this->actingAs($this->actor)->withSession(['fund_id' => $this->fundA])
            ->put('/dfd-compra/'.$dfd->ID, ['version' => 2])->assertStatus(409);
        $this->assertDatabaseHas('tb_N_DFD', ['ANO' => 2026, 'N_DFD' => 1]);
        $this->assertDatabaseHas('tb_DFD', ['ID' => $dfd->ID, 'N_DFD' => '001/26', 'version' => 1]);
    }

    public function test_counter_limit_is_rejected_without_wrapping_or_saving_a_document(): void
    {
        DB::table('tb_N_DFD')->insert(['ANO' => 2026, 'N_DFD' => 2147483647]);
        $this->actingAs($this->actor)->withSession(['fund_id' => $this->fundA])->post('/dfd-compra', [
            'Categoria' => 'Material', 'Data_DFD' => '2026-10-29', 'Necessidade' => 'Atender a unidade', 'Desricao' => 'Papel',
        ])->assertSessionHasErrors('N_DFD');
        $this->assertDatabaseHas('tb_N_DFD', ['ANO' => 2026, 'N_DFD' => 2147483647]);
        $this->assertDatabaseCount('tb_DFD', 0);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_import_rejects_a_number_already_used_by_another_fund_and_reports_the_conflict(): void
    {
        $this->save($this->fundA);
        $path = tempnam(sys_get_temp_dir(), 'dfd-numbering-');
        try {
            file_put_contents($path, <<<'SQL'
INSERT INTO `tb_DFD` (`ID`, `N_DFD`, `Fundo`) VALUES (101, '001/26', 'Fundo B');
SQL);
            $result = app(DfdLegacyImport::class)->run($path, true);
            $this->assertSame(1, $result['rejected_count']);
            $this->assertSame(0, $result['imported_count']);
            $this->assertDatabaseCount('tb_DFD', 1);
            $this->assertDatabaseHas('legacy_records', ['source_table' => 'tb_DFD', 'legacy_id' => '101', 'status' => 'conflict']);
            $this->assertSame('002/26', $this->save($this->fundB)->N_DFD);
        } finally {
            unlink($path);
        }
    }

    public function test_catalog_only_import_preserves_dfds_and_is_idempotent(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'dfd-catalog-');
        try {
            file_put_contents($path, <<<'SQL'
INSERT INTO `tb_Produtos_Prodata` (`ID`, `ID_Prodata`, `Classe`, `Descricao`, `UND`, `Inativo`) VALUES (500, 500, 'COMUNICAÇÃO', 'Produto de referência', 'UN', 0);
INSERT INTO `tb_DFD` (`ID`, `N_DFD`, `Fundo`) VALUES (101, '001/26', 'Fundo A');
INSERT INTO `tb_Produtos_DFD` (`ID`, `ID_DFD`, `N_DFD`, `ITEM`) VALUES (101, 101, '001/26', 1);
SQL);
            $importer = app(DfdLegacyImport::class);
            $this->assertSame(['tb_Produtos_Prodata' => 1], $importer->run($path, false, true)['source_count']);
            $this->assertDatabaseMissing('tb_Produtos_Prodata', ['ID' => 500]);
            $this->assertSame(1, $importer->run($path, true, true)['imported_count']);
            $this->assertSame(1, $importer->run($path, true, true)['skipped_count']);
            $this->assertDatabaseHas('tb_Produtos_Prodata', ['ID' => 500, 'Classe' => 'COMUNICAÇÃO']);
            $this->assertDatabaseCount('tb_DFD', 0);
            $this->assertDatabaseCount('tb_Produtos_DFD', 0);
            $this->assertDatabaseCount('tb_N_DFD', 0);
        } finally {
            unlink($path);
        }
    }
}
