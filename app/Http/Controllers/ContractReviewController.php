<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\ContractHistoryRecord;
use App\Services\ContractReview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ContractReviewController extends Controller
{
    public function edit(Request $request, Contract $contract)
    {
        Gate::authorize('update', $contract);
        abort_unless($request->user()->isAdmin(), 403);

        return $this->form($contract);
    }

    public function update(Request $request, Contract $contract, ContractReview $review)
    {
        try {
            $review->confirm($request->user(), $contract, $request->all());
        } catch (ConflictHttpException) {
            $contract = $contract->fresh();
            Gate::authorize('update', $contract);
            abort_unless($request->user()->isAdmin(), 403);

            return $this->form($contract, $request->all(), true);
        }

        return redirect()->route('contracts.show', $contract)->with('status', 'Conferência registrada. Vigência revisada e valores informados preservados na auditoria.');
    }

    private function form(Contract $contract, array $values = [], bool $conflict = false)
    {
        return response()->view('contracts.review', ['contract' => $contract, 'values' => $values, 'conflict' => $conflict,
            'amendments' => ContractAmendment::where('contract_id', $contract->id)->where('status', 'registrado')->orderBy('event_date')->get(),
            'history' => ContractHistoryRecord::where('contract_id', $contract->id)->where('kind', 'amendment')->get()], $conflict ? 409 : 200);
    }
}
