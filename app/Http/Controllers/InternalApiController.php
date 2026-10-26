<?php

namespace App\Http\Controllers;

use App\Models\ActivityEvent;
use App\Models\Contract;
use App\Services\Reports\ContractReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class InternalApiController extends Controller
{
    public function index(Request $request, ContractReport $service)
    {
        $request->validate(['include_personal' => 'prohibited', 'per_page' => 'nullable|integer|between:1,100', 'page' => 'nullable|integer|min:1']);
        $filters = $service->filters($request->query());
        $page = $service->query($request->user(), $filters)->paginate((int) $request->input('per_page', 20));
        $rows = $page->getCollection()->map(fn ($contract) => ['id' => $contract->id, 'version' => $contract->version,
            'values' => $this->values($service, $contract, $request, $filters)]);
        $this->audit($request, 'internal_api_list', ['count' => $rows->count(), 'page' => $page->currentPage()]);

        return response()->json(['data' => $rows, 'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(),
            'total' => $page->total(), 'last_page' => $page->lastPage(), 'reference' => $filters['reference'] ?? today()->toDateString(),
            'timezone' => 'America/Sao_Paulo', 'financial_indicator' => 'initial_amount']], headers: ['Cache-Control' => 'private, no-store']);
    }

    public function show(Request $request, Contract $contract, ContractReport $service)
    {
        Gate::authorize('view', $contract);
        $request->validate(['include_personal' => 'prohibited']);
        $this->audit($request, 'internal_api_detail', ['contract_id' => $contract->id]);

        return response()->json(['data' => ['id' => $contract->id, 'version' => $contract->version,
            'values' => $this->values($service, $contract, $request, [])]], headers: ['Cache-Control' => 'private, no-store']);
    }

    private function values(ContractReport $service, Contract $contract, Request $request, array $filters): array
    {
        $values = array_combine($filters['columns'] ?? ContractReport::COLUMNS, $service->row($contract, $request->user(), $filters));
        // The API's existing initial_amount field is a public contract, not a report label.
        if (array_key_exists('initial_amount', $values)) {
            $values['initial_amount'] = $contract->valor_total;
        }

        return $values;
    }

    private function audit(Request $request, string $action, array $metadata): void
    {
        ActivityEvent::create(['actor_id' => $request->user()->id, 'action' => $action, 'metadata' => $metadata, 'created_at' => now()]);
    }
}
