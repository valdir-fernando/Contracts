<?php

namespace Tests\Feature;

use App\Livewire\Overview;
use App\Models\Contract;
use App\Models\User;
use App\Services\Reports\ContractReport;
use App\Services\Reports\ReportRows;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ReportCriteriaTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_calendar_archive_and_dashboard_share_updated_dates_and_values_without_review_exclusions(): void
    {
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 10, 26)->startOfDay());
        $actor = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'SyntheticPassword123', 'user_type' => 'Administrador']);
        $fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo teste', 'name_key' => 'FUNDO TESTE', 'raw_payload' => '{}']);
        $create = fn (array $values) => Contract::create($values + [
            'fund_id' => $fund, 'exercicio' => 2026, 'ownership_resolved' => true,
            'vigencia_inicio' => '2026-01-01', 'data_contrato' => '2025-12-01', 'vigencia_fim_original' => '2030-12-31',
            'vigencia_fim_atual' => '2026-10-31', 'valor_total' => '999.0000',
            'valor_acumulado' => '10.1001', 'quality_issues' => [],
        ]);
        $review = $create(['numero' => 'REVIEW', 'vigencia_em_revisao' => true]);
        $futureStart = $create(['numero' => 'FUTURE', 'vigencia_inicio' => '2026-10-30', 'valor_acumulado' => '0.0000']);
        $missing = $create(['numero' => 'MISSING', 'vigencia_fim_atual' => null, 'valor_acumulado' => null]);
        $today = $create(['numero' => 'TODAY', 'vigencia_fim_atual' => '2026-10-26', 'valor_acumulado' => '20.2002']);
        $expired = $create(['numero' => 'EXPIRED', 'vigencia_fim_atual' => '2026-10-25', 'valor_acumulado' => '30.3003']);
        $this->actingAs($actor)->withSession(['fund_id' => $fund]);

        $this->get('/reports?report_year=&report_current=1')->assertOk()
            ->assertViewHas('reportContracts', fn ($rows) => $rows->pluck('id')->sort()->values()->all() === collect([$review->id, $futureStart->id])->sort()->values()->all());
        $this->get('/contracts?year=&validity=vigente')->assertOk()
            ->assertViewHas('contracts', fn ($rows) => $rows->total() === 2);
        $this->get('/contracts?year=&date_field=end&from=2026-10-31&to=2026-10-31&min_value=0&max_value=11')->assertOk()
            ->assertViewHas('contracts', fn ($rows) => $rows->total() === 2);
        $this->get('/reports?report_end_from=2026-10-31&report_end_to=2026-10-31')->assertOk()
            ->assertViewHas('reportContracts', fn ($rows) => $rows->total() === 2);
        $this->get('/calendar?month=2026-10')->assertOk()
            ->assertViewHas('contracts', fn ($rows) => $rows->total() === 4)
            ->assertViewHas('unknownCount', 1);
        Livewire::actingAs($actor->fresh())->test(Overview::class)->assertViewHas('alertCount', 3)
            ->assertViewHas('expired', 2)
            ->assertViewHas('metrics', fn ($metrics) => $metrics['portfolioValue'] === ['total' => '60.6006', 'missing' => 1]
                && $metrics['summary']['total'] === '60.6006');

        $service = app(ContractReport::class);
        $base = ['fund' => $fund];
        $this->assertSame(2, $service->query($actor, $base + ['validity' => 'vigente'])->count());
        $this->assertSame(4, $service->query($actor, $base + ['from' => '2026-01-01', 'to' => '2026-01-01'])->count());
        $this->assertSame(2, $service->query($actor, $base + ['report' => 'expired'])->count());
        $this->assertSame(3, $service->query($actor, $base + ['report' => 'expiring'])->count());
        $this->assertSame(5, $service->query($actor, $base + ['review' => 1, 'validity' => 'em_revisao'])->count());
        $this->assertSame('60.6006', $service->summary($actor, $base)['total']);
        $this->assertSame(1, $service->summary($actor, $base)['missing']);
        foreach (['fund', 'department', 'supplier', 'inspector', 'type', 'modality'] as $grouping) {
            $summary = $service->summary($actor, $base + ['report' => $grouping]);
            $this->assertSame(5, array_sum(array_column($summary['groups'], 'count')));
            $total = array_reduce($summary['groups'], fn ($sum, $group) => $sum->plus($group['total']), BigDecimal::of('0.0000'));
            $this->assertSame($summary['total'], (string) $total);
        }
        $rows = new ReportRows;
        $service->snapshot($actor, $base + ['q' => 'REVIEW', 'columns' => ['starts_at', 'ends_at', 'initial_amount', 'validity', 'remaining_days']], $rows, false);
        $this->assertSame([['01/01/2026', '31/10/2026', '10,1001', 'Vigente', '5']], iterator_to_array($rows));
        $this->assertSame('indeterminado', $missing->report_validity);
        $this->assertSame('vencido', $today->report_validity);
        $this->assertSame('vencido', $expired->report_validity);
        $this->assertSame('vigente', $futureStart->report_validity);
        $this->assertTrue($review->fresh()->vigencia_em_revisao);
        $this->assertSame('2030-12-31', $review->fresh()->vigencia_fim_original->format('Y-m-d'));
    }
}
