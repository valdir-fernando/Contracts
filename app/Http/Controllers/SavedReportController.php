<?php

namespace App\Http\Controllers;

use App\Models\ActivityEvent;
use App\Models\Contract;
use App\Models\SavedReport;
use App\Models\User;
use App\Services\Reports\ContractReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SavedReportController extends Controller
{
    public function store(Request $request, ContractReport $service)
    {
        Gate::authorize('viewAny', Contract::class);
        $data = $request->validate(['name' => 'required|string|max:80|regex:/\S/u', 'filters' => 'required|array',
            'filters.include_personal' => 'prohibited', 'filters.reason' => 'prohibited']);
        $filters = $service->filters($request->input('filters'));
        $service->query($request->user(), $filters);
        DB::transaction(function () use ($request, $data, $filters) {
            $actor = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('viewAny', Contract::class);
            abort_unless($actor->session_version === $request->user()->session_version, 403);
            $own = SavedReport::where('user_id', $actor->id);
            if ((clone $own)->count() >= 50 || (clone $own)->where('name', trim($data['name']))->exists()) {
                throw ValidationException::withMessages(['name' => 'Use um nome novo. Limite: 50 relatórios salvos por usuário.']);
            }
            $saved = SavedReport::create(['user_id' => $actor->id, 'name' => trim($data['name']), 'filters' => $filters]);
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'report_preset_created', 'metadata' => ['preset_id' => $saved->id], 'created_at' => now()]);
        });

        return redirect()->route('reports.index', $filters)->with('status', 'Relatório personalizado salvo na sua conta.');
    }

    public function show(Request $request, int $savedReport, ContractReport $service)
    {
        Gate::authorize('viewAny', Contract::class);
        $saved = SavedReport::where('user_id', $request->user()->id)->findOrFail($savedReport);
        $filters = $service->filters($saved->filters);
        $service->query($request->user(), $filters);

        return redirect()->route('reports.index', $filters);
    }

    public function destroy(Request $request, int $savedReport)
    {
        Gate::authorize('viewAny', Contract::class);
        DB::transaction(function () use ($request, $savedReport) {
            $saved = SavedReport::where('user_id', $request->user()->id)->lockForUpdate()->findOrFail($savedReport);
            $saved->delete();
            ActivityEvent::create(['actor_id' => $request->user()->id, 'action' => 'report_preset_deleted', 'metadata' => ['preset_id' => $savedReport], 'created_at' => now()]);
        });

        return redirect()->route('reports.index')->with('status', 'Relatório personalizado removido.');
    }
}
