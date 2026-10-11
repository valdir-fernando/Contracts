<?php

namespace App\Livewire;

use App\Models\Contract;
use App\Services\ExpirationCalendar;
use App\Services\OverviewMetrics;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Overview extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        Gate::authorize('viewAny', Contract::class);
        $this->validate(['search' => 'string|max:255']);
        $visible = Contract::visibleTo(auth()->user());
        $query = clone $visible;
        if (trim($this->search) !== '') {
            $search = '%'.trim($this->search).'%';
            $query->where(fn ($q) => $q->whereLike('numero', $search)->orWhereLike('processo', $search)->orWhereLike('fornecedor_nome_snapshot', $search));
        }

        $user = auth()->user();
        $alerts = app(ExpirationCalendar::class)->query($user, today(), today()->addDays($user->expiration_alert_days));

        return view('livewire.overview', ['metrics' => app(OverviewMetrics::class)->for($user), 'count' => (clone $visible)->count(),
            'alertDays' => $user->expiration_alert_days,
            'alertsEnabled' => $user->expiration_alert_enabled,
            'alertCount' => $user->expiration_alert_enabled ? (clone $alerts)->count() : 0,
            'alerts' => $user->expiration_alert_enabled ? $alerts->limit(5)->get() : collect(),
            'expired' => (clone $visible)->withValidity('vencido')->count(),
            'review' => (clone $visible)->where(fn ($q) => $q->where('vigencia_em_revisao', true)->orWhere('ownership_resolved', false))->count(),
            'recent' => $query->latest('id')->paginate(10)]);
    }
}
