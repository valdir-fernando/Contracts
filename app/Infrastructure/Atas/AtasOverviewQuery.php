<?php

namespace App\Infrastructure\Atas;

use App\Domain\Atas\AtaValidity;
use App\Domain\Shared\DecimalAmount;
use App\Infrastructure\Security\LockActor;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/** Global ata portfolio, explicitly independent of the selected fund and its participants. */
final class AtasOverviewQuery
{
    public const STATUSES = ['vigente' => 'Vigente', 'vencido' => 'Vencida', 'a_iniciar' => 'A iniciar', 'indeterminado' => 'Sem vigência definida'];

    public function __construct(private LockActor $actors) {}

    public function for(User $expected, ?int $year, string $search = '', int $page = 1): array
    {
        $actor = $this->actors->current($expected);
        $today = today();
        $rows = DB::table('tb_ATA')->whereNull('deleted_at')->orderByDesc('ID')
            ->get(['ID', 'ATA', 'DATA_ATA', 'PROCESSO', 'LICITACAO', 'N_LICIT', 'FORNECEDOR', 'INICIO_ATA', 'FINAL_ATA']);
        $years = collect([$today->year]);
        $selected = [];
        foreach ($rows as $row) {
            $numberYear = $this->year($row->ATA);
            if ($numberYear !== null && $numberYear <= $today->year) {
                $years->push($numberYear);
            }
            if ($year === null || $numberYear === $year) {
                $selected[$row->ID] = $row;
            }
        }
        if ($year !== null) {
            $years->push($year);
        }
        $figures = [];
        $total = '0.0000';
        $itemCount = 0;
        foreach (DB::table('tb_PRODUTOS_ATA')->whereIn('ID_ATA', array_keys($selected))->whereNull('deleted_at')->where('ADITIVO', 0)->whereNull('ID_ADITIVO')
            ->orderBy('ID')->select(['ID_ATA', 'VL_TOTAL'])->cursor() as $item) {
            $value = DecimalAmount::normalize((string) $item->VL_TOTAL);
            $figures[$item->ID_ATA] ??= ['items' => 0, 'value' => '0.0000'];
            $figures[$item->ID_ATA]['items']++;
            $figures[$item->ID_ATA]['value'] = bcadd($figures[$item->ID_ATA]['value'], $value, 4);
            $total = bcadd($total, $value, 4);
            $itemCount++;
        }
        $statuses = array_fill_keys(array_keys(self::STATUSES), 0);
        $months = [];
        for ($index = 0; $index < 6; $index++) {
            $label = $today->copy()->startOfMonth()->addMonths($index)->format('m/Y');
            $months[$label] = ['label' => $label, 'count' => 0];
        }
        $alerts = [];
        $projected = [];
        $procurements = [];
        $alertEnd = $today->copy()->addDays($actor->expiration_alert_days)->format('Y-m-d');
        foreach ($selected as $id => $row) {
            $start = $this->date($row->INICIO_ATA);
            $end = $this->date($row->FINAL_ATA);
            $status = AtaValidity::classify($start, $end, $today);
            $statuses[$status]++;
            $procurements[$row->PROCESSO.'|'.$row->LICITACAO.'|'.$row->N_LICIT] = true;
            $projected[$id] = [
                'id' => $id, 'number' => $row->ATA, 'date' => $this->date($row->DATA_ATA)?->format('d/m/Y'),
                'supplier' => $row->FORNECEDOR, 'process' => $row->PROCESSO, 'procurement' => trim($row->LICITACAO.' '.$row->N_LICIT),
                'start' => $start?->format('d/m/Y'), 'end' => $end?->format('d/m/Y'),
                'status' => $status, 'statusLabel' => self::STATUSES[$status],
                'items' => $figures[$id]['items'] ?? 0, 'value' => $figures[$id]['value'] ?? '0.0000',
            ];
            if (in_array($status, ['vigente', 'a_iniciar'], true)) {
                $month = $end->format('m/Y');
                if (isset($months[$month])) {
                    $months[$month]['count']++;
                }
                if ($actor->expiration_alert_enabled && $end->format('Y-m-d') <= $alertEnd) {
                    $alerts[] = ['number' => $row->ATA, 'supplier' => $row->FORNECEDOR,
                        'date' => $end->format('d/m/Y'), 'isoDate' => $end->format('Y-m-d'), 'day' => $end->format('d'), 'month' => $end->format('m')];
                }
            }
        }
        usort($alerts, fn ($a, $b) => [$a['isoDate'], $a['number']] <=> [$b['isoDate'], $b['number']]);
        $listing = DB::table('tb_ATA')->whereNull('deleted_at')->whereIn('ID', array_keys($selected));
        if (trim($search) !== '') {
            $term = '%'.trim($search).'%';
            $listing->where(fn ($query) => $query->whereLike('ATA', $term)->orWhereLike('FORNECEDOR', $term)
                ->orWhereLike('PROCESSO', $term)->orWhereLike('N_LICIT', $term));
        }
        $atas = $listing->orderByDesc('ID')->paginate(10, ['ID'], 'page', max(1, $page))->through(fn ($row) => $projected[$row->ID]);

        return ['count' => count($selected), 'statuses' => $statuses, 'statusLabels' => self::STATUSES,
            'years' => $years->unique()->sortDesc()->values(), 'currentYear' => $today->year,
            'months' => array_values($months), 'total' => $total, 'itemCount' => $itemCount, 'procurementCount' => count($procurements),
            'alerts' => $alerts, 'alertDays' => $actor->expiration_alert_days, 'alertsEnabled' => $actor->expiration_alert_enabled, 'atas' => $atas];
    }

    private function year(string $number): ?int
    {
        if (! preg_match('/^\s*\d+\s*\/\s*(\d{2}|\d{4})\s*$/D', $number, $match)) {
            return null;
        }
        $year = strlen($match[1]) === 2 ? 2000 + (int) $match[1] : (int) $match[1];

        return $year >= 1000 ? $year : null;
    }

    private function date(?string $text): ?DateTimeImmutable
    {
        if ($text === null) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text);

        return $date && $date->format('Y-m-d') === $text ? $date : null;
    }
}
