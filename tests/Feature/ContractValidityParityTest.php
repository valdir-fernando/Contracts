<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\User;
use App\Services\ExpirationCalendar;
use App\Services\Reports\ContractReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use Tests\TestCase;
use Tests\Unit\ContractValidityTest;

class ContractValidityParityTest extends TestCase
{
    use RefreshDatabase;

    #[DataProviderExternal(ContractValidityTest::class, 'situations')]
    public function test_historical_classification_is_preserved_while_reports_ignore_review_and_use_updated_end(bool $review, ?string $start, ?string $end, string $reference, string $expected): void
    {
        $this->travelTo(CarbonImmutable::parse($reference));
        $actor = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'SenhaFicticia123', 'user_type' => 'Administrador']);
        $contract = Contract::create(['numero' => '001/26', 'vigencia_inicio' => $start, 'vigencia_fim_original' => $end, 'vigencia_fim_atual' => $end, 'vigencia_em_revisao' => $review, 'quality_issues' => []]);
        $this->assertSame($expected, $contract->validity);
        $report = app(ContractReport::class);
        $reportExpected = $end === null ? 'indeterminado' : ($end > $reference ? 'vigente' : 'vencido');
        $this->assertSame($reportExpected, $contract->reportValidityOn(CarbonImmutable::parse($reference)));
        $this->assertSame([Contract::VALIDITY[$reportExpected]], $report->row($contract, $actor, ['reference' => $reference, 'columns' => ['validity']]));
        foreach (array_keys(Contract::VALIDITY) as $status) {
            $this->assertSame($status === $expected, Contract::withValidity($status)->whereKey($contract->id)->exists());
        }
        foreach (array_keys(Contract::REPORT_VALIDITY) as $status) {
            $this->assertSame($status === $reportExpected, $report->query($actor, ['reference' => $reference, 'validity' => $status])->whereKey($contract->id)->exists());
        }
    }

    public function test_unsaved_models_keep_the_existing_false_review_default(): void
    {
        $contract = new Contract(['vigencia_inicio' => '2026-01-01', 'vigencia_fim_original' => '2026-12-31']);
        $this->assertSame('vigente', $contract->validityOn(CarbonImmutable::parse('2026-10-11')));
        $this->assertSame('indeterminado', (new Contract)->validityOn(CarbonImmutable::parse('2026-10-11')));
    }

    public function test_updated_end_wins_and_report_reference_is_independent_of_today(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-06-01'));
        $actor = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'SenhaFicticia123', 'user_type' => 'Administrador']);
        $contract = Contract::create(['numero' => '001/26', 'vigencia_inicio' => '2026-01-01', 'vigencia_fim_original' => '2026-06-01', 'vigencia_fim_atual' => '2026-12-31', 'quality_issues' => []]);
        $this->assertSame('2026-12-31', $contract->end_date->format('Y-m-d'));
        $this->assertSame('vencido', $contract->validity);
        $report = app(ContractReport::class);
        $this->assertSame(['Vigente'], $report->row($contract, $actor, ['reference' => '2026-10-11', 'columns' => ['validity']]));
        $this->assertTrue($report->query($actor, ['reference' => '2026-10-11', 'validity' => 'vigente'])->whereKey($contract->id)->exists());
    }

    public function test_expiring_report_and_calendar_share_inclusive_limits_and_missing_date_rules(): void
    {
        $actor = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'SenhaFicticia123', 'user_type' => 'Administrador']);
        $from = CarbonImmutable::parse('2026-10-01');
        $to = $from->addDays(30);
        $expected = [];
        foreach ([
            ['2026-10-01', '2026-10-01', false, true],
            ['2026-10-01', '2026-10-31', false, true],
            ['2026-10-01', '2026-11-01', false, false],
            ['2026-10-01', '2026-09-30', false, false],
            [null, '2026-10-15', false, true],
            ['2026-10-01', null, false, false],
            ['2026-10-01', '2026-10-15', true, true],
            // Preserve the calendar's inclusion of contracts that have yet to start.
            ['2026-10-20', '2026-10-25', false, true],
        ] as [$start, $end, $review, $included]) {
            $contract = Contract::create(['vigencia_inicio' => $start, 'vigencia_fim_original' => '2030-12-31', 'vigencia_fim_atual' => $end, 'vigencia_em_revisao' => $review, 'quality_issues' => []]);
            if ($included) {
                $expected[] = $contract->id;
            }
        }
        $reportIds = app(ContractReport::class)->query($actor, ['report' => 'expiring', 'reference' => '2026-10-01', 'days' => 30])->pluck('id')->sort()->values()->all();
        $calendarIds = app(ExpirationCalendar::class)->query($actor, $from, $to)->pluck('id')->sort()->values()->all();
        $this->assertSame($expected, $reportIds);
        $this->assertSame($expected, $calendarIds);
    }
}
