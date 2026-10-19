<x-layouts.app title="Secretaria">
    <div class="page-heading catalogs-page-heading"><div><h1>Secretarias</h1><p>Secretarias dos seus órgãos autorizados.</p></div>@if($canCreate)<button class="primary-button" type="button" data-modal-open="catalog-department"><x-nav-icon name="plus"/>Nova secretaria</button>@endif</div>
    <section class="surface" id="departments"><div class="table-scroll"><table class="data-table"><thead><tr><th>Secretaria</th></tr></thead><tbody>
        @forelse($departments as $department)<tr><td><a class="text-link" href="{{ route('catalogs.show', ['department', $department->id]) }}">{{ $department->name }}</a></td></tr>@empty<tr><td class="empty-state">Nenhuma secretaria disponível para seus vínculos.</td></tr>@endforelse
    </tbody></table></div></section>
    @if($canCreate)<x-catalog-modal kind="department"/>@endif
</x-layouts.app>
