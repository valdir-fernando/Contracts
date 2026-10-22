<?php

namespace Tests\Unit;

use App\Domain\Contracts\ProductOrigin;
use PHPUnit\Framework\TestCase;

class ProductOriginTest extends TestCase
{
    public function test_only_missing_null_empty_and_whitespace_markers_are_original(): void
    {
        $this->assertFalse(ProductOrigin::isAmendment([]));
        foreach (['ADT', 'Proc_ADT'] as $field) {
            foreach ([null, '', ' ', "\t\n", 0, '0', ' 0 ', '001/25', '2025024745'] as $value) {
                $this->assertSame(! in_array($value, [null, '', ' ', "\t\n"], true), ProductOrigin::isAmendment([$field => $value]));
            }
        }
    }
}
