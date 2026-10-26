<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\User;
use App\Services\Reports\ContractReport;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Gate;

class OverviewMetrics
{
    public function for(User $actor, ?int $year = null, ?int $fundId = null): array
    {
        Gate::forUser($actor)->authorize('viewAny', Contract::class);
        $visible = Contract::visibleTo($actor);
        if ($fundId !== null) {
            $visible->where('fund_id', $fundId);
        }
        if ($year !== null) {
            $visible->where('exercicio', $year);
        }
        $validities = [];
        foreach (Contract::REPORT_VALIDITY as $key => $label) {
            $validities[] = ['key' => $key, 'label' => $label, 'count' => (clone $visible)->withReportValidity($key)->count()];
        }
        $months = [];
        for ($index = 0; $index < 6; $index++) {
            $start = today()->startOfMonth()->addMonths($index);
            $end = $start->copy()->endOfMonth();
            $query = app(ExpirationCalendar::class)->query($actor, $start, $end);
            if ($fundId !== null) {
                $query->where('fund_id', $fundId);
            }
            if ($year !== null) {
                $query->where('exercicio', $year);
            }
            $months[] = ['label' => $start->format('m/Y'), 'count' => $query->count()];
        }
        $summary = app(ContractReport::class)->summary($actor, ['report' => 'fund']
            + ($year === null ? [] : ['year' => $year]) + ($fundId === null ? [] : ['fund' => $fundId]));
        $portfolioTotal = BigDecimal::of('0.0000');
        $missingValue = 0;
        foreach ((clone $visible)->select(['valor_acumulado', 'valor_total'])->cursor() as $contract) {
            $value = $contract->valor_acumulado;
            $missingValue += $value === null ? 1 : 0;
            $portfolioTotal = $portfolioTotal->plus($value ?? '0');
        }

        return ['validities' => $validities, 'months' => $months, 'summary' => $summary,
            'portfolioValue' => ['total' => (string) $portfolioTotal, 'missing' => $missingValue],
            'funds' => collect($summary['groups'])->sortByDesc('count')->take(10)->values()->all()];
    }
}
