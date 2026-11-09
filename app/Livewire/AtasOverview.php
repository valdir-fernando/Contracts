<?php

namespace App\Livewire;

use App\Infrastructure\Atas\AtasOverviewQuery;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class AtasOverview extends Component
{
    use WithPagination;

    #[Url]
    public string $year = '';

    #[Url]
    public string $search = '';

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
        $this->resetPage();
    }

    public function updatingSearch(string $search): void
    {
        Validator::make(['search' => $search], ['search' => 'string|max:255'])->validate();
        $this->resetPage();
    }

    private function yearRules(string $year): array
    {
        return ['year' => $year === 'all' ? 'required|in:all' : 'required|integer|min:1|max:'.today()->year];
    }

    public function render()
    {
        // Livewire must still receive a view after a failed property update.
        $validator = Validator::make(['year' => $this->year, 'search' => $this->search],
            $this->yearRules($this->year) + ['search' => 'string|max:255']);
        $errors = $validator->errors();
        if ($validator->fails()) {
            $this->setErrorBag($errors);
        }
        $year = $errors->has('year') ? today()->year : ($this->year === 'all' ? null : (int) $this->year);
        $search = $errors->has('search') ? '' : $this->search;

        return view('livewire.atas-overview', app(AtasOverviewQuery::class)->for(
            auth()->user(), $year, $search, $this->getPage()
        ));
    }
}
