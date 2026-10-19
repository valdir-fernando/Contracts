<?php

namespace Tests\Unit;

use App\Infrastructure\Documents\SupplyContractFigures;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SupplyContractFiguresTest extends TestCase
{
    private function row(array $changes = []): array
    {
        return array_replace(['item' => '2', 'code' => '30149', 'description' => 'Produto', 'brand' => 'Marca', 'unit' => 'UND',
            'quantity' => '1.0000', 'unit_price' => '327990.0000', 'total' => '327990.0000', 'Ficha' => '001'], $changes);
    }

    public function test_consolidation_uses_item_not_code_or_ficha_and_preserves_exact_decimals(): void
    {
        $rows = [$this->row(['item' => '10', 'quantity' => '0.0001', 'unit_price' => '10000.0000', 'total' => '1.0000']),
            $this->row(), $this->row(['Ficha' => '002']),
            $this->row(['item' => '3', 'unit_price' => '333990.0000', 'total' => '333990.0000'])];
        $result = (new SupplyContractFigures)->products($rows, '989971.0000');
        $this->assertSame(['2', '3', '10'], array_column($result['items'], 'item'));
        $this->assertSame('2.0000', $result['items'][0]['quantity']);
        $this->assertSame('655980.0000', $result['items'][0]['total']);
        $this->assertSame('0.0001', $result['items'][2]['quantity']);
        $this->assertSame('989971.0000', $result['total']);
    }

    public function test_large_values_do_not_lose_cents_through_floating_point(): void
    {
        $result = (new SupplyContractFigures)->products([$this->row(['unit_price' => '99999999999999.9999', 'total' => '99999999999999.9999'])], '99999999999999.9999');
        $this->assertSame('99999999999999.9999', $result['items'][0]['total']);
    }

    public function test_persisted_half_up_rounding_at_four_places_is_honored(): void
    {
        $result = (new SupplyContractFigures)->products([$this->row(['quantity' => '0.3333', 'unit_price' => '0.3333', 'total' => '0.1111'])], '0.1111');
        $this->assertSame('0.1111', $result['total']);
    }

    public static function incompatibleFields(): array
    {
        return [['code', 'Outro código'], ['description', 'Outro produto'], ['brand', 'Outra marca'], ['unit', 'CX'], ['unit_price', '327991.0000']];
    }

    #[DataProvider('incompatibleFields')]
    public function test_incompatible_rows_with_same_item_are_rejected(string $field, string $value): void
    {
        $changes = [$field => $value];
        if ($field === 'unit_price') {
            $changes['total'] = $value;
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Item 2');
        (new SupplyContractFigures)->products([$this->row(), $this->row($changes)], '655980.0000');
    }

    public static function invalidFigures(): array
    {
        return [
            [['quantity' => '0.0000'], '327990.0000', 'quantidade'],
            [['quantity' => '-1.0000'], '327990.0000', 'quantidade'],
            [['unit_price' => 'abc'], '327990.0000', 'decimal'],
            [['total' => '327989.9999'], '327989.9999', 'QTD ×'],
            [[], '327989.9999', 'soma dos produtos'],
            [[], null, 'valor inicial'],
        ];
    }

    #[DataProvider('invalidFigures')]
    public function test_inconsistent_figures_are_rejected(array $changes, ?string $contractTotal, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        (new SupplyContractFigures)->products([$this->row($changes)], $contractTotal);
    }

    public function test_rounding_split_across_source_rows_cannot_silently_change_group_total(): void
    {
        $row = $this->row(['quantity' => '0.0001', 'unit_price' => '0.5000', 'total' => '0.0001']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('total somado');
        (new SupplyContractFigures)->products([$row, $row], '0.0002');
    }

    public function test_sum_of_displayed_item_cents_must_match_displayed_contract_total(): void
    {
        $row = $this->row(['quantity' => '1.0000', 'unit_price' => '0.0050', 'total' => '0.0050']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('centavos');
        (new SupplyContractFigures)->products([$row, array_replace($row, ['item' => '3'])], '0.0100');
    }

    public function test_budget_values_are_summed_by_exact_ficha_without_duplicate_allocation_lines(): void
    {
        $allocation = ['ficha' => '001', 'allocation' => '01.02'];
        $result = (new SupplyContractFigures)->budgets([
            ['Ficha' => '001', 'total' => '0.1000'], ['Ficha' => '001', 'total' => '0.2000'], ['Ficha' => '1', 'total' => '4.0000'],
        ], [$allocation, $allocation, ['ficha' => '1', 'allocation' => '03.04'], ['ficha' => 'UNUSED', 'allocation' => 'IGNORED']], '4.3000');
        $this->assertSame([['ficha' => '001', 'allocation' => '01.02', 'amount' => '0.3000'],
            ['ficha' => '1', 'allocation' => '03.04', 'amount' => '4.0000']], $result);
    }

    public function test_conflicting_budget_codes_for_same_ficha_are_rejected(): void
    {
        $row = ['ficha' => '001', 'allocation' => '01.02'];
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dotações diferentes');
        (new SupplyContractFigures)->budgets([['Ficha' => '001', 'total' => '1.0000']], [$row, array_replace($row, ['allocation' => '03.04'])], '1.0000');
    }

    public function test_budget_sum_must_match_contract_total(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('valor total do contrato');
        (new SupplyContractFigures)->budgets([['Ficha' => '0', 'total' => '1.0000']], [['ficha' => '0', 'allocation' => '01']], '2.0000');
    }

    public function test_budget_displayed_cents_must_match_contract_total(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('centavos');
        (new SupplyContractFigures)->budgets([['Ficha' => '0', 'total' => '0.0050'], ['Ficha' => '1', 'total' => '0.0050']],
            [['ficha' => '0', 'allocation' => '01'], ['ficha' => '1', 'allocation' => '02']], '0.0100');
    }
}
