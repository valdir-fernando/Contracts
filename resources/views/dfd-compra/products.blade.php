<section class="surface contract-section"><h2>Produtos da DFD</h2>
    <form method="GET" action="{{ route('dfd-compra.edit', $record->ID) }}" class="dfd-product-search">
        <input type="hidden" name="tab" value="products"><div class="field"><label for="dfd-catalog-query">Pesquisar no catálogo desta classe</label><input id="dfd-catalog-query" name="q" value="{{ request('q') }}" maxlength="100" placeholder="Descrição ou código do produto"></div><button type="submit" class="secondary-button"><x-nav-icon name="search"/>Pesquisar</button>
    </form>
    <div class="table-scroll"><table class="data-table"><thead><tr><th>Código</th><th>Descrição</th><th>Unidade</th><th>Ação</th></tr></thead><tbody>
        @forelse($catalog as $product)<tr><td>{{ $product->ID_Prodata }}</td><td>{{ $product->Descricao }}</td><td>{{ $product->UND }}</td><td><a class="text-link" href="{{ route('dfd-compra.edit', [$record->ID, 'tab' => 'products', 'catalog_product' => $product->ID, 'q' => request('q')]) }}">Selecionar<span class="sr-only"> {{ $product->Descricao }}</span></a></td></tr>
        @empty<tr><td colspan="4" class="empty-state">Nenhum produto encontrado nesta classe.</td></tr>@endforelse
    </tbody></table></div>{{ $catalog->links() }}
</section>
@if($selected)
    <section class="surface contract-section"><h2>{{ $item ? 'Editar produto' : 'Incluir produto' }}</h2>
        <form method="POST" action="{{ $item ? route('dfd-compra.products.update', [$record->ID, $item->ID]) : route('dfd-compra.products.store', $record->ID) }}">
            @csrf @if($item) @method('PUT') @endif
            <input type="hidden" name="version" value="{{ $record->version }}"><input type="hidden" name="product_id" value="{{ $selected->ID }}">
            <div class="form-grid">
                <div class="field field-wide"><label for="dfd-product-description">Produto</label><input id="dfd-product-description" value="{{ $selected->Descricao }}" readonly></div>
                <div class="field"><label for="dfd-product-unit">Unidade</label><input id="dfd-product-unit" value="{{ $selected->UND }}" readonly></div>
                <div class="field"><label for="dfd-product-quantity">Quantidade<x-required-mark/></label><input id="dfd-product-quantity" name="quantity" inputmode="decimal" value="{{ old('product_id') == $selected->ID ? old('quantity', $item?->QTD ?? '') : ($item?->QTD ?? '') }}" maxlength="40" required>@error('quantity')<p class="field-error">{{ $message }}</p>@enderror</div>
                <div class="field field-wide"><label for="dfd-product-characteristics">Características / Especificações complementares</label><textarea id="dfd-product-characteristics" name="characteristics" rows="3" maxlength="15000">{{ old('product_id') == $selected->ID ? old('characteristics', $item?->CARACT ?? '') : ($item?->CARACT ?? '') }}</textarea></div>
            </div>
            <div class="form-actions action-toolbar"><a class="section-tab" href="{{ route('dfd-compra.edit', [$record->ID, 'tab' => 'products']) }}">Cancelar</a><button class="section-tab" type="submit"><x-nav-icon name="save"/>{{ $item ? 'Salvar produto' : 'Incluir produto' }}</button></div>
        </form>
    </section>
@elseif($item)
    <p class="notice">O produto deste item não está mais ativo no catálogo. Seu histórico foi preservado.</p>
@endif
<section class="surface contract-section"><h2>Itens incluídos</h2><div class="table-scroll"><table class="data-table"><thead><tr><th>Item</th><th>Código</th><th>Produto / Características</th><th>Unidade</th><th>Quantidade</th><th>Ações</th></tr></thead><tbody>
    @forelse($items as $line)<tr><td>{{ $line->ITEM }}</td><td>{{ $line->COD_PROD }}</td><td>{{ $line->PROD_SIMPLES }}@if($line->CARACT)<p>{{ $line->CARACT }}</p>@endif</td><td>{{ $line->UND }}</td><td>{{ $line->QTD }}</td><td><div class="dfd-line-actions"><a class="text-link" href="{{ route('dfd-compra.edit', [$record->ID, 'tab' => 'products', 'item' => $line->ID]) }}">Editar</a><button type="button" class="text-link" @click="$refs.dfdItemDelete{{ $line->ID }}.showModal()">Excluir</button></div></td></tr>
    @empty<tr><td colspan="6" class="empty-state">Nenhum produto incluído nesta DFD.</td></tr>@endforelse
</tbody></table></div></section>
@foreach($items as $line)
    <dialog class="catalog-modal" x-ref="dfdItemDelete{{ $line->ID }}" aria-labelledby="dfd-item-delete-{{ $line->ID }}"><div class="modal-header"><h2 id="dfd-item-delete-{{ $line->ID }}">Excluir item {{ $line->ITEM }}</h2><button type="button" class="modal-close" @click="$refs.dfdItemDelete{{ $line->ID }}.close()" aria-label="Fechar">×</button></div><p>{{ $line->PROD_SIMPLES }}</p><form method="POST" action="{{ route('dfd-compra.products.destroy', [$record->ID, $line->ID]) }}">@csrf @method('DELETE')<input type="hidden" name="version" value="{{ $record->version }}"><label class="checkbox-label"><input type="checkbox" name="confirmation" value="1" required> Confirmo a exclusão deste produto.</label><div class="form-actions"><button type="button" class="secondary-button" @click="$refs.dfdItemDelete{{ $line->ID }}.close()">Cancelar</button><button type="submit" class="danger-button">Excluir produto</button></div></form></dialog>
@endforeach
