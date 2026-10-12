<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\User;
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
    public function test_model_sql_and_report_share_the_same_classification(bool $review, ?string $start, ?string $end, string $reference, string $expected): void
    {
        $this->travelTo(CarbonImmutable::parse($reference));
        $actor = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'SenhaFicticia123', 'user_type' => 'Administrador']);
        $contract = Contract::create(['numero' => '001/26', 'vigencia_inicio' => $start, 'vigencia_fim_original' => $end, 'vigencia_em_revisao' => $review, 'quality_issues' => []]);
        $this->assertSame($expected, $contract->validity);
        $report = app(ContractReport::class);
        $this->assertSame([Contract::VALIDITY[$expected]], $report->row($contract, $actor, ['reference' => $reference, 'columns' => ['validity']]));
        foreach (array_keys(Contract::VALIDITY) as $status) {
            $this->assertSame($status === $expected, Contract::withValidity($status)->whereKey($contract->id)->exists());
            $this->assertSame($status === $expected, $report->query($actor, ['reference' => $reference, 'validity' => $status])->whereKey($contract->id)->exists());
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
}
