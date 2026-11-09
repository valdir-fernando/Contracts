<?php

namespace Tests\Feature;

use App\Infrastructure\Atas\AtaNumbering;
use App\Models\ActivityEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class AtaNumberingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 9)->startOfDay());
    }

    public function test_one_sequence_is_shared_by_all_funds(): void
    {
        $this->assertFalse(Schema::hasColumn('tb_N_ATA', 'fund_id'));
        $this->assertFalse(Schema::hasColumn('tb_N_ATA', 'FUNDO'));
        $this->assertSame('001/26', $this->issue('00.000.000/0000-01'));
        $this->assertSame('002/26', $this->issue('00.000.000/0000-02'));
        $this->assertSame('003/26', $this->issue('00.000.000/0000-01'));
        $this->assertDatabaseCount('tb_N_ATA', 1);
        $this->assertDatabaseHas('tb_N_ATA', ['ANO' => 2026, 'NUMERO' => 3]);
    }

    public function test_new_year_restarts_without_changing_earlier_counter(): void
    {
        DB::table('tb_N_ATA')->insert(['ANO' => 2026, 'NUMERO' => 999]);
        $this->assertSame('1000/26', $this->issue());
        $this->travelTo(now()->setDate(2027, 1, 1));
        $this->assertSame('001/27', $this->issue());
        $this->assertDatabaseHas('tb_N_ATA', ['ANO' => 2026, 'NUMERO' => 1000]);
        $this->assertDatabaseHas('tb_N_ATA', ['ANO' => 2027, 'NUMERO' => 1]);
    }

    public function test_history_uses_number_year_including_deleted_records_and_every_fund(): void
    {
        $this->ata('008/2026');
        $this->ata(" 0057 / 2026\t", '00.000.000/0000-02', true);
        $this->ata('900/2025');
        $this->assertSame('058/26', $this->issue());
        $this->assertDatabaseHas('tb_N_ATA', ['ANO' => 2026, 'NUMERO' => 58]);
        $this->assertDatabaseMissing('tb_N_ATA', ['ANO' => 2025]);
        $this->assertDatabaseHas('tb_ATA', ['ATA' => " 0057 / 2026\t", 'DATA_ATA' => '2025-03-11']);
    }

    public function test_sync_previews_then_reserves_maxima_without_consuming_numbers_or_reducing_counters(): void
    {
        $this->ata('057/2026');
        $this->ata('010/25');
        $this->ata('sem numeração');
        DB::table('tb_N_ATA')->insert(['ANO' => 2025, 'NUMERO' => 20]);
        $numbering = app(AtaNumbering::class);
        $preview = $numbering->synchronizeHistory();
        $this->assertSame([2025 => 10, 2026 => 57], $preview['historical_maxima']);
        $this->assertSame(1, $preview['unrecognized']);
        $this->assertDatabaseCount('tb_N_ATA', 1);
        $this->assertDatabaseCount('activity_events', 0);
        $applied = $numbering->synchronizeHistory(true);
        $this->assertSame([['ANO' => 2026, 'before' => null, 'NUMERO' => 57]], $applied['changes']);
        $this->assertDatabaseHas('tb_N_ATA', ['ANO' => 2025, 'NUMERO' => 20]);
        $this->assertDatabaseHas('tb_N_ATA', ['ANO' => 2026, 'NUMERO' => 57]);
        $this->assertSame([], $numbering->synchronizeHistory(true)['changes']);
        $this->assertDatabaseCount('activity_events', 1);
        $this->assertSame('058/26', $this->issue());
    }

    public function test_sync_command_is_preview_by_default(): void
    {
        $this->ata('057/2026');
        $this->artisan('atas:sync-numbering')->assertSuccessful();
        $this->assertDatabaseCount('tb_N_ATA', 0);
        $this->artisan('atas:sync-numbering', ['--apply' => true])->assertSuccessful();
        $this->assertDatabaseHas('tb_N_ATA', ['ANO' => 2026, 'NUMERO' => 57]);
        $this->assertDatabaseCount('tb_ATA', 1);
    }

    public function test_audit_failure_rolls_back_new_and_existing_counters_and_the_ata(): void
    {
        foreach ([false, true] as $existing) {
            if ($existing) {
                DB::table('tb_N_ATA')->insert(['ANO' => 2026, 'NUMERO' => 57]);
            }
            ActivityEvent::creating(fn () => throw new RuntimeException('audit failed'));
            try {
                $this->issue();
                $this->fail('Expected audit failure');
            } catch (RuntimeException $error) {
                $this->assertSame('audit failed', $error->getMessage());
            } finally {
                ActivityEvent::flushEventListeners();
            }
            $this->assertDatabaseCount('tb_ATA', 0);
            $this->assertDatabaseCount('tb_N_ATA', $existing ? 1 : 0);
        }
        $this->assertSame('058/26', $this->issue());
    }

    public function test_sync_audit_failure_rolls_back_reservation(): void
    {
        $this->ata('057/2026');
        ActivityEvent::creating(fn () => throw new RuntimeException('audit failed'));
        try {
            app(AtaNumbering::class)->synchronizeHistory(true);
            $this->fail('Expected audit failure');
        } catch (RuntimeException $error) {
            $this->assertSame('audit failed', $error->getMessage());
        } finally {
            ActivityEvent::flushEventListeners();
        }
        $this->assertDatabaseCount('tb_N_ATA', 0);
        $this->assertDatabaseCount('tb_ATA', 1);
    }

    public function test_allocation_requires_transaction(): void
    {
        // RefreshDatabase wraps each test; leave its outer transaction for this guard test.
        DB::rollBack();
        $this->expectException(LogicException::class);
        app(AtaNumbering::class)->next();
    }

    public function test_overflow_does_not_wrap_or_save_a_new_ata(): void
    {
        DB::table('tb_N_ATA')->insert(['ANO' => 2026, 'NUMERO' => PHP_INT_MAX]);
        try {
            $this->issue();
            $this->fail('Expected overflow');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('ATA', $error->errors());
        }
        $this->assertDatabaseCount('tb_ATA', 0);
        $this->assertSame((string) PHP_INT_MAX, (string) DB::table('tb_N_ATA')->value('NUMERO'));
    }

    public function test_year_has_database_uniqueness_constraint(): void
    {
        DB::table('tb_N_ATA')->insert(['ANO' => 2026, 'NUMERO' => 1]);
        $this->expectException(QueryException::class);
        DB::table('tb_N_ATA')->insert(['ANO' => 2026, 'NUMERO' => 2]);
    }

    private function issue(string $fund = '00.000.000/0000-01'): string
    {
        return DB::transaction(function () use ($fund) {
            $number = app(AtaNumbering::class)->next();
            $id = $this->ata($number, $fund);
            ActivityEvent::create(['actor_id' => null, 'action' => 'test_ata_created', 'metadata' => ['ata_id' => $id], 'created_at' => now()]);

            return $number;
        });
    }

    private function ata(string $number, string $fund = '00.000.000/0000-01', bool $deleted = false): int
    {
        return DB::table('tb_ATA')->insertGetId(['ATA' => $number, 'DATA_ATA' => '2025-03-11',
            'PROCESSO' => 'processo fictício', 'LICITACAO' => 'PREGÃO ELETRÔNICO', 'N_LICIT' => '001/2026',
            'CNPJ_FUNDO' => $fund, 'source_files' => '[]', 'quality_issues' => '[]', 'import_hash' => str_repeat('0', 64),
            'deleted_at' => $deleted ? now() : null], 'ID');
    }
}
