<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateReport;
use App\Models\ActivityEvent;
use App\Models\Contract;
use App\Models\ReportExport;
use App\Models\SavedReport;
use App\Services\Reports\ContractReport;
use App\Services\Reports\ReportRenderer;
use Dompdf\Dompdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ReportController extends Controller
{
    public function index(Request $request, ContractReport $service)
    {
        $filters = $service->filters($request->query());

        $visible = Contract::visibleTo($request->user());

        return view('reports.index', ['presets' => SavedReport::where('user_id', $request->user()->id)->orderBy('name')->get(), 'filters' => $filters, 'summary' => $service->summary($request->user(), $filters),
            'contracts' => $service->query($request->user(), $filters)->paginate(20)->withQueryString(), 'service' => $service,
            'funds' => DB::table('contract_funds')->whereIn('id', (clone $visible)->select('fund_id'))->orderBy('name')->get(),
            'departments' => DB::table('contract_departments')->whereIn('id', (clone $visible)->select('department_id'))->orderBy('name')->get(),
            'types' => (clone $visible)->whereNotNull('tipo')->distinct()->orderBy('tipo')->pluck('tipo'),
            'modalities' => (clone $visible)->whereNotNull('modalidade')->distinct()->orderBy('modalidade')->pluck('modalidade')]);
    }

    public function export(Request $request, string $format, ContractReport $service, ReportRenderer $renderer)
    {
        Gate::authorize('viewAny', Contract::class);
        abort_unless(in_array($format, ['pdf', 'xlsx', 'csv']), 404);
        $filters = $service->filters($request->query());
        if ($service->query($request->user(), $filters)->count() > 1000) {
            return $this->queueExport($request, $format, $filters);
        }
        $path = storage_path('app/private/report-'.Str::uuid().'.'.$format);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0770, true);
        }
        $accessHash = ReportExport::accessHash($request->user());
        try {
            $renderer->write($request->user(), $filters, $format, $path);
            $request->user()->refresh();
            abort_unless(hash_equals($accessHash, ReportExport::accessHash($request->user())), 403);
            ActivityEvent::create(['actor_id' => $request->user()->id, 'action' => 'report_exported',
                'metadata' => ['format' => $format, 'report' => $filters['report'] ?? 'general',
                    'filters' => collect($filters)->except(['q'])->all(), 'count' => $service->query($request->user(), $filters)->count()], 'created_at' => now()]);
        } catch (\Throwable $error) {
            @unlink($path);
            throw $error;
        }

        return response()->download($path, 'contratos-'.today()->format('Y-m-d').'.'.$format,
            ['Cache-Control' => 'private, no-store'])->deleteFileAfterSend(true);
    }

    private function queueExport(Request $request, string $format, array $filters)
    {
        $export = ReportExport::create(['user_id' => $request->user()->id, 'format' => $format, 'filters' => $filters,
            'access_hash' => ReportExport::accessHash($request->user()), 'expires_at' => now()->addDay()]);
        GenerateReport::dispatch($export->id);

        return redirect()->route('reports.exports')->with('status', 'Relatório solicitado. Acompanhe a geração e baixe o arquivo nesta página.');
    }

    public function exports(Request $request)
    {
        Gate::authorize('viewAny', Contract::class);

        return view('reports.exports', ['exports' => ReportExport::where('user_id', $request->user()->id)->latest('id')->paginate(20)]);
    }

    public function download(Request $request, ReportExport $export)
    {
        Gate::authorize('viewAny', Contract::class);
        abort_unless($export->user_id === $request->user()->id, 403);
        abort_unless(hash_equals($export->access_hash, ReportExport::accessHash($request->user())), 403);
        abort_unless($export->status === 'ready' && $export->expires_at->isFuture(), 410);
        $path = storage_path('app/private/'.basename($export->path));
        abort_unless(is_file($path), 410);
        ActivityEvent::create(['actor_id' => $request->user()->id, 'action' => 'report_downloaded', 'metadata' => ['export_id' => $export->id], 'created_at' => now()]);

        return response()->download($path, ($export->report_kind === 'audit' ? 'auditoria.' : 'contratos.').$export->format, ['Cache-Control' => 'private, no-store']);
    }

    public function detail(Request $request, Contract $contract)
    {
        Gate::authorize('view', $contract);
        $data = $request->validate(['include_personal' => 'nullable|boolean', 'reason' => 'required_if:include_personal,1|nullable|string|min:10|max:1000']);
        abort_if(! empty($data['include_personal']) && ! $request->user()->isAdmin(), 403);
        $displayActor = clone $request->user();
        if (empty($data['include_personal'])) {
            $displayActor->user_type = 'Usuario';
        }
        $path = storage_path('app/private/detail-'.Str::uuid().'.pdf');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0770, true);
        }
        $pdf = new Dompdf(['isRemoteEnabled' => false, 'isPhpEnabled' => false]);
        $pdf->loadHtml(view('reports.detail', ['contract' => $contract, 'actor' => $displayActor])->render());
        $pdf->render();
        file_put_contents($path, $pdf->output());
        ActivityEvent::create(['actor_id' => $request->user()->id, 'action' => 'contract_printed', 'metadata' => ['contract_id' => $contract->id, 'include_personal' => (bool) ($data['include_personal'] ?? false), 'reason' => $data['reason'] ?? null], 'created_at' => now()]);

        return response()->download($path, 'contrato-'.$contract->id.'.pdf', ['Cache-Control' => 'private, no-store'])->deleteFileAfterSend(true);
    }
}
