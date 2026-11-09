<?php

namespace Tests\Unit;

use App\Domain\Atas\AtaTerm;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AtaTermTest extends TestCase
{
    public function test_original_period_ends_on_calendar_anniversary_without_subtracting_a_day(): void
    {
        $start = new DateTimeImmutable('2025-03-11');
        $this->assertSame('2026-03-11', AtaTerm::end($start, '12 (doze) meses')->format('Y-m-d'));
        $this->assertSame('2026-03-11', AtaTerm::end($start, '1 (um) ano, podendo ser prorrogada por igual período.')->format('Y-m-d'));
        $this->assertSame('2025-09-11', AtaTerm::end($start, '06 (seis) meses')->format('Y-m-d'));
        $this->assertSame('2025-04-10', AtaTerm::end($start, '30 dias')->format('Y-m-d'));
    }

    public function test_month_end_and_leap_day_are_clamped_to_last_day_of_target_month(): void
    {
        $this->assertSame('2025-02-28', AtaTerm::end(new DateTimeImmutable('2025-01-31'), '1 mês')->format('Y-m-d'));
        $this->assertSame('2025-02-28', AtaTerm::end(new DateTimeImmutable('2024-02-29'), '12 meses')->format('Y-m-d'));
        $this->assertSame('2024-02-29', AtaTerm::end(new DateTimeImmutable('2024-01-31'), '1 mês')->format('Y-m-d'));
    }

    public function test_unknown_period_is_not_guessed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AtaTerm::end(new DateTimeImmutable('2026-01-01'), 'prazo a definir');
    }
}
