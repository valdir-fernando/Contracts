@props(['contract', 'amendment' => null])
<dialog class="catalog-modal service-orders-dialog" data-service-orders-dialog data-url="{{ route('contracts.service-orders.index', [$contract, 'scope' => 'current-fund-year']) }}" data-amendment="{{ $amendment?->id }}" aria-labelledby="service-orders-title">
    <div class="modal-header">
        <div><h2 id="service-orders-title">Ordens de Serviço</h2></div>
        <button type="button" class="modal-close" data-service-orders-close aria-label="Fechar Ordens de Serviço">×</button>
    </div>
    <dl class="contract-fields service-order-context">
        <div><dt>Nº Contrato</dt><dd>{{ $contract->numero }}</dd></div>
        <div><dt>Fundo</dt><dd>{{ $contract->fundo_nome_snapshot ?: 'Não informado' }}</dd></div>
        <div><dt>Fornecedor</dt><dd>{{ $contract->fornecedor_nome_snapshot ?: 'Não informado' }}</dd></div>
        <div class="service-order-object"><dt>Objeto</dt><dd>{{ $contract->objeto ?: 'Não informado' }}</dd></div>
    </dl>
    <form data-service-order-form action="{{ route('contracts.service-orders.store', $contract) }}" method="POST">
        @csrf
        <div class="field-grid service-order-fields">
            <div class="field"><label for="service-order-number">Nº OS <x-required-mark/></label><input id="service-order-number" name="number" required maxlength="64"></div>
            <div class="field"><label for="service-order-date">Data OS <x-required-mark/></label><input id="service-order-date" name="issued_on" type="date" required></div>
            <div class="field"><label for="service-order-start">Início <x-required-mark/></label><input id="service-order-start" name="starts_on" type="date" required></div>
            <div class="field"><label for="service-order-end">Final <x-required-mark/></label><input id="service-order-end" name="ends_on" type="date" required></div>
            <div class="field"><label for="service-order-deadline">Período <x-required-mark/></label><input id="service-order-deadline" name="deadline" required maxlength="255" placeholder="Ex.: 90 dias"></div>
            <div class="field"><label for="service-order-amendment">Vínculo</label><select id="service-order-amendment" name="amendment_id"><option value="">Contrato original</option></select></div>
            <div class="field field-wide"><label for="service-order-notes">Observações</label><textarea id="service-order-notes" name="notes" maxlength="2000" rows="2"></textarea></div>
        </div>
        <p data-service-order-status role="status" aria-live="polite"></p>
        <div class="form-actions action-toolbar"><button type="submit" class="section-tab" data-service-order-save disabled><x-nav-icon name="save"/>Salvar</button><a class="secondary-button" data-service-order-pdf hidden target="_blank" rel="noopener" aria-label="Gerar PDF da OS salva" title="Gerar PDF da OS salva"><x-nav-icon name="document-extract"/>PDF da OS</a></div>
    </form>
    <h3>Ordens do Fundo selecionado — {{ now()->year }}</h3>
    <div class="service-orders-table-scroll" tabindex="0" role="region" aria-label="Ordens cadastradas">
        <table class="data-table"><thead><tr><th>Nº OS</th><th>Contrato</th><th>Data OS</th><th>Início</th><th>Final</th><th>Período</th><th>Vínculo</th><th>Observações</th><th>PDF</th></tr></thead><tbody data-service-order-rows></tbody></table>
    </div>
    <div class="form-actions action-toolbar"><button type="button" class="section-tab" data-service-orders-previous disabled>Anterior</button><span data-service-orders-page></span><button type="button" class="section-tab" data-service-orders-next disabled>Próxima</button></div>
</dialog>
