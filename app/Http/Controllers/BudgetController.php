<?php

namespace App\Http\Controllers;

use App\Infrastructure\Budgets\BudgetRegistration;
use App\Infrastructure\Security\CatalogAccess;
use App\Infrastructure\Security\LockActor;
use App\Services\Legacy\Normalizer;
use Illuminate\Http\Request;

final class BudgetController extends Controller
{
    public function lookup(Request $request, LockActor $actors, BudgetRegistration $budgets)
    {
        $actor = $actors->current($request->user());
        CatalogAccess::authorize($actor, 'budget');
        $data = $request->validate(['ficha' => ['required', 'string', 'max:32', 'regex:/^[0-9]+$/D']]);
        $records = $budgets->visible($actor)->whereRaw('TRIM(Ficha) = ?', [$data['ficha']])->limit(2)->get(['ID_Dotacao']);
        if ($records->count() > 1) {
            return response()->json(['message' => 'Há mais de uma dotação com esta ficha. Abra o registro pela lista.'], 409)->header('Cache-Control', 'private, no-store');
        }

        return response()->json($records->isEmpty() ? ['status' => 'not_found'] : [
            'status' => 'found', 'url' => route('catalogs.budgets.create', ['edit' => $records->first()->ID_Dotacao]),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request, LockActor $actors, BudgetRegistration $budgets)
    {
        $actor = $actors->current($request->user());
        CatalogAccess::authorize($actor, 'budget');
        $data = $request->validate(['edit' => 'nullable|integer|min:1', 'q' => 'nullable|string|max:100',
            'ficha' => 'nullable|string|max:32', 'fund_id' => 'nullable|integer|min:1', 'department_id' => 'nullable|integer|min:1',
            'filter_fund_id' => 'nullable|required_with:filter_department_id|integer|min:1', 'filter_department_id' => 'nullable|required_with:filter_fund_id|integer|min:1']);
        $record = empty($data['edit']) ? null : $budgets->visible($actor)->where('ID_Dotacao', $data['edit'])->first();
        abort_if(! empty($data['edit']) && ! $record, 404);
        $funds = CatalogAccess::funds($actor)->orderBy('name')->get(['id', 'name']);
        $departments = CatalogAccess::departments($actor)->orderBy('name')->get(['id', 'name']);
        $fundId = $record ? $funds->first(fn ($fund) => Normalizer::name($fund->name) === Normalizer::name($record->Fundo))?->id
            : ($data['fund_id'] ?? $data['filter_fund_id'] ?? $request->session()->get('fund_id'));
        $departmentId = $record ? $departments->first(fn ($department) => Normalizer::name($department->name) === Normalizer::name($record->Secretaria))?->id : ($data['department_id'] ?? $data['filter_department_id'] ?? null);
        $query = $budgets->visible($actor);
        if (! empty($data['filter_department_id'])) {
            $filterFund = $funds->firstWhere('id', (int) $data['filter_fund_id']);
            $filterDepartment = $departments->firstWhere('id', (int) $data['filter_department_id']);
            abort_unless($filterFund && $filterDepartment, 403);
            CatalogAccess::destination($actor, $filterFund->id, $filterDepartment->id);
            abort_unless(Normalizer::name($filterFund->name) === 'MUNICIPIO DE NIQUELANDIA', 422, 'O filtro de Secretaria exige o Fundo Município.');
            $departmentName = Normalizer::name($filterDepartment->name);
            $query->whereIn('Fundo', [$filterFund->name, Normalizer::name($filterFund->name)])
                ->whereIn('Secretaria', [$filterDepartment->name, $departmentName,
                    preg_replace('/^SECRETARIA MUNICIPAL\b/', 'SEC MUNICIPAL', $departmentName),
                    preg_replace('/^SECRETARIA MUNICIPAL\b/', 'SEC. MUNICIPAL', $departmentName)]);
        }
        $records = $query->when(! empty($data['q']), fn ($query) => $query->where(fn ($query) => $query->where('Ficha', 'like', '%'.$data['q'].'%')->orWhere('Descricao', 'like', '%'.$data['q'].'%')))
            ->orderBy('Ficha')->orderBy('ID_Dotacao')->select(['ID_Dotacao', 'Ficha', 'Dotacao', 'Gestao', 'Fonte', 'Descricao'])->paginate(25)->withQueryString();

        if ($request->expectsJson()) {
            return response()->json(['html' => view('administration.budget-list', compact('records', 'record'))->render()])->header('Cache-Control', 'private, no-store');
        }

        return response()->view('administration.budget-create', compact('record', 'records', 'funds', 'departments', 'fundId', 'departmentId') + [
            'fields' => BudgetRegistration::FIELDS, 'revision' => $record ? $budgets->revision($record) : '', 'initialFicha' => $data['ficha'] ?? '',
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, BudgetRegistration $budgets)
    {
        $id = $budgets->save($request->user(), null, $request->all());

        return redirect()->route('catalogs.budgets.create', ['edit' => $id])->with('status', 'Dotação salva.')->with('budget_saved', true);
    }

    public function update(Request $request, int $budget, BudgetRegistration $budgets)
    {
        $budgets->save($request->user(), $budget, $request->all());

        return redirect()->route('catalogs.budgets.create', ['edit' => $budget])->with('status', 'Dotação salva.')->with('budget_saved', true);
    }

    public function destroy(Request $request, int $budget, BudgetRegistration $budgets)
    {
        $budgets->save($request->user(), $budget, $request->all(), true);

        return redirect()->route('catalogs.budgets.create')->with('status', 'Dotação excluída.')->with('budget_saved', true);
    }
}
