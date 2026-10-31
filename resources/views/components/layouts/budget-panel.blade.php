<x-layouts.auth :title="$title">
    <main class="budget-panel-content">
        @if(session('status'))<dialog class="catalog-modal product-item-warning" role="alertdialog" aria-labelledby="budget-status-title" data-modal-auto-open><div class="modal-header"><h2 id="budget-status-title">Mensagem</h2></div><p>{{ session('status') }}</p><div class="form-actions action-toolbar"><button type="button" class="section-tab" data-modal-close autofocus>OK</button></div></dialog>@endif
        {{ $slot }}
    </main>
</x-layouts.auth>
