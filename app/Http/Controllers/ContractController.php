<?php

namespace App\Http\Controllers;

use App\Infrastructure\Contracts\ContractListingQuery;
use App\Infrastructure\Security\LockActor;
use App\Infrastructure\Security\SelectedFund;
use App\Models\Contract;
use App\Models\ContractAttachment;
use App\Models\ContractAudit;
use App\Models\ContractHistoryRecord;
use App\Models\ContractTypeRule;
use App\Services\ContractLifecycle;
use App\Services\ContractSearch;
use App\Services\ContractWriter;
use App\Services\SupplierCatalog;
use App\Support\ContractFields;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ContractController extends Controller
{
    public function index(Request $request, ContractListingQuery $listing)
    {
        Gate::authorize('viewAny', Contract::class);
        $filters = $request->validate([
            'q' => 'nullable|string|max:255', 'fund' => 'nullable|integer|min:1',
            'department' => 'nullable|integer|min:1', 'year' => 'nullable|integer|min:1|max:9999',
            'validity' => ['nullable', Rule::in(array_keys(Contract::VALIDITY))],
            'page' => 'nullable|integer|min:1',
            'deleted' => 'nullable|boolean',
            'sort' => ['nullable', Rule::in(['exercicio', 'numero', 'valor_total', 'data_contrato'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ] + ContractSearch::rules($request->all()));

        return view('contracts.index', $listing->for($request->user(), $filters));
    }

    public function show(Contract $contract)
    {
        Gate::authorize('view', $contract);

        return view('contracts.show', [
            'contract' => $contract,
            'attachments' => ContractAttachment::with('uploader')->where('contract_id', $contract->id)->latest()->paginate(10, ['*'], 'documents_page'),
            'removedAttachments' => auth()->user()->isAdmin() ? ContractAttachment::onlyTrashed()->where('contract_id', $contract->id)->latest()->paginate(10, ['*'], 'removed_documents_page') : collect(),
            'history' => ContractHistoryRecord::where('contract_id', $contract->id)->orderBy('event_date')->get(),
            'groups' => collect(ContractFields::MAP)->groupBy('group', preserveKeys: true),
            'audits' => auth()->user()->isAdmin() ? ContractAudit::with('actor')->where('contract_id', $contract->id)->latest('id')->limit(20)->get() : collect(),
        ]);
    }

    public function create(Request $request)
    {
        $actor = app(LockActor::class)->current($request->user());
        Gate::forUser($actor)->authorize('create', Contract::class);

        $data = $request->validate(['supplier' => 'nullable|integer|min:1']);
        $selectedFund = $request->attributes->get('selected_fund');
        $values = $selectedFund ? app(SelectedFund::class)->defaults($actor, $selectedFund['id']) : [];
        if ($data['supplier'] ?? null) {
            $supplier = SupplierCatalog::visibleTo($actor)->where('id', $data['supplier'])->first();
            abort_unless($supplier, 404);
            $values += SupplierCatalog::defaults($actor, $supplier);
        }

        return $this->form(new Contract, $values);
    }

    public function edit(Contract $contract)
    {
        Gate::authorize('update', $contract);

        return $this->form($contract);
    }

    public function fundDefaults(Request $request, int $fund, SelectedFund $context)
    {
        Gate::authorize('create', Contract::class);
        $values = $context->defaults($request->user(), $fund);
        abort_unless($values, 403);

        return response()->json(['values' => $values], headers: ['Cache-Control' => 'private, no-store']);
    }

    private function form(Contract $contract, array $values = [], bool $conflict = false)
    {
        $user = auth()->user();
        $funds = DB::table('contract_funds');
        $departments = DB::table('contract_departments');
        if (! $user->isAdmin()) {
            $scopes = $user->scopes()->whereNotNull('fund_id')->get();
            $funds->whereIn('id', $scopes->pluck('fund_id'));
            $departmentIds = Contract::visibleTo($user)->whereNotNull('department_id')->distinct()->pluck('department_id');
            $departments->whereIn('id', $departmentIds->merge($scopes->pluck('department_id'))->filter()->unique());
        }

        return response()->view('contracts.form', ['contract' => $contract,
            'typeRules' => ContractTypeRule::orderBy('name')->get(),
            'history' => ContractHistoryRecord::where('contract_id', $contract->id)->orderBy('event_date')->get(),
            'groups' => collect(ContractFields::MAP)->groupBy('group', preserveKeys: true),
            'funds' => $funds->orderBy('name')->get(), 'departments' => $departments->orderBy('name')->get(),
            'values' => $values, 'conflict' => $conflict], $conflict ? 409 : 200);
    }

    public function store(Request $request, ContractWriter $writer)
    {
        Gate::authorize('create', Contract::class);
        $contract = $writer->save($request->user(), $request->all());

        return $this->savedResponse($request, $contract, 'Contrato cadastrado.');
    }

    public function update(Request $request, Contract $contract, ContractWriter $writer)
    {
        Gate::authorize('update', $contract);
        try {
            $saved = $writer->save($request->user(), $request->all(), $contract);
        } catch (ConflictHttpException) {
            $current = Contract::findOrFail($contract->id);
            $actor = $request->user()->fresh();
            abort_unless($actor && $actor->session_version === $request->user()->session_version, 403);
            Gate::forUser($actor)->authorize('update', $current);

            return $this->form($current, $request->all(), true);
        }

        return $this->savedResponse($request, $saved, 'Contrato atualizado.');
    }

    public function destroy(Request $request, Contract $contract, ContractLifecycle $lifecycle)
    {
        $lifecycle->change($request->user(), $contract, $request->all());

        return redirect()->route('contracts.index')->with('status', 'Contrato excluído. Histórico preservado.');
    }

    public function restore(Request $request, Contract $contract, ContractLifecycle $lifecycle)
    {
        $lifecycle->change($request->user(), $contract, $request->all(), true);

        return redirect()->route('contracts.show', $contract)->with('status', 'Contrato restaurado.');
    }

    private function savedResponse(Request $request, Contract $contract, string $message)
    {
        $duplicate = Contract::visibleTo($request->user())->where('id', '!=', $contract->id)
            ->where('numero', $contract->numero)->where('fund_id', $contract->fund_id)
            ->where('exercicio', $contract->exercicio)->where('processo', $contract->processo)->exists();
        $response = redirect()->route('contracts.show', $contract)->with('status', $message);
        if ($request->routeIs('contracts.store')) {
            $response->with('contract_created', true);
        }
        if ($duplicate) {
            $response->with('warning', 'Possível duplicidade: existe outro contrato com o mesmo número, fundo, exercício e processo. Revise os registros.');
        }

        return $response;
    }
}
