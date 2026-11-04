<x-layouts.budget-panel title="Contratos do Fiscal">
    <div class="inspector-contracts-list">
        <form method="GET" class="inspector-contracts-filters" x-data="{}">
            <fieldset>
                <legend class="sr-only">Vigência dos contratos</legend>
                <label class="checkbox-label"><input type="radio" name="status" value="vencido" @checked($status === 'vencido') @change="$el.form.requestSubmit()"> Vencidos</label>
                <label class="checkbox-label"><input type="radio" name="status" value="vigente" @checked($status === 'vigente') @change="$el.form.requestSubmit()"> Vigentes</label>
            </fieldset>
        </form>
        <div class="table-scroll">
            <table class="data-table">
                <thead><tr><th>Contrato</th><th>Tipo_Cont</th><th>Fornecedor</th><th>Fundo &amp; Secretaria</th><th>Objeto &amp; Aditivo</th><th>Final</th><th>Ações</th></tr></thead>
                <tbody>
                    @forelse($contracts as $contract)
                        <tr>
                            <td>{{ $contract['number'] }}</td>
                            <td>{{ $contract['type'] }}</td>
                            <td>{{ $contract['supplier'] }}</td>
                            <td><span>{{ $contract['fund'] }}</span>@if(filled($contract['department']))<span class="inspector-contracts-detail">{{ $contract['department'] }}</span>@endif</td>
                            <td><span>{{ $contract['object'] }}</span>@if($contract['amendment'] !== '')<span class="inspector-contracts-detail">Aditivo: {{ $contract['amendment'] }}</span>@endif</td>
                            <td>{{ $contract['ends'] }}</td>
                            <td><a class="section-tab inspector-contract-load" target="_top" href="{{ route('catalogs.list', ['kind' => 'inspector', 'edit' => $inspector, 'diary_contract' => $contract['id']]) }}" aria-label="Carregar contrato {{ $contract['number'] }}">Carregar</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="empty-state">Nenhum contrato disponível para este fiscal.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $contracts->links() }}
    </div>
</x-layouts.budget-panel>
