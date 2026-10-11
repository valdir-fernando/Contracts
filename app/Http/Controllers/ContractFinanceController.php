<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\ContractFinancialRecord;
use App\Services\FinancialRecordWriter;
use App\Support\FinancialFields;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ContractFinanceController extends Controller
{
    public function index(Request $request, Contract $contract)
    {
        Gate::authorize('view', $contract);
        $filters = $request->validate(['kind' => ['nullable', Rule::in(array_keys(FinancialFields::KINDS))], 'deleted' => 'nullable|boolean', 'year' => 'nullable|integer|between:1000,9999']);
        $query = ContractFinancialRecord::where('contract_id', $contract->id);
        if (! empty($filters['deleted'])) {
            abort_unless($request->user()->isAdmin(), 403);
            $query->onlyTrashed();
        }
        foreach (['kind', 'year'] as $filter) {
            if ($filters[$filter] ?? null) {
                $query->where($filter, $filters[$filter]);
            }
        }
        $totals = [];
        foreach (['ficha', 'empenho'] as $kind) {
            $total = BigDecimal::zero();
            foreach ((clone $query)->where('kind', $kind)->where('status', 'registrado')->cursor() as $record) {
                $total = $total->plus($record->amount ?? '0');
            }
            $totals[$kind] = (string) $total;
        }

        return view('finance.index', ['contract' => $contract, 'filters' => $filters, 'totals' => $totals,
            'records' => $query->orderByDesc('year')->orderByDesc('id')->paginate(20)->withQueryString()]);
    }

    public function create(Request $request, Contract $contract)
    {
        Gate::authorize('update', $contract);
        $kind = $request->validate(['kind' => ['required', Rule::in(array_keys(FinancialFields::KINDS))]])['kind'];

        return $this->form($contract, new ContractFinancialRecord(['kind' => $kind, 'payload' => FinancialFields::seed($kind, $contract), 'status' => 'rascunho', 'year' => today()->year, 'event_date' => today()]));
    }

    public function store(Request $request, Contract $contract, FinancialRecordWriter $writer)
    {
        $kind = $request->validate(['kind' => ['required', Rule::in(array_keys(FinancialFields::KINDS))]])['kind'];
        try {
            $record = $writer->save($request->user(), $contract, $kind, $request->all());
        } catch (ConflictHttpException) {
            $contract = $contract->fresh();
            Gate::authorize('update', $contract);

            return $this->form($contract, new ContractFinancialRecord(['kind' => $kind, 'payload' => FinancialFields::seed($kind, $contract), 'status' => 'rascunho']), $request->all(), true);
        }

        return redirect()->route('contracts.finance.show', [$contract, $record->id])->with('status', 'Registro financeiro cadastrado.');
    }

    public function show(Contract $contract, int $finance)
    {
        Gate::authorize('view', $contract);
        $record = ContractFinancialRecord::where('contract_id', $contract->id)->findOrFail($finance);

        return view('finance.show', ['contract' => $contract, 'record' => $record,
            'budget' => ContractFinancialRecord::withTrashed()->where('contract_id', $contract->id)->find($record->budget_id),
            'amendment' => ContractAmendment::withTrashed()->where('contract_id', $contract->id)->find($record->amendment_id)]);
    }

    public function edit(Contract $contract, int $finance)
    {
        Gate::authorize('update', $contract);
        $record = ContractFinancialRecord::where('contract_id', $contract->id)->findOrFail($finance);
        abort_if(! auth()->user()->isAdmin() && $record->status === 'registrado', 403);

        return $this->form($contract, $record);
    }

    public function update(Request $request, Contract $contract, int $finance, FinancialRecordWriter $writer)
    {
        $record = ContractFinancialRecord::where('contract_id', $contract->id)->findOrFail($finance);
        try {
            $writer->save($request->user(), $contract, $record->kind, $request->all(), $record->id);
        } catch (ConflictHttpException) {
            $contract = $contract->fresh();
            Gate::authorize('update', $contract);
            $record->refresh();
            abort_if(! $request->user()->isAdmin() && $record->status === 'registrado', 403);

            return $this->form($contract, $record, $request->all(), true);
        }

        return redirect()->route('contracts.finance.show', [$contract, $finance])->with('status', 'Registro financeiro atualizado.');
    }

    public function destroy(Request $request, Contract $contract, int $finance, FinancialRecordWriter $writer)
    {
        $writer->change($request->user(), $contract, $finance, $request->all(), false);

        return redirect()->route('contracts.finance.index', $contract)->with('status', 'Registro financeiro excluído.');
    }

    public function restore(Request $request, Contract $contract, int $finance, FinancialRecordWriter $writer)
    {
        $writer->change($request->user(), $contract, $finance, $request->all(), true);

        return redirect()->route('contracts.finance.show', [$contract, $finance])->with('status', 'Registro financeiro restaurado.');
    }

    private function form(Contract $contract, ContractFinancialRecord $record, array $values = [], bool $conflict = false)
    {
        return response()->view('finance.form', ['contract' => $contract, 'record' => $record, 'values' => $values, 'conflict' => $conflict,
            'budgets' => ContractFinancialRecord::where('contract_id', $contract->id)->where('kind', 'dotacao')->orderBy('number')->get(),
            'amendments' => ContractAmendment::where('contract_id', $contract->id)->orderBy('number')->get()], $conflict ? 409 : 200);
    }
}
