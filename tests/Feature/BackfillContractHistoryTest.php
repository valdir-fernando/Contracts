<?php

namespace Tests\Feature;

use App\Infrastructure\Contracts\BackfillContractHistory;
use App\Models\ActivityEvent;
use App\Models\Contract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class BackfillContractHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function contract(string $number, string $date): Contract
    {
        return Contract::create(['numero' => $number, 'data_contrato' => $date,
            'fundo_nome_snapshot' => 'Saúde', 'nome_fiscal' => 'Fiscal salvo', 'decreto_fiscal' => '001/26',
            'tipo' => 'Fornecimento', 'modalidade' => 'Pregão', 'n_licit' => '010/26',
            'processo' => '100/26', 'objeto' => 'Medicamentos', 'quality_issues' => []]);
    }

    public function test_backfill_uses_contract_date_includes_deleted_and_is_repeatable(): void
    {
        $this->contract('001/26', '2026-01-15');
        $this->contract('002/26', '2026-02-15')->delete();
        $this->contract('001/25', '2025-01-15');
        $service = app(BackfillContractHistory::class);
        $this->assertSame(2, $service->run(2026)['missing']);
        $this->assertDatabaseCount('tb_Historico', 0);
        $this->assertSame(2, $service->run(2026, true)['inserted']);
        $this->assertDatabaseHas('tb_Historico', ['Contrato' => '001/26', 'Fiscal' => "Fiscal salvo\n001/26",
            'Historico' => '15/01/2026 - Fornecimento - Pregão 010/26 - 100/26 - OBJETO: Medicamentos']);
        $this->assertSame(0, $service->run(2026, true)['inserted']);
        $this->assertDatabaseCount('tb_Historico', 2);
        $this->assertDatabaseCount('activity_events', 1);
        $this->assertDatabaseCount('contract_audits', 0);
    }

    public function test_audit_failure_rolls_back_history(): void
    {
        $this->contract('001/26', '2026-01-15');
        ActivityEvent::creating(fn () => throw new RuntimeException('audit failure'));
        try {
            app(BackfillContractHistory::class)->run(2026, true);
            $this->fail('Expected audit failure');
        } catch (RuntimeException $error) {
            $this->assertSame('audit failure', $error->getMessage());
        } finally {
            ActivityEvent::flushEventListeners();
        }
        $this->assertSame(0, DB::table('tb_Historico')->count());
    }
}
