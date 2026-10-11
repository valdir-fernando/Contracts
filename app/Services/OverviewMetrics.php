<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\User;
use App\Services\Reports\ContractReport;
use Illuminate\Support\Facades\Gate;

class OverviewMetrics
{
    public function for(User $actor): array
    {
        Gate::forUser($actor)->authorize('viewAny', Contract::class);
        $visible = Contract::visibleTo($actor);
        $validities = [];
        foreach (Contract::VALIDITY as $key => $label) {
            $validities[] = ['key' => $key, 'label' => $label, 'count' => (clone $visible)->withValidity($key)->count()];
        }
        $months = [];
        for ($index = 0; $index < 6; $index++) {
            $start = today()->startOfMonth()->addMonths($index);
            $end = $start->copy()->endOfMonth();
            $months[] = ['label' => $start->format('m/Y'), 'count' => app(ExpirationCalendar::class)->query($actor, $start, $end)->count()];
        }
        $summary = app(ContractReport::class)->summary($actor, ['report' => 'fund']);

        return ['validities' => $validities, 'months' => $months, 'summary' => $summary,
            'funds' => collect($summary['groups'])->sortByDesc('count')->take(10)->values()->all()];
    }
}
