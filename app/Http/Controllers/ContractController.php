<?php

namespace App\Http\Controllers;

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
    public function index(Request $request)
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
        $visible = Contract::visibleTo($request->user());
        if ($request->boolean('deleted')) {
            abort_unless($request->user()->isAdmin(), 403);
            $visible->onlyTrashed();
        }
        // Filter choices come only from accessible contracts, never from all catalogs.
        $funds = DB::table('contract_funds')->whereIn('id', (clone $visible)->select('fund_id'))->orderBy('name')->get(['id', 'name']);
        $departments = DB::table('contract_departments')->whereIn('id', (clone $visible)->select('department_id'))->orderBy('name')->get(['id', 'name']);
        $years = (clone $visible)->whereNotNull('exercicio')->distinct()->orderByDesc('exercicio')->pluck('exercicio');
        $types = (clone $visible)->whereNotNull('tipo')->distinct()->orderBy('tipo')->pluck('tipo');
        $modalities = (clone $visible)->whereNotNull('modalidade')->distinct()->orderBy('modalidade')->pluck('modalidade');
        $contracts = clone $visible;
        ContractSearch::apply($contracts, $filters);
        if ($search = trim($filters['q'] ?? '')) {
            $contracts->where(function ($query) use ($search) {
                foreach (['numero', 'processo', 'fornecedor_nome_snapshot', 'fornecedor_documento_snapshot'] as $column) {
                    $query->orWhereLike($column, '%'.$search.'%');
                }
                $document = preg_replace('/\D/', '', $search);
                if (preg_match('/^[\d\s.\/\-]+$/', $search) && strlen($document) >= 4) {
                    $query->orWhereLike('supplier_document_key', '%'.$document.'%');
                }
            });
        }
        foreach (['fund' => 'fund_id', 'department' => 'department_id'] as $filter => $column) {
            if ($filters[$filter] ?? null) {
                $contracts->where($column, $filters[$filter]);
            }
        }
        if ($filters['validity'] ?? null) {
            $contracts->withValidity($filters['validity']);
        }

        return view('contracts.index', [
            'contracts' => $contracts->select(['deleted_at', 'version', 'administrative_status', 'tipo', 'id', 'numero', 'processo', 'fornecedor_nome_snapshot', 'fornecedor_documento_snapshot',
                'fundo_nome_snapshot', 'secretaria_nome_snapshot', 'valor_total', 'vigencia_inicio', 'vigencia_fim_original',
                'vigencia_fim_atual', 'vigencia_em_revisao', 'ownership_resolved'])->orderBy($filters['sort'] ?? 'exercicio', $filters['direction'] ?? 'desc')->orderByDesc('id')->paginate(20)->withQueryString(),
            'funds' => $funds, 'departments' => $departments, 'years' => $years, 'types' => $types, 'modalities' => $modalities,
        ]);
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
        Gate::authorize('create', Contract::class);

        $data = $request->validate(['supplier' => 'nullable|integer|min:1']);
        $values = [];
        if ($data['supplier'] ?? null) {
            $supplier = SupplierCatalog::visibleTo($request->user())->where('id', $data['supplier'])->first();
            abort_unless($supplier, 404);
            $values = ['fornecedor_nome_snapshot' => $supplier->name, 'fornecedor_documento_snapshot' => $supplier->document_key ?: $supplier->document];
        }

        return $this->form(new Contract, $values);
    }

    public function edit(Contract $contract)
    {
        Gate::authorize('update', $contract);

        return $this->form($contract);
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
            return $this->form($contract->fresh(), $request->all(), true);
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
        if ($duplicate) {
            $response->with('warning', 'Possível duplicidade: existe outro contrato com o mesmo número, fundo, exercício e processo. Revise os registros.');
        }

        return $response;
    }
}
