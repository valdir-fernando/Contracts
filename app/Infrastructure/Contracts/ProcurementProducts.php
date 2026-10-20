<?php

namespace App\Infrastructure\Contracts;

use App\Application\Contracts\Ports\ContractAccess;
use App\Application\Ports\TransactionManager;
use App\Application\Security\ActorIdentity;
use App\Domain\Shared\DecimalAmount;
use App\Infrastructure\Persistence\TransactionGuard;
use App\Models\Contract;
use App\Models\ContractAudit;
use App\Models\ContractFinancialRecord;
use App\Models\ContractProduct;
use App\Models\ProcurementProduct;
use App\Services\Legacy\Normalizer;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Distribute the existing catalog; no product creation or external services. */
final class ProcurementProducts
{
    public function __construct(private TransactionManager $transactions, private ContractAccess $access) {}

    public static function decimal(mixed $value, bool $positive = true): string
    {
        $amount = is_string($value) ? DecimalAmount::normalize($value) : null;
        if ($amount === null || BigDecimal::of($amount)->isNegative() || ($positive && BigDecimal::of($amount)->isZero())) {
            throw ValidationException::withMessages(['quantity' => 'Informe um decimal válido, positivo, com até quatro casas.']);
        }

        return $amount;
    }

    public function allocate(ActorIdentity $actor, int $id, array $input, ?int $allocationId = null, bool $remove = false): array
    {
        return $this->transactions->run(function () use ($actor, $id, $input, $allocationId, $remove) {
            $context = $this->access->lockForSave($actor, $id);
            TransactionGuard::requireActive();
            $allocation = $allocationId ? ContractProduct::where('contract_id', $id)->whereKey($allocationId)->lockForUpdate()->firstOrFail() : null;
            $manual = $allocation ? $allocation->product_id === null : filter_var($input['manual'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $data = Validator::make($input, [
                'contract_version' => 'required|integer|min:1',
                'product_id' => $allocationId || $manual ? 'prohibited' : 'required|integer|min:1',
                'manual' => $remove ? 'prohibited' : 'sometimes|boolean',
                'quantity' => $remove ? 'prohibited' : 'required|string|max:40',
                'budget_id' => $remove ? 'prohibited' : 'sometimes|nullable|integer|min:1',
                'ficha' => $remove ? 'prohibited' : 'sometimes|nullable|string|max:64',
                'process_catalog' => $remove ? 'prohibited' : 'sometimes|boolean',
                'unit_price' => $remove ? 'prohibited' : 'sometimes|required|string|max:40',
                'description' => $manual && ! $remove ? 'required|string|max:5000' : 'prohibited',
                'unit' => $manual && ! $remove ? 'required|string|max:40' : 'prohibited',
                'code' => $manual && ! $remove ? 'sometimes|nullable|string|max:100' : 'prohibited',
                'brand' => $manual && ! $remove ? 'sometimes|nullable|string|max:255' : 'prohibited',
                'item' => $manual && ! $remove ? 'sometimes|nullable|string|max:100' : 'prohibited',
            ])->validate();
            if (array_key_exists('manual', $data) && (bool) $data['manual'] !== $manual) {
                throw ValidationException::withMessages(['manual' => 'A origem do lançamento não pode ser alterada.']);
            }
            if ($manual && ! $remove && ! array_key_exists('unit_price', $data)) {
                throw ValidationException::withMessages(['unit_price' => 'Informe o valor unitário.']);
            }
            if ((int) $data['contract_version'] !== $context->contract->version()) {
                throw new ConflictHttpException('O contrato mudou. Recarregue a página antes de continuar.');
            }
            $contract = Contract::findOrFail($id);
            abort_if($allocation && ! (new OriginalContractProducts)->isOriginal($allocation, $contract), 404);
            $product = $manual ? null : ProcurementProduct::whereKey($allocation?->product_id ?? $data['product_id'])->lockForUpdate()->firstOrFail();
            $processCatalog = ! $manual && ($remove ? (bool) $allocation->process_catalog : (bool) ($data['process_catalog'] ?? $allocation?->process_catalog ?? false));
            abort_unless($manual || (filled($contract->processo) && trim($product->Processo ?? '') === trim($contract->processo)
                && ($processCatalog || (Normalizer::validDocument(Normalizer::document($contract->fornecedor_documento_snapshot))
                && Normalizer::document($product->CNPJ_Fornec) === Normalizer::document($contract->fornecedor_documento_snapshot)))), 404);
            $quantity = $remove ? '0.0000' : self::decimal($data['quantity']);
            $remaining = $manual ? '0.0000' : self::decimal($product->QTD, false);
            $available = BigDecimal::of($remaining)->plus($allocation?->quantity ?? '0');
            if (! $manual && BigDecimal::of($quantity)->isGreaterThan($available)) {
                throw ValidationException::withMessages(['quantity' => 'A quantidade excede o saldo disponível.']);
            }
            $budgetId = array_key_exists('budget_id', $data) ? $data['budget_id'] : $allocation?->budget_id;
            $typedFicha = array_key_exists('ficha', $data);
            if ($typedFicha) {
                $budgetId = null;
            }
            $ficha = $typedFicha ? trim($data['ficha'] ?? '') : ($allocation->Ficha ?? null);
            $ficha = $ficha === '' ? null : $ficha;
            $budget = null;
            if ($budgetId && ! $remove) {
                $budget = ContractFinancialRecord::where('contract_id', $id)->where('kind', 'dotacao')->whereKey($budgetId)->lockForUpdate()->first();
                if (! $budget) {
                    throw ValidationException::withMessages(['budget_id' => 'Selecione uma ficha/dotação ativa deste contrato.']);
                }
            }
            $before = $allocation?->only(['id', 'product_id', 'item', 'code', 'description', 'brand', 'unit', 'quantity', 'unit_price', 'total', 'budget_id', 'Ficha', 'process_catalog']);
            $balanceBefore = $remaining;
            $price = $allocation?->unit_price ?? ($manual ? '0.0000' : self::decimal($product->VL_Unitario, false));
            if (array_key_exists('unit_price', $data)) {
                $normalizedPrice = DecimalAmount::normalize($data['unit_price']);
                if ($normalizedPrice === null || BigDecimal::of($normalizedPrice)->isNegative()) {
                    throw ValidationException::withMessages(['unit_price' => 'Informe um valor unitário válido, não negativo, com até quatro casas decimais.']);
                }
                if (! $manual && ! BigDecimal::of($normalizedPrice)->isEqualTo($price)) {
                    throw ValidationException::withMessages(['unit_price' => 'O valor unitário só pode ser alterado em lançamento manual.']);
                }
                $price = $normalizedPrice;
            }
            $afterBalance = $manual ? null : self::decimal((string) $available->minus($quantity)->toScale(4), false);
            if (! $manual) {
                $product->QTD = $afterBalance;
                $product->remaining = $afterBalance;
                $sourcePrice = self::decimal($product->VL_Unitario, false);
                $product->VL_Total = (string) BigDecimal::of($afterBalance)->multipliedBy($sourcePrice)->toScale(4, RoundingMode::HalfUp);
                $product->save();
            }
            if ($remove) {
                $allocation->delete();
            } else {
                $total = self::decimal((string) BigDecimal::of($quantity)->multipliedBy($price)->toScale(4, RoundingMode::HalfUp), false);
                if (! $allocation) {
                    $source = $manual ? ['item' => $data['item'] ?? 'MANUAL', 'Cod_Prod' => $data['code'] ?? '',
                        'Discriminacao' => $data['description'], 'Unidade' => $data['unit'], 'Marca' => $data['brand'] ?? ''] : Validator::make($product->getAttributes(), [
                            'item' => 'required|string|max:100', 'Cod_Prod' => 'nullable|string|max:100',
                            'Discriminacao' => 'required|string|max:5000', 'Unidade' => 'required|string|max:40', 'Marca' => 'nullable|string|max:255',
                        ])->validate();
                    $allocation = new ContractProduct;
                    $allocation->fill(['contract_id' => $id, 'product_id' => $product?->id, 'item' => $source['item'],
                        'code' => $source['Cod_Prod'] ?? '', 'brand' => $source['Marca'] ?? '',
                        'description' => $source['Discriminacao'], 'unit' => $source['Unidade'],
                        'process_number' => trim($contract->processo ?? ''), 'supplier_document' => Normalizer::document($contract->fornecedor_documento_snapshot),
                        'CNPJ' => Normalizer::document($contract->fornecedor_documento_snapshot), 'Contrato' => $contract->numero,
                        'Processo' => trim($contract->processo ?? ''), 'FUNDO' => $contract->fundo_nome_snapshot]);
                }
                if ($manual) {
                    $allocation->fill(['item' => $data['item'] ?? $allocation->item, 'code' => $data['code'] ?? '',
                        'description' => $data['description'], 'unit' => $data['unit'], 'brand' => $data['brand'] ?? '']);
                }
                $allocation->fill(['quantity' => $quantity, 'unit_price' => $price, 'total' => $total,
                    'process_catalog' => $processCatalog,
                    'budget_id' => $budgetId, 'Ficha' => $budget ? ($budget->payload['Ficha'] ?? $budget->number) : $ficha,
                    'Cod_Prod' => $allocation->code, 'Descricao' => $allocation->description, 'Discriminacao' => $allocation->description,
                    'Marca' => $allocation->brand, 'UND' => $allocation->unit, 'QTD' => $quantity, 'VL_UNIT' => $price, 'TOTAL_UNIT' => $total])->save();
            }
            $sum = BigDecimal::zero();
            foreach (ContractProduct::where('contract_id', $id)->orderBy('id')->lockForUpdate()->get() as $row) {
                $sum = $sum->plus($row->total);
            }
            $total = self::decimal((string) $sum->toScale(4), false);
            $totalBefore = $contract->valor_total;
            $contract->items_managed = true;
            $contract->valor_total = $total;
            $contract->version++;
            $contract->updated_by = $actor->id;
            $contract->save();
            ContractAudit::create(['contract_id' => $id, 'actor_id' => $actor->id,
                'action' => 'products_updated', 'reason' => $manual
                    ? ($remove ? 'Estorno de lançamento manual' : 'Lançamento/alteração manual de objeto do contrato')
                    : ($remove ? 'Estorno de produto e devolução ao saldo' : 'Lançamento/alteração de produto do catálogo existente'),
                'changes' => ['products' => ['before' => $before, 'after' => $remove ? null : $allocation->only(['id', 'product_id', 'item', 'code', 'description', 'brand', 'unit', 'quantity', 'unit_price', 'total', 'budget_id', 'Ficha', 'process_catalog'])],
                    'product_balance' => $manual ? null : ['before' => $balanceBefore, 'after' => $afterBalance], 'valor_total' => ['before' => $totalBefore, 'after' => $total]], 'created_at' => now()]);

            return ['contract_version' => $contract->version, 'total' => $total, 'total_label' => Contract::moneyLabel($total)];
        });
    }
}
