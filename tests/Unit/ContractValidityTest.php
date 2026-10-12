<?php

namespace Tests\Unit;

use App\Domain\Contracts\ContractValidity;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ContractValidityTest extends TestCase
{
    public static function situations(): array
    {
        return [
            'review wins over missing dates' => [true, null, null, '2026-10-11', 'em_revisao'],
            'review wins over expired dates' => [true, '2026-01-01', '2026-10-10', '2026-10-11', 'em_revisao'],
            'missing start' => [false, null, '2026-12-31', '2026-10-11', 'indeterminado'],
            'missing end' => [false, '2026-01-01', null, '2026-10-11', 'indeterminado'],
            'both missing' => [false, null, null, '2026-10-11', 'indeterminado'],
            'expired yesterday' => [false, '2026-01-01', '2026-10-10', '2026-10-11', 'vencido'],
            'ends today' => [false, '2026-01-01', '2026-10-11', '2026-10-11', 'vigente'],
            'starts today' => [false, '2026-10-11', '2026-12-31', '2026-10-11', 'vigente'],
            'same-day period' => [false, '2026-10-11', '2026-10-11', '2026-10-11', 'vigente'],
            'starts tomorrow' => [false, '2026-10-12', '2026-12-31', '2026-10-11', 'a_iniciar'],
            'historical reference' => [false, '2026-01-01', '2026-12-31', '2025-12-31', 'a_iniciar'],
            'later reference' => [false, '2026-01-01', '2026-12-31', '2027-01-01', 'vencido'],
            // Imported inconsistent chronology keeps the existing precedence.
            'legacy inconsistent chronology' => [false, '2027-01-01', '2026-01-01', '2026-10-11', 'vencido'],
        ];
    }

    #[DataProvider('situations')]
    public function test_classification_without_laravel_or_database(bool $review, ?string $start, ?string $end, string $reference, string $expected): void
    {
        $this->assertSame($expected, ContractValidity::classify($review, $start ? new DateTimeImmutable($start) : null, $end ? new DateTimeImmutable($end) : null, new DateTimeImmutable($reference)));
    }

    public function test_chronology_uses_civil_dates_and_allows_missing_or_equal_dates(): void
    {
        $start = new DateTimeImmutable('2026-10-11 12:00:00');
        $this->assertFalse(ContractValidity::endsBeforeStart($start, new DateTimeImmutable('2026-10-11 00:00:00')));
        $this->assertTrue(ContractValidity::endsBeforeStart($start, new DateTimeImmutable('2026-10-10')));
        $this->assertFalse(ContractValidity::endsBeforeStart(null, $start));
        $this->assertFalse(ContractValidity::endsBeforeStart($start, null));
        $this->assertSame('vigente', ContractValidity::classify(false, $start, $start, new DateTimeImmutable('2026-10-11 23:59:59')));
    }
}
