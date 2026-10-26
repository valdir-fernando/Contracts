<?php

namespace App\Livewire;

use App\Infrastructure\Accounts\UpdateExpirationAlertDays;
use App\Infrastructure\Security\SelectedFund;
use App\Models\Contract;
use App\Services\ExpirationCalendar;
use App\Services\OverviewMetrics;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Overview extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $year = '';

    public $alertDaysInput = '';

    public function mount(): void
    {
        $this->alertDaysInput = (string) auth()->user()->expiration_alert_days;
        if ($this->year === '') {
            $this->year = (string) today()->year;
        }
        $this->validate($this->yearRules($this->year));
    }

    public function updatingYear(string $year): void
    {
        Validator::make(['year' => $year], $this->yearRules($year))->validate();
        $this->resetPage();
    }

    private function yearRules(string $year): array
    {
        return ['year' => $year === 'all' ? 'required|in:all' : 'required|integer|min:1|max:'.today()->year];
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function saveAlertDays(UpdateExpirationAlertDays $preferences): void
    {
        $preferences->save(auth()->user(), $this->alertDaysInput);
        auth()->user()->refresh();
        $this->resetValidation('alertDaysInput');
        $this->dispatch('alert-days-saved');
    }

    public function render()
    {
        Gate::authorize('viewAny', Contract::class);
        $currentYear = today()->year;
        $this->validate(['search' => 'string|max:255']);
        $user = auth()->user();
        $fund = app(SelectedFund::class)->current($user, session('fund_id') === null ? null : (int) session('fund_id'));
        // No selected/authorized fund means no portfolio, including unresolved rows.
        $fundId = $fund?->id ?? -1;
        $visible = Contract::visibleTo($user)->where('fund_id', $fundId);
        $years = (clone $visible)->whereNotNull('exercicio')->where('exercicio', '<=', $currentYear)
            ->distinct()->orderByDesc('exercicio')->pluck('exercicio')->push($currentYear);
        $year = $this->year === 'all' ? null : (int) $this->year;
        if ($year !== null) {
            $years->push($year);
            $visible->where('exercicio', $year);
        }
        $query = clone $visible;
        if (trim($this->search) !== '') {
            $search = '%'.trim($this->search).'%';
            $query->where(fn ($q) => $q->whereLike('Contrato', $search)->orWhereLike('processo', $search)->orWhereLike('fornecedor_nome_snapshot', $search));
        }

        $alerts = app(ExpirationCalendar::class)->query($user, today(), today()->addDays($user->expiration_alert_days))->where('fund_id', $fundId);
        if ($year !== null) {
            $alerts->where('exercicio', $year);
        }

        return view('livewire.overview', ['metrics' => app(OverviewMetrics::class)->for($user, $year, $fundId), 'count' => (clone $visible)->count(),
            'hasSelectedFund' => $fund !== null,
            'years' => $years->unique()->sortDesc()->values(), 'currentYear' => $currentYear,
            'yearFilters' => ($year === null ? [] : ['year' => $year]) + ['fund' => $fundId],
            'alertDays' => $user->expiration_alert_days,
            'alertsEnabled' => $user->expiration_alert_enabled,
            'alertCount' => $user->expiration_alert_enabled ? (clone $alerts)->count() : 0,
            'alerts' => $user->expiration_alert_enabled ? $alerts->get(['id', 'Contrato', 'vigencia_fim_atual', 'fornecedor_nome_snapshot']) : collect(),
            'expired' => (clone $visible)->withReportValidity('vencido')->count(),
            'review' => (clone $visible)->withReportValidity('indeterminado')->count(),
            'recent' => $query->latest('id')->paginate(10)]);
    }
}
