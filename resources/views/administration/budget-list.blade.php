        <div class="table-scroll"><table class="data-table"><thead><tr><th>ID</th><th>Ficha</th><th>Dotação</th><th>Gestão</th><th>Fonte</th><th>Descrição</th></tr></thead><tbody>
            @forelse($records as $item)<tr @if($record?->ID_Dotacao === $item->ID_Dotacao) class="budget-selected" @endif><td>{{ $item->ID_Dotacao }}</td><td><a class="text-link" href="{{ route('catalogs.budgets.create', array_merge(request()->only('filter_fund_id', 'filter_department_id'), ['edit' => $item->ID_Dotacao, 'q' => request('q')])) }}">{{ $item->Ficha }}</a></td><td>{{ $item->Dotacao }}</td><td>{{ $item->Gestao }}</td><td>{{ $item->Fonte }}</td><td>{{ $item->Descricao }}</td></tr>
            @empty<tr><td colspan="6" class="empty-state">Nenhuma dotação encontrada.</td></tr>@endforelse
        </tbody></table></div>
        {{ $records->links() }}
