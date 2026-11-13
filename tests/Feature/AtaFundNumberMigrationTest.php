<?php

namespace Tests\Feature;

use App\Models\ActivityEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class AtaFundNumberMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function link(string $number, string $process, bool $deleted = false): int
    {
        $ata = DB::table('tb_ATA')->insertGetId(['ATA' => $number, 'PROCESSO' => $process,
            'LICITACAO' => 'Pregão', 'N_LICIT' => '1', 'CNPJ_FUNDO' => '04252011000110',
            'source_files' => '[]', 'quality_issues' => '[]', 'import_hash' => hash('sha256', $process),
            'deleted_at' => $deleted ? '2026-01-01 10:00:00' : null], 'ID');

        return DB::table('tb_FUNDOS_ATA')->insertGetId(['ID_ATA' => $ata, 'CNPJ_FUNDO' => '11222333000181',
            'FUNDO' => 'Fundo histórico', 'papel' => 'participante', 'created_at' => '2025-01-01 10:00:00',
            'updated_at' => '2025-01-02 10:00:00', 'deleted_at' => $deleted ? '2026-01-01 10:00:00' : null], 'ID');
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_11_13_000002_add_ata_number_to_ata_funds.php');
    }

    public function test_backfill_preserves_numbers_links_timestamps_and_soft_deleted_history_and_is_repeatable(): void
    {
        $first = $this->link('107/25', 'PROC-A');
        $second = $this->link('107/2025', 'PROC-B', true);
        $third = $this->link('107/25', 'PROC-C');
        $before = DB::table('tb_FUNDOS_ATA')->orderBy('ID')->get()->keyBy('ID');
        $this->migration()->up();
        foreach ([$first => '107/25', $second => '107/2025', $third => '107/25'] as $id => $number) {
            $row = DB::table('tb_FUNDOS_ATA')->where('ID', $id)->first();
            $this->assertSame($number, $row->ATA);
            $this->assertEquals(array_diff_key((array) $before[$id], ['ATA' => null]), array_diff_key((array) $row, ['ATA' => null]));
        }
        $this->assertDatabaseCount('tb_FUNDOS_ATA', 3);
        $this->assertDatabaseHas('activity_events', ['action' => 'ata_fund_numbers_backfilled']);
        $this->assertSame(3, ActivityEvent::sole()->metadata['rows']);
        $this->migration()->up();
        $this->assertDatabaseCount('activity_events', 1);
        $this->migration()->down();
        $this->assertDatabaseHas('tb_FUNDOS_ATA', ['ID' => $first, 'ATA' => '107/25']);
    }

    public function test_audit_failure_rolls_back_backfill_and_allows_a_safe_retry(): void
    {
        $id = $this->link('001/26', 'PROC');
        ActivityEvent::creating(fn () => throw new RuntimeException('Synthetic audit failure'));
        try {
            $this->migration()->up();
            $this->fail('Audit failure ignored');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic audit failure', $error->getMessage());
        } finally {
            ActivityEvent::flushEventListeners();
        }
        $this->assertDatabaseHas('tb_FUNDOS_ATA', ['ID' => $id, 'ATA' => null]);
        $this->assertDatabaseCount('activity_events', 0);
        $this->migration()->up();
        $this->assertDatabaseHas('tb_FUNDOS_ATA', ['ID' => $id, 'ATA' => '001/26']);
        $this->assertDatabaseCount('activity_events', 1);
    }
}
