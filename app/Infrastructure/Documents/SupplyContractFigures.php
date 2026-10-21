<?php

namespace App\Infrastructure\Documents;

use App\Domain\Shared\DecimalAmount;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/** Exact, read-only consolidation for the supply instrument; never changes persisted amounts. */
final class SupplyContractFigures
{
    public function products(array $rows, ?string $contractTotal, bool $groupByItem = true): array
    {
        if ($rows === []) {
            throw new InvalidArgumentException('Cadastre os produtos do contrato antes de emitir o documento.');
        }
        $expected = $this->decimal($contractTotal, 'O valor inicial do contrato');
        $groups = [];
        foreach ($rows as $row) {
            $item = trim((string) ($row['item'] ?? ''));
            if ($item === '') {
                throw new InvalidArgumentException('Há produto sem número de item. Revise o cadastro antes de emitir.');
            }
            $quantity = $this->decimal($row['quantity'] ?? null, 'A quantidade do item '.$item, positive: true);
            $price = $this->decimal($row['unit_price'] ?? null, 'O valor unitário do item '.$item);
            $total = $this->decimal($row['total'] ?? null, 'O valor total do item '.$item);
            if (! $quantity->multipliedBy($price)->toScale(4, RoundingMode::HalfUp)->isEqualTo($total)) {
                throw new InvalidArgumentException('Item '.$item.': QTD × valor unitário não corresponde ao valor total salvo. Revise o cadastro.');
            }
            $key = $groupByItem ? 'item:'.$item : 'row:'.count($groups);
            if (! isset($groups[$key])) {
                $groups[$key] = ['item' => $item, 'code' => trim($row['code'] ?? ''), 'description' => trim($row['description'] ?? ''),
                    'brand' => trim($row['brand'] ?? ''), 'unit' => trim($row['unit'] ?? ''),
                    'quantity' => BigDecimal::zero(), 'unit_price' => $price, 'total' => BigDecimal::zero()];
            }
            $group = &$groups[$key];
            foreach (['code' => 'códigos', 'description' => 'descrições', 'brand' => 'marcas', 'unit' => 'unidades'] as $field => $label) {
                if ($group[$field] !== trim($row[$field] ?? '')) {
                    throw new InvalidArgumentException('Item '.$item.' possui '.$label.' diferentes. Revise os lançamentos antes de agrupá-los.');
                }
            }
            if (! $group['unit_price']->isEqualTo($price)) {
                throw new InvalidArgumentException('Item '.$item.' possui valores unitários diferentes. Revise os lançamentos antes de agrupá-los.');
            }
            $group['quantity'] = $group['quantity']->plus($quantity);
            $group['total'] = $group['total']->plus($total);
            unset($group);
        }
        $total = BigDecimal::zero();
        $displayed = BigDecimal::zero();
        foreach ($groups as &$group) {
            if (! $group['quantity']->multipliedBy($group['unit_price'])->toScale(4, RoundingMode::HalfUp)->isEqualTo($group['total'])) {
                throw new InvalidArgumentException('Item '.$group['item'].': a QTD somada × valor unitário diverge do total somado, inclusive no arredondamento. Revise o cadastro.');
            }
            $total = $total->plus($group['total']);
            $displayed = $displayed->plus($group['total']->toScale(2, RoundingMode::HalfUp));
            foreach (['quantity', 'unit_price', 'total'] as $field) {
                $group[$field] = (string) $group[$field]->toScale(4);
            }
        }
        unset($group);
        if (! $total->isEqualTo($expected)) {
            throw new InvalidArgumentException('A soma dos produtos não corresponde ao valor inicial do contrato. Total dos produtos: '
                .str_replace('.', ',', (string) $total->toScale(4)).'; valor inicial do contrato: '
                .str_replace('.', ',', (string) $expected->toScale(4)).'. Confira os lançamentos do contrato original antes de emitir.');
        }
        if (! $displayed->isEqualTo($expected->toScale(2, RoundingMode::HalfUp))) {
            throw new InvalidArgumentException('Os totais dos itens arredondados para centavos divergem do total do contrato. Revise os valores antes de emitir.');
        }
        $items = array_values($groups);
        usort($items, static fn ($a, $b) => strnatcmp($a['item'], $b['item']) ?: strcmp($a['item'], $b['item']));

        return ['items' => $items, 'total' => (string) $total->toScale(4)];
    }

    public function budgets(array $products, array $allocations, ?string $contractTotal): array
    {
        $groups = [];
        foreach ($products as $product) {
            $ficha = trim((string) ($product['Ficha'] ?? ''));
            if ($ficha === '') {
                throw new InvalidArgumentException('Há produto sem ficha. Informe a ficha para identificar sua dotação antes de emitir.');
            }
            $key = 'ficha:'.$ficha;
            $groups[$key] ??= ['ficha' => $ficha, 'allocation' => '', 'amount' => BigDecimal::zero()];
            $groups[$key]['amount'] = $groups[$key]['amount']->plus($this->decimal($product['total'] ?? null, 'O valor dos produtos da ficha '.$ficha));
        }
        foreach ($allocations as $allocation) {
            $key = 'ficha:'.trim((string) ($allocation['ficha'] ?? ''));
            if (! isset($groups[$key])) {
                continue;
            }
            $code = trim($allocation['allocation'] ?? '');
            if ($groups[$key]['allocation'] !== '' && $code !== '' && $groups[$key]['allocation'] !== $code) {
                throw new InvalidArgumentException('A ficha '.$groups[$key]['ficha'].' possui dotações diferentes na tb_Dotacao. Revise o cadastro antes de emitir.');
            }
            if ($code !== '') {
                $groups[$key]['allocation'] = $code;
            }
        }
        $sum = BigDecimal::zero();
        $displayed = BigDecimal::zero();
        foreach ($groups as &$group) {
            if ($group['allocation'] === '') {
                throw new InvalidArgumentException('Dotação da ficha '.$group['ficha'].' não encontrada ou não preenchida na tb_Dotacao. Revise o cadastro antes de emitir.');
            }
            $sum = $sum->plus($group['amount']);
            $displayed = $displayed->plus($group['amount']->toScale(2, RoundingMode::HalfUp));
            $group['amount'] = (string) $group['amount']->toScale(4);
        }
        unset($group);
        $expected = $this->decimal($contractTotal, 'O valor inicial do contrato');
        if (! $sum->isEqualTo($expected) || ! $displayed->isEqualTo($expected->toScale(2, RoundingMode::HalfUp))) {
            throw new InvalidArgumentException('A soma dos valores das dotações por ficha não corresponde ao valor total do contrato, inclusive em centavos. Revise os produtos e suas fichas.');
        }

        return array_values($groups);
    }

    private function decimal(?string $value, string $label, bool $positive = false): BigDecimal
    {
        $normalized = DecimalAmount::normalize($value);
        if ($normalized === null) {
            throw new InvalidArgumentException($label.' deve ser um decimal válido com até quatro casas.');
        }
        $amount = BigDecimal::of($normalized);
        if ($amount->isNegative() || ($positive && $amount->isZero())) {
            throw new InvalidArgumentException($label.($positive ? ' deve ser maior que zero.' : ' não pode ser negativo.'));
        }

        return $amount;
    }
}
