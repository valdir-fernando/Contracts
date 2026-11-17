<?php

namespace Tests\Unit;

use App\Domain\Atas\AtaExtractPeriod;
use PHPUnit\Framework\TestCase;

class AtaExtractPeriodTest extends TestCase
{
    public function test_one_year_mode_uses_the_requested_pncp_clause_and_ignores_dates(): void
    {
        $this->assertSame('O registro de preços formalizado na presente ata terá vigência de 1 (um) ano, contado a partir do primeiro dia útil subsequente à data de divulgação no PNCP e poderá ser prorrogado, por igual período, desde que comprovado o preço vantajoso, art. 84 da Lei Federal 14.133/21.',
            AtaExtractPeriod::text(true, '1 Ano INPC', '2025-01-01', '2026-01-01'));
    }

    public function test_manual_mode_uses_the_editable_period_and_both_explicit_dates(): void
    {
        $this->assertSame('O registro de preços formalizado na presente ata terá vigência de 24 meses, iniciando-se em 02/06/2025 e término em 02/06/2027 e poderá ser prorrogado, por igual período, desde que comprovado o preço vantajoso, art. 84 da Lei Federal 14.133/21.',
            AtaExtractPeriod::text(false, '24 meses', '2025-06-02', '2027-06-02'));
    }
}
