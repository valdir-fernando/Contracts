@if(($fields['presentation']['products_layout'] ?? 'continuous') === 'parallel')
    @forelse($fields['products'] as $product)
        <table class="products parallel-item{{ $loop->first ? ' parallel-first' : '' }}">
            <thead><tr>
                <th style="width: 7%">Item</th><th style="width: 10%">Código</th><th style="width: 10%">UND</th>
                @if($fields['special_quantity_name'] !== '')
                    <th style="width: 11%">{{ $fields['special_quantity_name'] }}</th>
                @endif
                <th style="width: 12%">QTD</th><th>Marca</th>
                <th style="width: 15%">VL. Unitário</th><th style="width: 16%">VL. Total</th>
            </tr></thead>
            <tbody>
                <tr class="item-values">
                    <td class="center">{{ $product['item'] }}</td><td class="center">{{ $product['code'] }}</td><td class="center">{{ $product['unit'] }}</td>
                    @if($fields['special_quantity_name'] !== '')
                        <td class="center">{{ $product['special_quantity'] }}</td>
                    @endif
                    <td class="center">{{ $product['quantity'] }}</td><td class="center">{{ $product['brand'] }}</td>
                    <td class="number">{{ $product['unit_price'] }}</td><td class="number">{{ $product['total'] }}</td>
                </tr>
                <tr><td class="description" colspan="{{ $fields['special_quantity_name'] !== '' ? 8 : 7 }}">{{ $product['description'] }}</td></tr>
            </tbody>
        </table>
    @empty
        <p class="spaced">Nenhum produto registrado.</p>
    @endforelse
@else
<table class="products">
    <thead>
        <tr>
            <th style="width: 6%">Item</th><th style="width: 8%">Código</th><th>Produto</th><th style="width: 6%">UND</th>
            @if($fields['special_quantity_name'] !== '')
                <th style="width: 11%">{{ $fields['special_quantity_name'] }}</th>
            @endif
            <th style="width: 10%">QTD</th><th style="width: 10%">Marca</th><th style="width: 13%">VL. Unitário</th><th style="width: 13%">VL. Total</th>
        </tr>
    </thead>
    <tbody>
        @forelse($fields['products'] as $product)
            <tr>
                <td class="center">{{ $product['item'] }}</td><td class="center">{{ $product['code'] }}</td><td>{{ $product['description'] }}</td><td class="center">{{ $product['unit'] }}</td>
                @if($fields['special_quantity_name'] !== '')
                    <td class="center">{{ $product['special_quantity'] }}</td>
                @endif
                <td class="center">{{ $product['quantity'] }}</td><td class="center">{{ $product['brand'] }}</td>
                <td class="number">{{ $product['unit_price'] }}</td><td class="number">{{ $product['total'] }}</td>
            </tr>
        @empty
            <tr><td colspan="{{ $fields['special_quantity_name'] !== '' ? 9 : 8 }}">Nenhum produto registrado.</td></tr>
        @endforelse
    </tbody>
</table>
@endif
