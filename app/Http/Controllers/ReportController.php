<?php

namespace App\Http\Controllers;

use App\Infrastructure\Managers\ManagerSignatory;
use App\Infrastructure\Security\LockActor;
use App\Infrastructure\Security\SelectedFund;
use App\Models\ActivityEvent;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\ReportExport;
use App\Models\SavedReport;
use App\Services\Reports\ContractReport;
use App\Services\Reports\ReportRenderer;
use App\Services\Reports\ReportRequests;
use Dompdf\Dompdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    public function __construct(private LockActor $actors) {}

    public function index(Request $request, ContractReport $service)
    {
        Gate::authorize('viewAny', Contract::class);
        $panelPdf = $request->routeIs('reports.panel-pdf');
        $accessHash = $panelPdf ? ReportExport::accessHash($request->user()) : null;
        $filters = $service->filters($request->query());
        $visible = Contract::visibleTo($request->user());
        $currentYear = today()->year;
        $selectedFund = $request->attributes->get('selected_fund');
        $canSelectScope = $request->user()->isAdmin();
        $reportFunds = $canSelectScope ? app(SelectedFund::class)->options($request->user()) : collect();
        $selection = $request->validate([
            'report_year' => $request->query('report_year') === 'all' ? ['in:all'] : ['nullable', 'integer', 'min:1', 'max:'.$currentYear],
            'report_process' => ['nullable', 'string', 'max:255'],
            'report_bidding' => ['nullable', 'string', 'max:255'],
            'report_bidding_number' => ['nullable', 'string', 'max:255'],
            'report_supplier' => ['nullable', 'string', 'max:255'],
            'report_type' => ['nullable', 'string', 'max:255'],
            'report_end_from' => ['bail', 'nullable', 'date_format:Y-m-d'],
            'report_end_active' => ['nullable', 'boolean'],
            'report_current' => ['nullable', 'boolean'],
            'report_end_to' => ['bail', 'nullable', 'date_format:Y-m-d', ...($request->filled('report_end_from') ? ['after_or_equal:report_end_from'] : [])],
            'report_fund' => $canSelectScope ? ['nullable', 'integer', Rule::in($reportFunds->pluck('id')->all())] : ['prohibited'],
            'report_department' => $canSelectScope ? ['nullable', 'integer'] : ['prohibited'],
        ], ['report_end_to.after_or_equal' => 'A data Até deve ser igual ou posterior à data De.'], [
            'report_end_from' => 'De (data final atualizada)',
            'report_end_to' => 'Até (data final atualizada)',
        ]);
        $reportYear = $request->query->has('report_year') ? ($selection['report_year'] ?? null) : $currentYear;
        if ($reportYear === 'all') {
            $reportYear = null;
        }
        if ($reportYear === null) {
            $request->query->set('report_year', 'all');
        }
        $reportYears = (clone $visible)->where('fund_id', $selectedFund['id'] ?? -1)
            ->whereNotNull('exercicio')->where('exercicio', '<=', $currentYear)
            ->distinct()->orderByDesc('exercicio')->pluck('exercicio')->push($currentYear);
        if ($reportYear !== null) {
            $reportYears->push((int) $reportYear);
        }
        $reportYears = $reportYears->unique()->sortDesc()->values();
        $reportProcess = trim($selection['report_process'] ?? '');
        $reportType = trim($selection['report_type'] ?? '');
        $reportCurrent = $request->boolean('report_current');
        $reportEndFrom = $selection['report_end_from'] ?? null;
        $reportEndTo = $selection['report_end_to'] ?? null;
        $reportEndActive = $request->boolean('report_end_active') || $reportEndFrom !== null || $reportEndTo !== null;
        if ($reportEndActive && $reportEndFrom === null && $reportEndTo === null) {
            $reportEndFrom = today()->toDateString();
        }
        $supplierFilters = [
            'report_bidding' => trim($selection['report_bidding'] ?? ''),
            'report_bidding_number' => trim($selection['report_bidding_number'] ?? ''),
            'report_supplier' => trim($selection['report_supplier'] ?? ''),
        ];
        if ($reportEndActive) {
            $reportYear = null;
            $request->query->remove('report_year');
            $reportProcess = '';
            $reportType = '';
            $reportCurrent = false;
            $supplierFilters = array_fill_keys(array_keys($supplierFilters), '');
            foreach (['report_process', 'report_type', 'report_current', 'report_bidding', 'report_bidding_number', 'report_supplier'] as $filter) {
                $request->query->remove($filter);
            }
        }
        $reportScopeLocked = $reportProcess !== '' || $reportType !== '' || $reportEndActive || $reportCurrent
            || collect($supplierFilters)->contains(fn ($value) => $value !== '');
        $reportFundId = $selection['report_fund'] ?? $selectedFund['id'] ?? -1;
        if ($canSelectScope && $request->query->has('report_fund') && empty($selection['report_fund'])) {
            $reportFundId = null;
        }
        $reportFundIds = $reportFundId === null ? $reportFunds->pluck('id')->all() : [$reportFundId];
        $reportFund = $reportFunds->firstWhere('id', $reportFundId);
        $reportFundName = $reportFundId === null ? 'Todos os Fundos' : ($reportFund?->name ?? ($selectedFund['name'] ?? 'Fundo não selecionado'));
        $canSelectDepartment = $reportFund && ManagerSignatory::isMunicipal($reportFund->name);
        $reportDepartments = $canSelectDepartment
            ? DB::table('contract_departments')->whereIn('id', (clone $visible)->where('fund_id', $reportFundId)->select('department_id'))->orderBy('name')->get(['id', 'name'])
            : collect();
        $reportDepartmentId = $canSelectDepartment ? ($selection['report_department'] ?? null) : null;
        abort_if($reportDepartmentId !== null && ! $reportDepartments->contains('id', $reportDepartmentId), 403);
        $reportTypes = (clone $visible)->whereIn('fund_id', $reportFundIds)
            ->when($reportDepartmentId !== null, fn ($query) => $query->where('department_id', $reportDepartmentId))
            ->when(! $reportEndActive && $reportYear !== null, fn ($query) => $query->where('exercicio', $reportYear))
            ->whereNotNull('tipo')->where('tipo', '<>', '')->distinct()->orderBy('tipo')->pluck('tipo');
        $reportQuery = (clone $visible)->select('contracts.*')->addSelect([
            'latest_amendment_number' => ContractAmendment::select('number')
                ->whereColumn('contract_id', 'contracts.id')->orderByDesc('event_date')->orderByDesc('id')->limit(1),
            'latest_amendment_process' => ContractAmendment::select('payload->Proc_ADT')
                ->whereColumn('contract_id', 'contracts.id')->orderByDesc('event_date')->orderByDesc('id')->limit(1),
        ])->with(['serviceOrders' => fn ($query) => $query->orderBy('issued_on')->orderBy('id')])
            ->whereIn('fund_id', $reportFundIds)
            ->when($reportCurrent, fn ($query) => $query->withReportValidity('vigente'))
            ->when($reportEndFrom !== null, fn ($query) => $query->whereDate('vigencia_fim_atual', '>=', $reportEndFrom))
            ->when($reportEndTo !== null, fn ($query) => $query->whereDate('vigencia_fim_atual', '<=', $reportEndTo))
            ->when($reportType !== '', fn ($query) => $query->where('tipo', $reportType))
            ->when($reportProcess !== '', fn ($query) => $query->whereLike('processo', '%'.$reportProcess.'%'))
            ->when($supplierFilters['report_bidding'] !== '', fn ($query) => $query->whereLike('modalidade', '%'.$supplierFilters['report_bidding'].'%'))
            ->when($supplierFilters['report_bidding_number'] !== '', fn ($query) => $query->whereLike('n_licit', '%'.$supplierFilters['report_bidding_number'].'%'))
            ->when($supplierFilters['report_supplier'] !== '', fn ($query) => $query->whereLike('fornecedor_nome_snapshot', '%'.$supplierFilters['report_supplier'].'%'))
            ->when($reportDepartmentId !== null, fn ($query) => $query->where('department_id', $reportDepartmentId))
            ->when(! $reportEndActive && $reportYear !== null, fn ($query) => $query->where('exercicio', $reportYear))->orderBy('Contrato')->orderBy('id');

        if ($panelPdf) {
            $title = trim(view('reports.panel-title', compact('reportCurrent', 'reportDepartmentId', 'reportDepartments', 'reportProcess', 'supplierFilters', 'reportType', 'reportEndFrom', 'reportEndTo', 'reportFundName', 'reportEndActive', 'reportYear'))->render());
            $rows = $reportQuery->get()->map(fn (Contract $contract) => [
                'number' => $contract->numero ?: (string) $contract->id,
                'process' => $contract->processo,
                'supplier' => $contract->fornecedor_nome_snapshot,
                'bidding' => ($contract->modalidade ?: 'Licitação').' nº '.$contract->n_licit,
                'amendment' => trim((string) ($contract->latest_amendment_number ?? $contract->n_aditivo)),
                'amendment_process' => trim((string) ($contract->latest_amendment_number !== null ? $contract->latest_amendment_process : $contract->proc_aditivo)),
                'fund' => $reportFundId === null ? ($contract->fundo_nome_snapshot ?: 'Não informado') : null,
                'department' => $reportFundId === null ? $contract->secretaria_nome_snapshot : null,
                'object' => ($contract->tipo ? $contract->tipo.' - ' : '').$contract->objeto,
                'service_orders' => in_array(mb_strtolower(trim((string) $contract->tipo)), ['obra', 'obras'], true)
                    ? $contract->serviceOrders->map(fn ($order) => [
                        'number' => $order->number,
                        'issued_on' => $order->issued_on?->format('d/m/Y') ?? 'Não informada',
                        'period' => $order->deadline,
                    ])->all()
                    : [],
                'starts' => $contract->vigencia_inicio?->format('d/m/Y') ?? 'Não informado',
                'ends' => $contract->vigencia_fim_atual?->format('d/m/Y') ?? 'Não informado',
                'amount' => Contract::moneyLabel($contract->valor_acumulado),
            ])->all();
            $pdf = new Dompdf(['isRemoteEnabled' => false]);
            $pdf->setPaper('A4', 'landscape');
            $pdf->loadHtml(view('reports.panel-pdf', compact('title', 'rows'))->render());
            $pdf->render();
            $content = $pdf->output();
            DB::transaction(function () use ($request, $accessHash, $rows, $reportFundIds) {
                $actor = $this->actors->lock($request->user());
                Gate::forUser($actor)->authorize('viewAny', Contract::class);
                foreach (array_unique([...$reportFundIds, (int) $request->session()->get('fund_id')]) as $fundId) {
                    abort_unless(app(SelectedFund::class)->allows($actor, $fundId), 403);
                }
                abort_unless(hash_equals($accessHash, ReportExport::accessHash($actor)), 403);
                ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'report_exported',
                    'metadata' => ['format' => 'pdf', 'report' => 'contract-panel', 'count' => count($rows)], 'created_at' => now()]);
            });

            return response($content, 200, ['Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="relatorio-contratos-'.today()->format('Y-m-d').'.pdf"',
                'Cache-Control' => 'private, no-store']);
        }

        $reportContracts = $reportQuery->paginate(20)->appends(array_map(fn ($value) => $value ?? '', $request->query()));

        if ($reportContracts->currentPage() > $reportContracts->lastPage()) {
            return redirect()->to($reportContracts->url($reportContracts->lastPage()));
        }

        return view('reports.index', compact('reportYears', 'reportCurrent', 'reportScopeLocked', 'reportEndActive', 'reportEndFrom', 'reportEndTo', 'reportTypes', 'reportType', 'supplierFilters', 'reportProcess', 'reportYear', 'canSelectScope', 'reportFunds', 'reportFundId', 'reportFundName', 'reportDepartments', 'reportDepartmentId', 'canSelectDepartment') + ['reportContracts' => $reportContracts, 'currentYear' => $currentYear,
            'presets' => SavedReport::where('user_id', $request->user()->id)->orderBy('name')->get(), 'filters' => $filters, 'summary' => $service->summary($request->user(), $filters),
            'contracts' => $service->query($request->user(), $filters)->paginate(20)->withQueryString(), 'service' => $service,
            'funds' => DB::table('contract_funds')->whereNull('deleted_at')->whereIn('id', (clone $visible)->select('fund_id'))->orderBy('name')->get(),
            'departments' => DB::table('contract_departments')->whereIn('id', (clone $visible)->select('department_id'))->orderBy('name')->get(),
            'inspectors' => (clone $visible)->whereNotNull('nome_fiscal')->where('nome_fiscal', '<>', '')->distinct()->orderBy('nome_fiscal')->pluck('nome_fiscal'),
            'types' => (clone $visible)->whereNotNull('tipo')->distinct()->orderBy('tipo')->pluck('tipo'),
            'modalities' => (clone $visible)->whereNotNull('modalidade')->distinct()->orderBy('modalidade')->pluck('modalidade')]);
    }

    public function export(Request $request, string $format, ContractReport $service, ReportRenderer $renderer)
    {
        Gate::authorize('viewAny', Contract::class);
        abort_unless(in_array($format, ['pdf', 'xlsx', 'csv']), 404);
        $filters = $service->filters($request->query());
        $request->validate(['background' => 'sometimes|boolean']);
        if ($request->boolean('background')) {
            return $this->queueExport($request, $format, $filters);
        }
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
        $export = app(ReportRequests::class)->create($request->user(), $format, $filters);
        if ($request->expectsJson()) {
            return response()->json(['id' => $export->id, 'status_url' => route('reports.status', $export)], 202);
        }

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
        abort_unless($export->sha256 !== null && $export->size !== null, 410,
            'Solicite novamente esta exportação para verificar sua integridade.');
        abort_unless(filesize($path) === $export->size && hash_equals($export->sha256, hash_file('sha256', $path)), 409,
            'A integridade da exportação precisa ser verificada. Solicite um novo arquivo.');
        ActivityEvent::create(['actor_id' => $request->user()->id, 'action' => 'report_downloaded', 'metadata' => ['export_id' => $export->id], 'created_at' => now()]);

        return response()->download($path, ($export->report_kind === 'audit' ? 'auditoria.' : 'contratos.').$export->format, ['Cache-Control' => 'private, no-store']);
    }

    public function status(Request $request, ReportExport $export)
    {
        $actor = $this->actors->current($request->user());
        Gate::forUser($actor)->authorize('viewAny', Contract::class);
        abort_unless($export->user_id === $actor->id, 403);
        abort_if($export->report_kind === 'audit' && ! $actor->isAdmin(), 403);
        $status = $export->expires_at->isFuture() ? $export->status : 'expired';
        $download = null;
        if ($status === 'ready') {
            abort_unless(hash_equals($export->access_hash, ReportExport::accessHash($actor)), 403);
            $status = $export->sha256 !== null && $export->size !== null ? 'ready' : 'expired';
            $download = $status === 'ready' ? route('reports.download', $export) : null;
        }

        return response()->json(['id' => $export->id, 'status' => $status, 'format' => $export->format,
            'download_url' => $download], headers: ['Cache-Control' => 'private, no-store']);
    }

    public function detail(Request $request, Contract $contract)
    {
        $actor = $this->actors->current($request->user());
        $contract = Contract::findOrFail($contract->id);
        Gate::forUser($actor)->authorize('issue', $contract);
        $data = $request->validate(['include_personal' => 'nullable|boolean', 'reason' => 'required_if:include_personal,1|nullable|string|min:10|max:1000']);
        abort_if(! empty($data['include_personal']) && ! $actor->isAdmin(), 403);
        $displayActor = clone $actor;
        if (empty($data['include_personal'])) {
            $displayActor->user_type = 'Usuario';
        }
        $path = storage_path('app/private/detail-'.Str::uuid().'.pdf');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0770, true);
        }
        $accessHash = ReportExport::accessHash($actor);
        try {
            $pdf = new Dompdf(['isRemoteEnabled' => false, 'isPhpEnabled' => false]);
            $pdf->loadHtml(view('reports.detail', ['contract' => $contract, 'actor' => $displayActor])->render());
            $pdf->render();
            file_put_contents($path, $pdf->output());
            abort_unless(hash_equals($accessHash, ReportExport::accessHash($actor)), 403);
            DB::transaction(function () use ($actor, $contract, $data) {
                $currentActor = $this->actors->lock($actor);
                $currentContract = Contract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($currentActor)->authorize('issue', $currentContract);
                abort_unless((int) $currentContract->version === (int) $contract->version, 409);
                ActivityEvent::create(['actor_id' => $currentActor->id, 'action' => 'contract_printed', 'metadata' => ['contract_id' => $contract->id, 'include_personal' => (bool) ($data['include_personal'] ?? false), 'reason' => $data['reason'] ?? null], 'created_at' => now()]);
            });
        } catch (\Throwable $error) {
            @unlink($path);
            throw $error;
        }

        return response()->download($path, 'contrato-'.$contract->id.'.pdf', ['Cache-Control' => 'private, no-store'])->deleteFileAfterSend(true);
    }
}
