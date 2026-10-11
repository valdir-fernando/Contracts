<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\ContractAttachment;
use App\Services\AmendmentWriter;
use App\Support\AmendmentFields;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ContractAmendmentController extends Controller
{
    public function index(Request $request, Contract $contract)
    {
        Gate::authorize('view', $contract);
        $data = $request->validate(['deleted' => 'nullable|boolean', 'page' => 'nullable|integer|min:1']);
        $query = ContractAmendment::where('contract_id', $contract->id);
        if (! empty($data['deleted'])) {
            abort_unless($request->user()->isAdmin(), 403);
            $query->onlyTrashed();
        }

        return view('amendments.index', ['contract' => $contract,
            'amendments' => $query->orderByDesc('event_date')->orderByDesc('id')->paginate(20)->withQueryString()]);
    }

    public function create(Contract $contract)
    {
        Gate::authorize('update', $contract);

        return $this->form($contract, new ContractAmendment(['payload' => AmendmentWriter::seed($contract), 'status' => 'rascunho']));
    }

    public function store(Request $request, Contract $contract, AmendmentWriter $writer)
    {
        try {
            $amendment = $writer->save($request->user(), $contract, $request->all());
        } catch (ConflictHttpException) {
            Gate::authorize('update', $contract->fresh());

            return $this->form($contract->fresh(), new ContractAmendment(['payload' => AmendmentWriter::seed($contract->fresh()), 'status' => 'rascunho']), $request->all(), true);
        }

        return redirect()->route('contracts.amendments.show', [$contract, $amendment->id])->with('status', 'Aditivo cadastrado. Valores e datas do contrato preservados.');
    }

    public function show(Contract $contract, int $amendment)
    {
        Gate::authorize('view', $contract);
        $amendment = ContractAmendment::where('contract_id', $contract->id)->findOrFail($amendment);

        return view('amendments.show', ['contract' => $contract, 'amendment' => $amendment,
            'groups' => collect(AmendmentFields::MAP)->groupBy('group', preserveKeys: true),
            'document' => ContractAttachment::withTrashed()->where('contract_id', $contract->id)->find($amendment->attachment_id),
        ]);
    }

    public function edit(Contract $contract, int $amendment)
    {
        Gate::authorize('update', $contract);
        $amendment = ContractAmendment::where('contract_id', $contract->id)->findOrFail($amendment);
        abort_if(! auth()->user()->isAdmin() && $amendment->status === 'registrado', 403);

        return $this->form($contract, $amendment);
    }

    public function update(Request $request, Contract $contract, int $amendment, AmendmentWriter $writer)
    {
        try {
            $writer->save($request->user(), $contract, $request->all(), $amendment);
        } catch (ConflictHttpException) {
            Gate::authorize('update', $contract->fresh());
            $current = ContractAmendment::where('contract_id', $contract->id)->findOrFail($amendment);
            abort_if(! $request->user()->isAdmin() && $current->status === 'registrado', 403);

            return $this->form($contract->fresh(), $current, $request->all(), true);
        }

        return redirect()->route('contracts.amendments.show', [$contract, $amendment])->with('status', 'Aditivo atualizado. Valores e datas do contrato preservados.');
    }

    public function destroy(Request $request, Contract $contract, int $amendment, AmendmentWriter $writer)
    {
        $writer->change($request->user(), $contract, $amendment, $request->all(), false);

        return redirect()->route('contracts.amendments.index', $contract)->with('status', 'Aditivo excluído. Histórico preservado.');
    }

    public function restore(Request $request, Contract $contract, int $amendment, AmendmentWriter $writer)
    {
        $writer->change($request->user(), $contract, $amendment, $request->all(), true);

        return redirect()->route('contracts.amendments.show', [$contract, $amendment])->with('status', 'Aditivo restaurado.');
    }

    private function form(Contract $contract, ContractAmendment $amendment, array $values = [], bool $conflict = false)
    {
        return response()->view('amendments.form', ['contract' => $contract, 'amendment' => $amendment,
            'groups' => collect(AmendmentFields::MAP)->groupBy('group', preserveKeys: true),
            'documents' => ContractAttachment::where('contract_id', $contract->id)->orderBy('name')->get(['id', 'name']),
            'values' => $values, 'conflict' => $conflict,
        ], $conflict ? 409 : 200);
    }
}
