<x-layouts.app :title="$title">
    <div class="page-heading"><div><a class="text-link" href="{{ route('administration.index') }}">← Auditoria e conciliação</a><h1>{{ $title }}</h1><p>Registro {{ $recordId }} · Consulta do histórico preservado.</p></div></div>
    <section class="surface contract-section"><dl class="contract-fields">@foreach($fields as $label => $value)<div><dt>{{ $label }}</dt><dd>@if(is_array($value))<pre>{{ json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre>@else{{ $value ?? 'Não informado' }}@endif</dd></div>@endforeach</dl></section>
</x-layouts.app>
