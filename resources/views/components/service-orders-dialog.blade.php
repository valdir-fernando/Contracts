@props(['contract', 'amendment' => null])
<dialog class="catalog-modal service-orders-dialog" data-service-orders-dialog data-url="{{ route('contracts.service-orders.index', $contract) }}" data-amendment="{{ $amendment?->id }}" aria-labelledby="service-orders-title">
    <div class="modal-header">
        <div><span class="section-label">Contrato {{ $contract->numero }}</span><h2 id="service-orders-title">Ordens de Serviço</h2></div>
        <button type="button" class="modal-close" data-service-orders-close aria-label="Fechar Ordens de Serviço">×</button>
    </div>
    <p>Cadastre ordens do contrato ou de seus aditivos. Cada ordem mantém seu próprio prazo.</p>
    <form data-service-order-form action="{{ route('contracts.service-orders.store', $contract) }}" method="POST">
        @csrf
        <div class="field-grid">
            <div class="field"><label for="service-order-number">Número <x-required-mark/></label><input id="service-order-number" name="number" required maxlength="64"></div>
            <div class="field"><label for="service-order-date">Data da ordem <x-required-mark/></label><input id="service-order-date" name="issued_on" type="date" required></div>
            <div class="field"><label for="service-order-deadline">Prazo <x-required-mark/></label><input id="service-order-deadline" name="deadline" required maxlength="255" placeholder="Ex.: 90 dias"></div>
            <div class="field"><label for="service-order-amendment">Vínculo</label><select id="service-order-amendment" name="amendment_id"><option value="">Contrato original</option></select></div>
            <div class="field field-wide"><label for="service-order-notes">Observações</label><textarea id="service-order-notes" name="notes" maxlength="2000" rows="2"></textarea></div>
        </div>
        <p data-service-order-status role="status" aria-live="polite"></p>
        <div class="form-actions action-toolbar"><button type="submit" class="section-tab" data-service-order-save disabled><x-nav-icon name="save"/>Salvar</button></div>
    </form>
    <h3>Ordens cadastradas</h3>
    <div class="service-orders-table-scroll" tabindex="0" role="region" aria-label="Ordens cadastradas">
        <table class="data-table"><thead><tr><th>Número</th><th>Data</th><th>Prazo</th><th>Vínculo</th><th>Observações</th></tr></thead><tbody data-service-order-rows></tbody></table>
    </div>
    <div class="form-actions action-toolbar"><button type="button" class="section-tab" data-service-orders-previous disabled>Anterior</button><span data-service-orders-page></span><button type="button" class="section-tab" data-service-orders-next disabled>Próxima</button></div>
</dialog>
