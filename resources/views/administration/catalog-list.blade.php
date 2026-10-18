<x-layouts.app :title="$title">
    <div class="page-heading"><div><span class="section-label">Cadastros</span><h1>{{ $title }}</h1></div></div>
    <section class="surface contract-section">
        <div class="table-scroll"><table class="data-table">
            <thead><tr>@foreach($columns as $label)<th>{{ $label }}</th>@endforeach</tr></thead>
            <tbody>@forelse($records as $record)<tr>@foreach($columns as $column => $label)<td>{{ $record->$column ?: 'Não informado' }}</td>@endforeach</tr>
            @empty<tr><td colspan="{{ count($columns) }}" class="empty-state">Nenhum registro cadastrado.</td></tr>@endforelse</tbody>
        </table></div>
        {{ $records->links() }}
    </section>
</x-layouts.app>
