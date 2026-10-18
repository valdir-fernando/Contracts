<x-layouts.app :title="$kind === 'fund' ? 'Dados do fundo' : 'Dados do órgão'">
    <div class="page-heading"><div><a class="text-link" href="{{ route('catalogs.index') }}">← Órgãos e permissões</a><h1>{{ $record->name }}</h1><p>{{ $kind === 'fund' ? 'Fundo cadastrado' : 'Órgão ou secretaria cadastrada' }}</p></div></div>
    <section class="surface contract-section"><h2>Dados cadastrais</h2><dl class="contract-fields"><div><dt>Nome</dt><dd>{{ $record->name }}</dd></div>@if($kind === 'fund')<div><dt>CPF/CNPJ</dt><dd>{{ $record->document ?: 'Não informado' }}</dd></div>@endif</dl></section>
    @if($kind === 'fund')<div class="form-actions"><a class="secondary-button" href="{{ route('funds.index', ['edit' => $record->id]) }}">Editar Fundo</a></div>@endif
    <x-related-contracts :contracts="$contracts"/>
</x-layouts.app>
