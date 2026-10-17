<?php

namespace App\Http\Controllers;

use App\Application\Security\ActorIdentity;
use App\Domain\Shared\DecimalAmount;
use App\Infrastructure\Contracts\BudgetCatalog;
use App\Infrastructure\Contracts\FichaValuesCatalog;
use App\Infrastructure\Contracts\ProcurementProducts;
use App\Infrastructure\Contracts\ProductDisplayAvailability;
use App\Infrastructure\Http\ApplicationErrors;
use App\Infrastructure\Security\LockActor;
use App\Models\Contract;
use App\Models\ContractFinancialRecord;
use App\Models\ContractProduct;
use App\Models\ProcurementProduct;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ContractProductController extends Controller
{
    private function catalog(Contract $contract, bool $processCatalog): Builder
    {
        $query = ProcurementProduct::query()->whereRaw('TRIM(Processo) = ?', [trim($contract->processo ?? '')])
            ->when(! filled($contract->processo), fn ($q) => $q->whereRaw('1=0'));
        if (! $processCatalog) {
            $document = Normalizer::document($contract->fornecedor_documento_snapshot);
            $query->whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(CNPJ_Fornec, '.', ''), '/', ''), '-', ''), ' ', '') = ?", [$document])
                ->when(! Normalizer::validDocument($document), fn ($q) => $q->whereRaw('1=0'));
        }

        return $query;
    }

    private function productData(ProcurementProduct $product, Contract $contract, User $actor): array
    {
        $balance = DecimalAmount::normalize($product->QTD);
        $price = DecimalAmount::normalize($product->VL_Unitario);

        return ['id' => $product->id, 'item' => $product->item, 'code' => $product->Cod_Prod, 'description' => $product->Discriminacao,
            'brand' => $product->Marca, 'unit' => $product->Unidade, 'remaining' => $balance, 'unit_price' => $price,
            'disp' => (new ProductDisplayAvailability)->calculate($product, $contract, $actor),
            'available' => $balance !== null && $price !== null && BigDecimal::of($balance)->isGreaterThan('0') && ! BigDecimal::of($price)->isNegative()];
    }

    public function itemLookup(Request $request, Contract $contract, LockActor $actors)
    {
        $actor = $actors->current($request->user());
        $contract = Contract::findOrFail($contract->id);
        Gate::forUser($actor)->authorize('update', $contract);
        $data = $request->validate(['item' => 'required|string|max:100', 'process_catalog' => 'sometimes|boolean']);
        $processCatalog = (bool) ($data['process_catalog'] ?? false);
        $query = $this->catalog($contract, $processCatalog)->whereRaw('TRIM(item) = ?', [trim($data['item'])]);
        $products = $query->orderBy('id')->limit(2)->get();
        if ($products->isEmpty()) {
            $exists = ! $processCatalog && $this->catalog($contract, true)->whereRaw('TRIM(item) = ?', [trim($data['item'])])->exists();

            return $this->json(['status' => $exists ? 'supplier_mismatch' : 'not_found']);
        }
        if ($products->count() !== 1) {
            return $this->json(['status' => 'ambiguous']);
        }
        $product = $this->productData($products[0], $contract, $actor);

        return $this->json($product['available'] ? ['status' => 'found', 'product' => $product] : ['status' => 'unavailable']);
    }

    private function actor(Request $request): ActorIdentity
    {
        return new ActorIdentity($request->user()->id, $request->user()->session_version);
    }

    private function json(array $data, int $status = 200)
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store');
    }

    public function budgetLookup(Request $request, Contract $contract, LockActor $actors, BudgetCatalog $catalog)
    {
        $actor = $actors->current($request->user());
        $contract = Contract::findOrFail($contract->id);
        Gate::forUser($actor)->authorize('update', $contract);
        $data = $request->validate(['ficha' => 'required|string|max:64']);

        $result = $catalog->lookup(trim($data['ficha']));
        $result['ficha_values'] = (new FichaValuesCatalog)->lookup($contract, trim($data['ficha']));
        if ($result['status'] === 'found') {
            $department = $request->query('department_id', $contract->department_id);
            $departmentId = is_scalar($department) && ctype_digit((string) $department) ? (int) $department : null;
            $result['scope_matches'] = $catalog->matchesScope($result,
                (int) ($request->session()->get('fund_id') ?: $contract->fund_id), $departmentId);
        }

        return $this->json($result);
    }

    public function index(Request $request, Contract $contract, LockActor $actors)
    {
        $actor = $actors->current($request->user());
        $contract = Contract::findOrFail($contract->id);
        Gate::forUser($actor)->authorize('update', $contract);
        $data = $request->validate(['search' => 'nullable|string|max:200', 'page' => 'sometimes|integer|min:1', 'process_catalog' => 'sometimes|boolean']);
        $products = $this->catalog($contract, (bool) ($data['process_catalog'] ?? false))
            ->when(filled($data['search'] ?? null), fn ($q) => $q->where(fn ($q) => $q
                ->where('Discriminacao', 'like', '%'.$data['search'].'%')->orWhere('Cod_Prod', 'like', '%'.$data['search'].'%')->orWhere('item', $data['search'])))
            ->orderBy('id')->paginate(20);
        $rows = $products->map(fn ($product) => $this->productData($product, $contract, $actor));
        $items = ContractProduct::where('contract_id', $contract->id)->orderBy('id')->get()->map(fn ($p) => $p->only([
            'id', 'product_id', 'item', 'code', 'description', 'brand', 'unit', 'quantity', 'unit_price', 'total', 'budget_id', 'Ficha', 'process_catalog',
        ]));
        $budgets = ContractFinancialRecord::where('contract_id', $contract->id)->where('kind', 'dotacao')->orderBy('number')->get()
            ->map(fn ($b) => ['id' => $b->id, 'number' => $b->payload['Ficha'] ?? $b->number,
                'description' => $b->payload['Descricao'] ?? '', 'allocation' => $b->payload['Dotacao'] ?? '']);

        return $this->json(['products' => $rows, 'items' => $items, 'budgets' => $budgets,
            'ficha_values' => (new FichaValuesCatalog)->lookup($contract),
            'page' => $products->currentPage(), 'last_page' => $products->lastPage(),
            'contract_version' => $contract->version, 'total' => $contract->valor_total, 'total_label' => Contract::moneyLabel($contract->valor_total)]);
    }

    public function store(Request $request, Contract $contract, ProcurementProducts $writer)
    {
        return $this->json(ApplicationErrors::translate(fn () => $writer->allocate($this->actor($request), $contract->id, $request->all())), 201);
    }

    public function update(Request $request, Contract $contract, int $item, ProcurementProducts $writer)
    {
        return $this->json(ApplicationErrors::translate(fn () => $writer->allocate($this->actor($request), $contract->id, $request->all(), $item)));
    }

    public function destroy(Request $request, Contract $contract, int $item, ProcurementProducts $writer)
    {
        return $this->json(ApplicationErrors::translate(fn () => $writer->allocate($this->actor($request), $contract->id, $request->all(), $item, true)));
    }
}
