<dialog id="dfd-class-dialog" class="catalog-modal dfd-class-dialog" data-dfd-class-dialog data-classes-url="{{ route('dfd-compra.classes') }}" data-create-url="{{ route('dfd-compra.index') }}" aria-labelledby="dfd-class-title">
    <div class="modal-header"><h2 id="dfd-class-title">DFD - Documento de Formalização de Demanda</h2><button type="button" class="modal-close" data-dfd-class-close aria-label="Fechar DFD"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18"/></svg></button></div>
    <div class="field"><label for="dfd-class-filter">Pesquisar classe / categoria</label><input id="dfd-class-filter" type="search" data-dfd-class-filter autocomplete="off" data-enter-native placeholder="Digite para pesquisar…"></div>
    <output class="dfd-class-selected" data-dfd-class-selected aria-live="polite">Selecione uma classe / categoria</output>
    <div class="dfd-class-heading">Classe / Categoria</div>
    <div class="dfd-class-list" data-dfd-class-list role="group" aria-label="Classes de produtos"></div>
    <p class="hint" data-dfd-class-status role="status" aria-live="polite"></p>
    <div class="dfd-class-actions"><button type="button" class="secondary-button" data-dfd-class-retry hidden>Tentar novamente</button></div>
</dialog>
