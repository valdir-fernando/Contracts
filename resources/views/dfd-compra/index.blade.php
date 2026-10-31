<x-layouts.app title="DFD Compra">
    @php
        $value = fn ($key, $default = '') => $canRestore ? old($key, $values[$key] ?? $default) : ($values[$key] ?? $default);
        $editing = !$record || ($canRestore && $errors->any());
        $budgetId = $canRestore && old('budget_id') !== null ? old('budget_id') : $budgets->firstWhere('Ficha', $values['Ficha'] ?? '')?->ID_Dotacao;
        $initialTab = 'description';
        if ($canRestore && $errors->any()) {
            $errorField = $errors->keys()[0] ?? '';
            $initialTab = match (true) {
                in_array($errorField, ['Data_Conclusao', 'Prioridade', 'Vigencia', 'Local', 'End_Local', 'Vinculacao']) => 'information',
                in_array($errorField, ['department_id', 'Responsavel', 'Decreto', 'Cargo', 'email']) => 'requester',
                $errorField === 'budget_id' => 'budget',
                default => 'description',
            };
        }
    @endphp
    <div class="page-heading dfd-page-heading"><div><h1>DFD - Documento de Formalização de Demanda</h1></div>
        @if($record)<a class="section-tab dfd-pdf-button" href="{{ route('dfd-compra.pdf', $record->ID) }}" target="_blank" rel="noopener" aria-label="Gerar PDF da DFD" title="Gerar PDF da DFD" data-dfd-pdf><x-nav-icon name="printer"/></a>
        @else<button type="button" class="section-tab dfd-pdf-button" disabled aria-label="Gerar PDF da DFD" title="Salve a DFD antes de gerar o PDF" data-dfd-pdf><x-nav-icon name="printer"/></button>@endif
    </div>
    <div class="dfd-editor" data-contract-editor data-dfd-editor x-data="{ editing: @js($editing), activeTab: @js($initialTab), budgetChanged: @js($canRestore && old('budget_id') !== null) }">
        <section class="surface contract-section dfd-context" aria-label="Identificação da DFD">
            <div class="form-grid dfd-identification">
                <div class="field"><label for="dfd-number">Nº DFD</label><div class="dfd-number-control"><input id="dfd-number" value="{{ $value('N_DFD') }}" maxlength="100" autocomplete="off" data-dfd-number-query><button type="button" class="section-tab" data-dfd-search-open aria-label="Pesquisar DFD" aria-haspopup="dialog" aria-controls="dfd-search-dialog"><x-nav-icon name="search"/></button></div></div>
                <div class="field"><label for="Data_DFD">Data da DFD<x-required-mark/></label><input id="Data_DFD" name="Data_DFD" type="date" form="dfd-general-form" value="{{ $value('Data_DFD') }}" required :disabled="!editing"></div>
                <div class="field"><label for="Processo_DFD">DFD vinculada ao Processo</label><div class="dfd-process-control"><input id="Processo_DFD" name="Processo_DFD" form="dfd-general-form" value="{{ $value('Processo_DFD') }}" readonly>
                </div></div>
                <div class="field"><label for="dfd-quotation">Cotação</label><input id="dfd-quotation" type="text" maxlength="5" readonly aria-label="Cotação"></div>
                <div class="field dfd-category"><label for="dfd-selected-class">Classe / Categoria</label><input id="dfd-selected-class" type="text" value="{{ $category }}" readonly data-dfd-class-panel placeholder="Selecione uma classe no menu DFD Compra"></div>
            </div>
        </section>
        <nav class="section-tabs contract-section-navigation dfd-tabs" aria-label="Seções da DFD">
            @foreach(['description' => 'Justificativa e descrição', 'information' => 'Informações gerais e vinculação', 'requester' => 'Área requisitante e responsáveis', 'budget' => 'Dotação orçamentária'] as $tab => $label)
                <button type="button" class="section-tab" :class="{ active: activeTab === '{{ $tab }}' }" :aria-current="activeTab === '{{ $tab }}' ? 'page' : null" @click="activeTab = '{{ $tab }}'" aria-controls="dfd-panel-{{ $tab }}">{{ $label }}</button>
            @endforeach
            @if($record)
                <button type="button" class="section-tab" @click="$refs.dfdProducts.showModal()" aria-haspopup="dialog" aria-controls="dfd-products-dialog">Produtos</button>
            @else
                <button type="button" class="section-tab" disabled title="Salve a DFD para acessar os produtos">Produtos</button>
            @endif
        </nav>
        <div class="editor-panels">
            @include('dfd-compra.general')
        </div>
        @if($record)
            @include('dfd-compra.products')
        @endif
        @if($record)
            <dialog class="catalog-modal" x-ref="dfdDelete" aria-labelledby="dfd-delete-title"><div class="modal-header"><h2 id="dfd-delete-title">Excluir DFD {{ $record->N_DFD }}</h2><button type="button" class="modal-close" @click="$refs.dfdDelete.close()" aria-label="Fechar">×</button></div><p>Confirme a exclusão desta DFD. O histórico será preservado.</p>
                <form method="POST" action="{{ route('dfd-compra.destroy', $record->ID) }}">@csrf @method('DELETE')<input type="hidden" name="version" value="{{ $record->version }}"><label class="checkbox-label"><input type="checkbox" name="confirmation" value="1" required> Confirmo a exclusão desta DFD.</label><div class="form-actions"><button type="button" class="secondary-button" @click="$refs.dfdDelete.close()">Cancelar</button><button type="submit" class="danger-button">Excluir DFD</button></div></form>
            </dialog>
        @endif
        <dialog id="dfd-search-dialog" class="catalog-modal" aria-labelledby="dfd-search-title" data-dfd-search-dialog data-search-url="{{ route('dfd-compra.search') }}"><div class="modal-header"><h2 id="dfd-search-title">Pesquisar DFD</h2><button type="button" class="modal-close" data-dfd-search-close aria-label="Fechar pesquisa">×</button></div><form data-dfd-search-form><div class="field"><label for="dfd-search-number">Nº DFD</label><input id="dfd-search-number" name="q" maxlength="100" placeholder="Informe o número da DFD"></div><div class="form-actions action-toolbar"><button type="submit" class="section-tab"><x-nav-icon name="search"/>Pesquisar</button></div></form><p role="status" data-dfd-search-status></p><div class="table-scroll"><table class="data-table"><thead><tr><th>Nº DFD</th><th>Data</th><th>Processo</th><th>Classe / Categoria</th></tr></thead><tbody data-dfd-search-results></tbody></table></div></dialog>
    </div>
</x-layouts.app>
