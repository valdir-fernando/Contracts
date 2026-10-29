<x-layouts.app title="Contratos excluídos">
    <div class="page-heading"><div><h1>Contratos excluídos</h1><p>Histórico preservado. A restauração exige confirmação e justificativa.</p></div></div>
    <section class="surface">
        <form class="filter-bar" method="GET" action="{{ route('contracts.deleted') }}">
            <div><label for="q">Buscar</label><input id="q" name="q" value="{{ request('q') }}" maxlength="255" placeholder="Número, processo, fornecedor ou documento"></div>
            <div class="filter-actions"><button class="primary-button compact" type="submit">Filtrar</button><a class="text-link" href="{{ route('contracts.deleted') }}">Limpar filtros</a></div>
        </form>
        <div class="table-scroll"><table class="data-table"><thead><tr><th>Contrato / processo</th><th>Fornecedor</th><th>Fundo / secretaria</th><th>Exclusão</th><th>Restaurar</th></tr></thead><tbody>
            @forelse($contracts as $contract)
                <tr><td><strong>{{ $contract->numero ?: 'Sem número' }}</strong><small>Processo: {{ $contract->processo ?: 'Não informado' }}</small></td>
                    <td class="scope-cell">{{ $contract->fornecedor_nome_snapshot ?: 'Não informado' }}</td>
                    <td class="scope-cell"><strong>{{ $contract->fundo_nome_snapshot ?: 'Não informado' }}</strong><small>{{ $contract->secretaria_nome_snapshot ?: 'Secretaria não informada' }}</small></td>
                    <td>{{ $contract->deleted_at?->format('d/m/Y') }}</td>
                    <td><form method="POST" action="{{ route('contracts.restore', $contract) }}">@csrf<input type="hidden" name="version" value="{{ $contract->version }}"><label><span class="field-caption">Confirme {{ $contract->numero ?: $contract->id }} <x-required-mark/></span><input name="confirmation" required></label><label><span class="field-caption">Motivo <x-required-mark/></span><input name="reason" minlength="5" required></label><button class="secondary-button">Restaurar</button></form></td></tr>
            @empty<tr><td colspan="5" class="empty-state">Nenhum contrato excluído encontrado.</td></tr>@endforelse
        </tbody></table></div>
        <div class="table-footer"><span>{{ $contracts->total() }} {{ $contracts->total() === 1 ? 'contrato' : 'contratos' }}</span>{{ $contracts->links() }}</div>
    </section>
</x-layouts.app>
