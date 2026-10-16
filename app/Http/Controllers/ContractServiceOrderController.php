<?php

namespace App\Http\Controllers;

use App\Application\Security\ActorIdentity;
use App\Infrastructure\Contracts\RegisterServiceOrder;
use App\Infrastructure\Http\ApplicationErrors;
use App\Infrastructure\Security\LockActor;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\ContractServiceOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ContractServiceOrderController extends Controller
{
    public function index(Request $request, Contract $contract, LockActor $actors)
    {
        $actor = $actors->current($request->user());
        Gate::forUser($actor)->authorize('update', $contract);
        $request->validate(['page' => 'sometimes|integer|min:1']);
        $orders = ContractServiceOrder::where('contract_id', $contract->id)->orderByDesc('issued_on')->orderByDesc('id')->paginate(10);
        $amendments = ContractAmendment::withTrashed()->where('contract_id', $contract->id)->orderBy('number')->get(['id', 'number', 'deleted_at']);

        return response()->json([
            'orders' => $orders->map(fn ($order) => [
                'id' => $order->id, 'number' => $order->number, 'issued_on' => $order->issued_on->format('d/m/Y'),
                'deadline' => $order->deadline, 'notes' => $order->notes,
                'amendment' => $order->amendment_id ? $amendments->firstWhere('id', $order->amendment_id)?->number : null,
            ]),
            'amendments' => $amendments->whereNull('deleted_at')->map(fn ($amendment) => ['id' => $amendment->id, 'number' => $amendment->number])->values(),
            'page' => $orders->currentPage(), 'last_page' => $orders->lastPage(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Contract $contract, RegisterServiceOrder $writer)
    {
        $actor = $request->user();
        $result = ApplicationErrors::translate(fn () => $writer->save(new ActorIdentity($actor->id, $actor->session_version), $contract->id, $request->all()));

        return response()->json($result, 201)->header('Cache-Control', 'private, no-store');
    }
}
