<?php

namespace Tests\Unit;

use App\Domain\Contracts\ContractNumber;
use App\Domain\Contracts\ContractState;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\TestCase;

class ContractNumberTest extends TestCase
{
    public function test_numbers_have_at_least_three_digits_and_continue_past_999(): void
    {
        foreach ([1 => '001/26', 10 => '010/26', 101 => '101/26', 999 => '999/26', 1000 => '1000/26', 10001 => '10001/26'] as $sequence => $formatted) {
            $number = new ContractNumber($sequence, 2026);
            $this->assertSame($formatted, $number->formatted());
            $this->assertSame((string) $sequence, $number->attributes()['cont_inteiro']);
            $this->assertSame(2026, $number->attributes()['exercicio']);
        }
        $this->assertSame('001/27', (new ContractNumber(1, 2027))->formatted());
    }

    public function test_existing_numbers_are_read_without_changing_their_representation(): void
    {
        $this->assertSame(1000, ContractNumber::sequenceIn('1000/26', 2026));
        $this->assertSame(1, ContractNumber::sequenceIn(' 0001 / 2026 ', 2026));
        $this->assertNull(ContractNumber::sequenceIn('019/25', 2026));
        $this->assertNull(ContractNumber::sequenceIn('EXTRA/26', 2026));
        $this->assertSame(0, ContractNumber::integer('000'));
        $this->assertSame(58, ContractNumber::integer('058'));
    }

    public function test_explicit_generated_year_is_not_inferred_from_the_two_digit_suffix(): void
    {
        $number = new ContractNumber(1, 2100);
        $this->assertSame('001/00', $number->formatted());
        $this->assertSame(2100, (new ContractState(1, $number->attributes()))->exercise());
    }

    public function test_zero_is_not_a_contract_number(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ContractNumber(0, 2026);
    }

    public function test_integer_overflow_is_rejected_without_float_conversion(): void
    {
        $this->expectException(OverflowException::class);
        ContractNumber::integer((string) PHP_INT_MAX.'0');
    }
}
