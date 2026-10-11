@if($paginator->hasPages())
    @php
        $livewire = $livewire ?? false;
        $pages = collect([1, $paginator->lastPage(), $paginator->currentPage() - 1, $paginator->currentPage(), $paginator->currentPage() + 1])
            ->filter(fn ($page) => $page >= 1 && $page <= $paginator->lastPage())->unique()->sort()->values();
        $previous = 0;
    @endphp
    <nav class="pagination" aria-label="Paginação">
        <span class="pagination-summary">{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} de {{ number_format($paginator->total(), 0, ',', '.') }}</span>
        <div class="pagination-controls">
            @if($livewire)
                <button type="button" class="pagination-button" wire:click="previousPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" @disabled($paginator->onFirstPage()) aria-label="Página anterior">← <span>Anterior</span></button>
            @elseif($paginator->onFirstPage())
                <span class="pagination-button" aria-disabled="true">← <span>Anterior</span></span>
            @else
                <a class="pagination-button" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Página anterior">← <span>Anterior</span></a>
            @endif
            @foreach($pages as $page)
                @if($previous && $page > $previous + 1)<span class="pagination-gap" aria-hidden="true">…</span>@endif
                @if($page === $paginator->currentPage())
                    <span class="pagination-button pagination-number current" aria-current="page" aria-label="Página {{ $page }}">{{ $page }}</span>
                @elseif($livewire)
                    <button type="button" class="pagination-button pagination-number" wire:key="pagination-{{ $paginator->getPageName() }}-{{ $page }}" wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" aria-label="Ir para a página {{ $page }}">{{ $page }}</button>
                @else
                    <a class="pagination-button pagination-number" href="{{ $paginator->url($page) }}" aria-label="Ir para a página {{ $page }}">{{ $page }}</a>
                @endif
                @php($previous = $page)
            @endforeach
            <span class="pagination-position">{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
            @if($livewire)
                <button type="button" class="pagination-button" wire:click="nextPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" @disabled(!$paginator->hasMorePages()) aria-label="Próxima página"><span>Próxima</span> →</button>
            @elseif($paginator->hasMorePages())
                <a class="pagination-button" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Próxima página"><span>Próxima</span> →</a>
            @else
                <span class="pagination-button" aria-disabled="true"><span>Próxima</span> →</span>
            @endif
        </div>
    </nav>
@endif
