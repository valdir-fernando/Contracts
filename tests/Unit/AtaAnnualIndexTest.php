<?php

namespace Tests\Unit;

use App\Domain\Atas\AtaAnnualIndex;
use PHPUnit\Framework\TestCase;

final class AtaAnnualIndexTest extends TestCase
{
    public function test_index_keeps_decimal_precision_and_rejects_invalid_input(): void
    {
        $this->assertSame('12.256700', AtaAnnualIndex::normalize('012,256700%'));
        $this->assertSame('0', AtaAnnualIndex::normalize('0'));
        foreach ([null, '', '-2,23', '12,256%%', '12,2.3', 'IPCA'] as $invalid) {
            $this->assertNull(AtaAnnualIndex::normalize($invalid));
        }
    }

    public function test_adjusted_price_truncates_to_the_database_scale(): void
    {
        $this->assertSame('2.6192', AtaAnnualIndex::adjustedUnitPrice('2.3333', '12.256'));
        $this->assertSame('10.0000', AtaAnnualIndex::adjustedUnitPrice('10.0000', '0.000000001'));
        $this->assertNull(AtaAnnualIndex::adjustedUnitPrice('99999999999999.9999', '1'));
    }
}
