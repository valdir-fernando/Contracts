<?php

namespace App\Infrastructure\Atas;

use App\Domain\Shared\DecimalAmount;
use App\Infrastructure\Security\LockActor;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Explicit, global ata projection; never selects PDFs or personal participant snapshots. */
class AtaReportQuery
{
    public function __construct(private LockActor $actors) {}

    public function for(User $expected, array $input, bool $paginate = true): array
    {
        $this->actors->current($expected);
        $currentYear = today()->year;
        $rules = ['report_year' => ($input['report_year'] ?? null) === 'all' ? ['in:all'] : ['nullable', 'integer', 'min:1000', 'max:'.$currentYear],
            'report_end_from' => ['nullable', 'date_format:Y-m-d'],
            'report_end_to' => ['nullable', 'date_format:Y-m-d', ...(! empty($input['report_end_from']) ? ['after_or_equal:report_end_from'] : [])],
            'report_end_active' => ['nullable', 'boolean'], 'report_current' => ['nullable', 'boolean'], 'page' => ['nullable', 'integer', 'min:1']];
        foreach (['fund', 'number', 'process', 'supplier', 'bidding', 'bidding_number', 'object'] as $name) {
            $rules['report_'.$name] = ['nullable', 'string', 'max:255'];
        }
        $filters = Validator::make($input, $rules, ['report_end_to.after_or_equal' => 'A data Até deve ser igual ou posterior à data De.'])->validate();
        $year = array_key_exists('report_year', $input) ? ($filters['report_year'] ?? 'all') : $currentYear;
        $endActive = ! empty($filters['report_end_active']) || ! empty($filters['report_end_from']) || ! empty($filters['report_end_to']);
        if ($endActive) {
            $year = 'all';
            if (empty($filters['report_end_from']) && empty($filters['report_end_to'])) {
                $filters['report_end_from'] = today()->toDateString();
            }
        }
        $filters['report_year'] = (string) $year;
        $filters['report_end_active'] = $endActive ? '1' : '0';
        $filters['report_current'] = ! empty($filters['report_current']) ? '1' : '0';
        foreach (['fund', 'number', 'process', 'supplier', 'bidding', 'bidding_number', 'object'] as $name) {
            $filters['report_'.$name] = trim($filters['report_'.$name] ?? '');
        }
        $filters = array_replace(['report_end_from' => null, 'report_end_to' => null], $filters);
        unset($filters['page']);
        $numbers = DB::table('tb_ATA')->whereNull('deleted_at')->get(['ID', 'ATA', 'PROCESSO', 'INICIO_ATA', 'FINAL_ATA']);
        $identity = new AtaAmendmentReader;
        $amendments = [];
        $amendmentEnds = [];
        foreach (DB::table('tb_ADT_ATA')->where('ADT', '>', 0)->whereNotNull('Proc_ATA')
            ->orderByDesc('Data_ADT')->orderByDesc('ID')->get(['ID', 'ATA', 'Proc_ATA', 'ADT', 'Proc_ADT', 'FINAL']) as $amendment) {
            $number = $identity->key($amendment->ATA);
            $process = trim($amendment->Proc_ATA);
            $key = $number.'|'.$process;
            if ($number !== null && $process !== '' && ! isset($amendments[$key])) {
                $amendments[$key] = ['number' => (string) $amendment->ADT, 'process' => trim((string) $amendment->Proc_ADT)];
                $amendmentEnds[$key] = $amendment->FINAL;
            }
        }
        $years = collect([$currentYear]);
        $ids = [];
        $ends = [];
        $reference = today()->toDateString();
        foreach ($numbers as $row) {
            $key = $identity->key($row->ATA);
            $numberYear = $key === null ? null : (int) substr($key, -4);
            if ($numberYear !== null && $numberYear <= $currentYear) {
                $years->push($numberYear);
            }
            $end = $amendmentEnds[$key.'|'.trim($row->PROCESSO)] ?? $row->FINAL_ATA;
            $ends[$row->ID] = $end;
            if ($filters['report_current'] === '1' && ($row->INICIO_ATA === null || $end === null || $row->INICIO_ATA > $reference || $end <= $reference)) {
                continue;
            }
            if (! empty($filters['report_end_from']) && ($end === null || $end < $filters['report_end_from'])) {
                continue;
            }
            if (! empty($filters['report_end_to']) && ($end === null || $end > $filters['report_end_to'])) {
                continue;
            }
            if ($year === 'all' || $numberYear === (int) $year) {
                $ids[] = $row->ID;
            }
        }
        if ($year !== 'all') {
            $years->push((int) $year);
        }
        $query = DB::table('tb_ATA')->whereNull('deleted_at')->whereIn('ID', $ids);
        if ($filters['report_fund'] !== '') {
            $query->whereRaw('TRIM(FUNDO) = ?', [$filters['report_fund']]);
        }
        foreach (['number' => 'ATA', 'process' => 'PROCESSO', 'supplier' => 'FORNECEDOR', 'bidding' => 'LICITACAO', 'bidding_number' => 'N_LICIT', 'object' => 'OBJETO DA ATA'] as $name => $column) {
            if ($filters['report_'.$name] !== '') {
                $query->whereLike($column, '%'.$filters['report_'.$name].'%');
            }
        }
        $selected = (clone $query)->pluck('ID')->all();
        $figures = [];
        $total = '0.0000';
        foreach (DB::table('tb_PRODUTOS_ATA')->whereIn('ID_ATA', $selected)->whereNull('deleted_at')->orderBy('ID')->get(['ID_ATA', 'VL_TOTAL']) as $item) {
            $value = DecimalAmount::normalize((string) $item->VL_TOTAL);
            $figures[$item->ID_ATA] = bcadd($figures[$item->ID_ATA] ?? '0.0000', $value, 4);
            $total = bcadd($total, $value, 4);
        }
        $query->orderByDesc('DATA_ATA')->orderByDesc('ID');
        $columns = ['ID', 'ATA', 'PROCESSO', 'LICITACAO', 'N_LICIT', 'FORNECEDOR', 'OBJETO DA ATA', 'INICIO_ATA', 'FINAL_ATA'];
        $project = fn ($row) => ['id' => $row->ID, 'number' => $row->ATA, 'process' => $row->PROCESSO, 'bidding' => trim($row->LICITACAO.' '.$row->N_LICIT),
            'supplier' => $row->FORNECEDOR, 'object' => $row->{'OBJETO DA ATA'}, 'starts' => $row->INICIO_ATA, 'ends' => $ends[$row->ID],
            'amount' => $figures[$row->ID] ?? '0.0000',
            'amendment' => $amendments[$identity->key($row->ATA).'|'.trim($row->PROCESSO)] ?? null];
        $rows = $paginate ? $query->paginate(20, $columns, 'page', (int) ($input['page'] ?? 1))->appends(array_map(fn ($v) => $v ?? '', $filters))->through($project)
            : $query->get($columns)->map($project);

        $options = ['funds' => DB::table('contract_funds')->whereNull('deleted_at')->whereRaw("TRIM(name) <> ''")
            ->selectRaw('TRIM(name) as name')->distinct()->orderBy('name')->pluck('name')];
        foreach (['biddings' => 'LICITACAO', 'biddingNumbers' => 'N_LICIT'] as $name => $column) {
            $options[$name] = DB::table('tb_ATA')->whereNull('deleted_at')->whereNotNull($column)->whereRaw('TRIM('.$column.") <> ''")
                ->selectRaw('TRIM('.$column.') as name')->distinct()->orderBy('name')->pluck('name');
        }

        return [...$options, 'rows' => $rows, 'filters' => $filters, 'years' => $years->unique()->sortDesc()->values(),
            'currentYear' => $currentYear, 'count' => count($selected), 'total' => $total];
    }
}
