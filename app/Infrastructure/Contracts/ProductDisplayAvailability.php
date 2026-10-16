<?php

namespace App\Infrastructure\Contracts;

use App\Domain\Shared\DecimalAmount;
use App\Models\Contract;
use App\Models\ContractProduct;
use App\Models\ProcurementProduct;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use Brick\Math\BigDecimal;

final class ProductDisplayAvailability
{
    public function calculate(ProcurementProduct $product, Contract $contract, User $actor): ?string
    {
        $quantity = DecimalAmount::normalize($product->QTD);
        if ($quantity === null) {
            return null;
        }
        $document = Normalizer::document($contract->fornecedor_documento_snapshot);
        $rows = ContractProduct::query()
            ->whereIn('contract_id', Contract::visibleTo($actor)->select('id'))
            ->whereRaw('TRIM(item) = ?', [trim($product->item)])
            ->whereRaw('TRIM(Processo) = ?', [trim($contract->processo ?? '')])
            ->whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(CNPJ, '.', ''), '/', ''), '-', ''), ' ', '') = ?", [$document])
            ->select(['id', 'QTD', 'quantity'])->orderBy('id');
        $sum = BigDecimal::zero();
        foreach ($rows->cursor() as $row) {
            $value = DecimalAmount::normalize($row->QTD ?? $row->quantity);
            if ($value === null) {
                return null;
            }
            $sum = $sum->plus($value);
        }
        $difference = $sum->minus($quantity);

        return (string) ($difference->isNegative() ? BigDecimal::zero() : $difference)->toScale(4);
    }
}
