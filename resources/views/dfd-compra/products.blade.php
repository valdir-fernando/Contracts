<dialog id="dfd-products-dialog" class="catalog-modal contract-products-dialog" x-ref="dfdProducts" aria-labelledby="dfd-products-title" @if(request('tab') === 'products') x-init="$el.showModal()" @endif>
    <div class="modal-header">
        <h2 id="dfd-products-title" class="dfd-products-heading">
            <span>Produtos da DFD</span>
            <span class="dfd-products-identification-divider" aria-hidden="true"></span>
            <span>{{ $fund->name }}</span>
        </h2>
        <button type="button" class="modal-close" @click="$refs.dfdProducts.close()" aria-label="Fechar produtos da DFD">×</button>
    </div>
    <div class="dfd-products-workspace">
        <div class="dfd-products-left">
            <div class="dfd-products-identification">
                <strong>{{ $category }}</strong>
                <span class="dfd-products-identification-divider" aria-hidden="true"></span>
                <strong>Nº DFD: {{ $record->N_DFD }}</strong>
            </div>
            <div class="field dfd-products-name">
                <label for="dfd-product-name">Pesquise pelo Nome do Produto</label>
                <input id="dfd-product-name" type="text" autocomplete="off" placeholder="Digite o nome do produto" value="{{ request('q') }}" data-dfd-product-query>
            </div>
            <div class="dfd-products-catalog table-scroll" tabindex="0" aria-label="Produtos da classe {{ $category }}">
                <table class="data-table">
                    <colgroup>
                        <col class="dfd-product-select-column">
                        <col class="dfd-product-code-column">
                        <col>
                        <col class="dfd-product-unit-column">
                        <col class="dfd-product-quantity-column">
                    </colgroup>
                    <thead><tr><th><span class="sr-only">Selecionar</span></th><th>Código</th><th>Produto</th><th>Unidade</th><th>QTD</th></tr></thead>
                    <tbody>
                        @forelse($catalog as $product)
                            <tr data-dfd-product-row data-product-name="{{ $product->Descricao }}"><td><input type="checkbox" name="selected_products[]" value="{{ $product->ID }}" aria-label="Selecionar {{ $product->Descricao }}"></td><td>{{ $product->ID_Prodata }}</td><td x-data="{ complement: '', draft: '' }">
    <div class="dfd-product-description">
        <button type="button" class="text-link dfd-product-description-edit" @click="draft = complement; $refs.descriptionDialog.showModal()" aria-label="Complementar descrição de {{ $product->Descricao }}" aria-haspopup="dialog"><x-nav-icon name="edit"/></button>
        <div>{{ $product->Descricao }}<span class="dfd-product-complement" x-text="complement" x-show="complement" x-cloak></span></div>
    </div>
    <input type="hidden" name="characteristics" form="dfd-launch-{{ $product->ID }}" :value="complement" data-dfd-product-characteristics>
    <dialog class="catalog-modal dfd-product-description-dialog" x-ref="descriptionDialog" aria-labelledby="dfd-description-title-{{ $product->ID }}">
        <div class="modal-header">
            <h2 id="dfd-description-title-{{ $product->ID }}">Complementar descrição</h2>
            <button type="button" class="modal-close" @click="$refs.descriptionDialog.close()" aria-label="Fechar complemento">×</button>
        </div>
        <p class="dfd-product-original-name">{{ $product->Descricao }}</p>
        <div class="field">
            <label for="dfd-description-{{ $product->ID }}">Complemento da descrição</label>
            <textarea id="dfd-description-{{ $product->ID }}" x-model="draft" rows="4" maxlength="15000" placeholder="Digite as especificações complementares"></textarea>
        </div>
        <div class="form-actions">
            <button type="button" class="secondary-button" @click="$refs.descriptionDialog.close()">Cancelar</button>
            <button type="button" class="primary-button" @click="complement = draft.trim(); $refs.descriptionDialog.close()">Aplicar complemento</button>
        </div>
    </dialog>
</td><td>{{ $product->UND }}</td><td><form id="dfd-launch-{{ $product->ID }}" class="dfd-product-launch" method="POST" action="{{ route('dfd-compra.products.store', $record->ID) }}" data-dfd-product-launch>@csrf<input type="hidden" name="version" value="{{ $record->version }}"><input type="hidden" name="product_id" value="{{ $product->ID }}"><input name="quantity" type="text" class="dfd-product-quantity" inputmode="decimal" maxlength="24" placeholder="0,000" disabled data-dfd-product-quantity aria-label="Quantidade de {{ $product->Descricao }}"><button type="submit" class="text-link dfd-product-launch-button" disabled data-dfd-product-launch-button aria-label="Lançar {{ $product->Descricao }}" title="Lançar produto"><x-nav-icon name="save"/></button></form></td></tr>
                        @empty
                            <tr><td colspan="5" class="empty-state">Nenhum produto encontrado nesta classe.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p data-dfd-launch-status role="status" hidden></p><p class="dfd-products-selection-total" data-dfd-product-total hidden role="status"></p>
            <p data-dfd-product-empty hidden role="status">Nenhum produto corresponde à pesquisa.</p>
        </div>
        <div class="dfd-products-divider" role="separator" aria-orientation="vertical"></div>
        <section class="dfd-products-right" aria-labelledby="dfd-linked-products-title">
            <h3 id="dfd-linked-products-title">Itens vinculados à DFD</h3>
            <div class="dfd-products-linked table-scroll" tabindex="0" aria-label="Itens vinculados à DFD">
                <table class="data-table">
                    <thead><tr><th>Item</th><th>Código</th><th>Produto</th><th>Unidade</th><th>QTD</th></tr></thead>
                    <tbody>
                        @forelse($items as $line)
                            <tr>
                                <td>{{ $line->ITEM }}</td>
                                <td>{{ $line->COD_PROD }}</td>
                                <td>{{ $line->PROD_SIMPLES }}@if($line->CARACT)<p>{{ $line->CARACT }}</p>@endif</td>
                                <td>{{ $line->UND }}</td>
                                <td>{{ str_replace('.', ',', (string) $line->QTD) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="empty-state">Nenhum item vinculado a esta DFD.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</dialog>
