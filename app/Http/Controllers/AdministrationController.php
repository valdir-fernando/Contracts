<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateReport;
use App\Models\ActivityEvent;
use App\Models\Contract;
use App\Models\ContractAudit;
use App\Models\ContractHistoryRecord;
use App\Models\ImportIssue;
use App\Models\ImportRun;
use App\Models\ReportExport;
use App\Models\User;
use App\Models\UserAudit;
use App\Services\Reports\AuditReportRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdministrationController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $filters = $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d'.($request->filled('from') ? '|after_or_equal:from' : ''),
            'actor' => 'nullable|integer|min:1', 'action' => 'nullable|string|max:64']);
        $apply = function ($query) use ($filters) {
            foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
                if ($filters[$key] ?? null) {
                    $query->whereDate('created_at', $operator, $filters[$key]);
                }
            }
            foreach (['actor' => 'actor_id', 'action' => 'action'] as $key => $column) {
                if ($filters[$key] ?? null) {
                    $query->where($column, $filters[$key]);
                }
            }

            return $query->with('actor')->latest('id');
        };

        return view('administration.index', [
            'queuePending' => DB::table('jobs')->count(), 'queueFailed' => DB::table('failed_jobs')->count(),
            'events' => $apply(ActivityEvent::query())->paginate(20, ['*'], 'events_page')->withQueryString(),
            'audits' => $apply(ContractAudit::query())->paginate(20, ['*'], 'audits_page')->withQueryString(),
            'userAudits' => $apply(UserAudit::query())->paginate(20, ['*'], 'users_page')->withQueryString(),
            'history' => ContractHistoryRecord::whereNull('contract_id')->paginate(20, ['*'], 'history_page')->withQueryString(),
            'issues' => ImportIssue::latest('id')->paginate(20, ['*'], 'issues_page')->withQueryString(),
            'runs' => ImportRun::latest('id')->paginate(10, ['*'], 'runs_page')->withQueryString(),
        ]);
    }

    public function export(Request $request, string $format, AuditReportRenderer $renderer)
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_unless(in_array($format, ['csv', 'xlsx', 'pdf']), 404);
        $filters = $request->validate(['from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d'.($request->filled('from') ? '|after_or_equal:from' : ''),
            'actor' => 'nullable|integer|min:1', 'action' => 'nullable|string|max:64']);
        if ($renderer->count($filters) > 1000) {
            $export = ReportExport::create(['user_id' => $request->user()->id, 'format' => $format, 'filters' => $filters,
                'report_kind' => 'audit', 'access_hash' => ReportExport::accessHash($request->user()), 'expires_at' => now()->addDay()]);
            GenerateReport::dispatch($export->id);

            return redirect()->route('reports.exports')->with('status', 'Auditoria solicitada. Acompanhe a geração nesta página.');
        }
        $path = storage_path('app/private/audit-'.Str::uuid().'.'.$format);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0770, true);
        }
        try {
            $count = $renderer->write($request->user(), $filters, $format, $path);
            abort_unless($request->user()->fresh()?->isAdmin(), 403);
            ActivityEvent::create(['actor_id' => $request->user()->id, 'action' => 'audit_exported', 'metadata' => ['format' => $format, 'filters' => $filters, 'count' => $count], 'created_at' => now()]);
        } catch (\Throwable $error) {
            @unlink($path);
            throw $error;
        }

        return response()->download($path, 'auditoria.'.$format, ['Cache-Control' => 'private, no-store'])->deleteFileAfterSend(true);
    }

    public function reconcile(Request $request, ContractHistoryRecord $history)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate(['contract_id' => 'required|integer|exists:contracts,id', 'reason' => 'required|string|min:10|max:1000']);
        DB::transaction(function () use ($request, $history, $data) {
            $actor = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->isAdmin(), 403);
            $record = ContractHistoryRecord::whereKey($history->id)->lockForUpdate()->firstOrFail();
            abort_if($record->contract_id !== null, 409, 'Vínculo já conciliado.');
            $contract = Contract::whereKey($data['contract_id'])->lockForUpdate()->firstOrFail();
            $record->update(['contract_id' => $contract->id, 'match_status' => 'manual_confirmed', 'reviewed_by' => $actor->id, 'review_reason' => $data['reason']]);
            if ($record->kind === 'amendment') {
                $contract->vigencia_em_revisao = true;
                $contract->version++;
                $contract->save();
            }
            ContractAudit::create(['contract_id' => $contract->id, 'actor_id' => $actor->id, 'action' => 'history_linked',
                'reason' => $data['reason'], 'changes' => ['history_id' => ['before' => null, 'after' => $record->id]], 'created_at' => now()]);
        });

        return redirect()->route('administration.index')->with('status', 'Vínculo confirmado. Prazos e valores permanecem sujeitos a revisão.');
    }
}
