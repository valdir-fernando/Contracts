<?php

namespace App\Http\Controllers;

use App\Application\Security\ActorIdentity;
use App\Infrastructure\Contracts\RegisterServiceOrder;
use App\Infrastructure\Documents\ServiceOrderPdf;
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
        $request->validate(['page' => 'sometimes|integer|min:1', 'scope' => 'sometimes|in:current-fund-year']);
        $query = ContractServiceOrder::query();
        if ($request->query('scope') === 'current-fund-year') {
            $fund = $request->attributes->get('selected_fund');
            abort_unless($fund, 403, 'Selecione um Fundo autorizado.');
            $query->whereIn('contract_id', Contract::visibleTo($actor)->where('fund_id', $fund['id'])->select('id'))
                ->where('issued_on', '>=', now()->startOfYear()->toDateString())
                ->where('issued_on', '<', now()->startOfYear()->addYear()->toDateString());
        } else {
            $query->where('contract_id', $contract->id);
        }
        $orders = $query->orderByDesc('id')->paginate(10);
        $orderContracts = Contract::visibleTo($actor)->whereIn('id', $orders->pluck('contract_id'))->get(['id', 'numero'])->keyBy('id');
        $orderAmendments = ContractAmendment::withTrashed()->whereIn('contract_id', $orderContracts->keys())
            ->whereIn('id', $orders->pluck('amendment_id')->filter())->get(['id', 'number'])->keyBy('id');
        $amendments = ContractAmendment::withTrashed()->where('contract_id', $contract->id)->orderBy('number')->get(['id', 'number', 'deleted_at']);

        return response()->json([
            'orders' => $orders->map(fn ($order) => [
                'id' => $order->id, 'number' => $order->number, 'issued_on' => $order->issued_on->format('d/m/Y'),
                'contract_number' => $orderContracts->get($order->contract_id)?->numero,
                'deadline' => $order->deadline, 'notes' => $order->notes,
                'starts_on' => $order->starts_on?->format('d/m/Y'), 'ends_on' => $order->ends_on?->format('d/m/Y'),
                'pdf_url' => route('contracts.service-orders.pdf', [$order->contract_id, $order]),
                'amendment' => $order->amendment_id ? $orderAmendments->get($order->amendment_id)?->number : null,
            ]),
            'amendments' => $amendments->whereNull('deleted_at')->map(fn ($amendment) => ['id' => $amendment->id, 'number' => $amendment->number])->values(),
            'page' => $orders->currentPage(), 'last_page' => $orders->lastPage(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Contract $contract, RegisterServiceOrder $writer)
    {
        $actor = $request->user();
        $result = ApplicationErrors::translate(fn () => $writer->save(new ActorIdentity($actor->id, $actor->session_version), $contract->id, $request->all()));
        $result['pdf_url'] = route('contracts.service-orders.pdf', [$contract, $result['id']]);

        return response()->json($result, 201)->header('Cache-Control', 'private, no-store');
    }

    public function pdf(Request $request, Contract $contract, ContractServiceOrder $order, ServiceOrderPdf $pdf)
    {
        $bytes = $pdf->generate($request->user(), $contract->id, $order->id);

        return response($bytes)->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="ordem-servico-'.$order->id.'.pdf"')
            ->header('Cache-Control', 'private, no-store');
    }
}
