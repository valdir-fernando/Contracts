<?php

namespace Tests\Unit;

use App\Domain\Atas\AtaValidity;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class AtaValidityTest extends TestCase
{
    public function test_statuses_use_explicit_dates_and_end_on_reference_date_is_expired(): void
    {
        $today = new DateTimeImmutable('2026-11-09');
        $this->assertSame('vigente', AtaValidity::classify(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-11-10'), $today));
        $this->assertSame('vencido', AtaValidity::classify(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-11-09'), $today));
        $this->assertSame('a_iniciar', AtaValidity::classify(new DateTimeImmutable('2026-11-10'), new DateTimeImmutable('2027-11-10'), $today));
    }

    public function test_missing_or_inverted_dates_have_undefined_validity(): void
    {
        $today = new DateTimeImmutable('2026-11-09');
        $this->assertSame('indeterminado', AtaValidity::classify(null, null, $today));
        $this->assertSame('indeterminado', AtaValidity::classify(null, new DateTimeImmutable('2026-12-01'), $today));
        $this->assertSame('indeterminado', AtaValidity::classify(new DateTimeImmutable('2026-01-01'), null, $today));
        $this->assertSame('indeterminado', AtaValidity::classify(new DateTimeImmutable('2026-12-01'), new DateTimeImmutable('2026-01-01'), $today));
    }
}
