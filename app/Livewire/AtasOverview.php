<?php

namespace App\Livewire;

use App\Infrastructure\Security\LockActor;
use App\Infrastructure\Security\SelectedFund;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Url;
use Livewire\Component;

class AtasOverview extends Component
{
    #[Url]
    public string $year = '';

    public function mount(): void
    {
        if ($this->year === '') {
            $this->year = (string) today()->year;
        }
        $this->validate($this->yearRules($this->year));
    }

    public function updatingYear(string $year): void
    {
        Validator::make(['year' => $year], $this->yearRules($year))->validate();
    }

    private function yearRules(string $year): array
    {
        return ['year' => $year === 'all' ? 'required|in:all' : 'required|integer|min:1|max:'.today()->year];
    }

    public function render()
    {
        $actor = app(LockActor::class)->current(auth()->user());
        $fund = app(SelectedFund::class)->current($actor, session('fund_id') === null ? null : (int) session('fund_id'));
        $months = [];
        for ($index = 0; $index < 6; $index++) {
            $months[] = today()->startOfMonth()->addMonths($index)->format('m/Y');
        }

        return view('livewire.atas-overview', [
            'hasSelectedFund' => $fund !== null,
            'currentYear' => (string) today()->year,
            'years' => collect([(string) today()->year, $this->year])->reject(fn ($year) => $year === 'all')->unique()->values(),
            'months' => $months,
        ]);
    }
}
