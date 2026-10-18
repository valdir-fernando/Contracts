<x-layouts.app title="Decreto de Fiscais">
    <div class="page-heading"><div><span class="section-label">Fiscal_Contrato</span><h1>Decreto de Fiscais</h1></div></div>
    <form data-inspector-decree method="POST" action="{{ route('inspectors.decree.store') }}" novalidate>
        @csrf
        <input type="hidden" name="revision" value="{{ $revision }}">
        <input type="hidden" name="expires" value="{{ $expires }}">
        <section class="surface contract-section">
            <h2>Emitir decreto cadastrado</h2>
            <p>Selecione um fiscal. O sistema incluirá automaticamente todos os fiscais ativos com o mesmo número e data de decreto e escolherá o modelo adequado.</p>
            <p>Emitido pelo Município de Niquelândia e assinado pelo Prefeito, inclusive para os demais fundos.</p>
            <div class="field"><label for="decree-inspector">Fiscal / Decreto</label>
                <select name="inspector_id" id="decree-inspector" required>
                    <option value="">Selecione</option>
                    @foreach($inspectors as $inspector)
                        <option value="{{ $inspector['id'] }}" @selected((string) old('inspector_id', $preset['id'] ?? '') === (string) $inspector['id'])>{{ $inspector['name'] }} — {{ $inspector['unit'] }} — {{ $inspector['number'] ?: 'Sem decreto' }}{{ $inspector['date'] ? ' — '.\Carbon\CarbonImmutable::parse($inspector['date'])->format('d/m/Y') : '' }}</option>
                    @endforeach
                </select>
            </div>
        </section>
        <div class="form-actions sticky-actions contract-action-toolbar action-toolbar"><a class="section-tab" href="{{ route('catalogs.inspectors') }}">Voltar</a><button class="section-tab" type="submit"><x-nav-icon name="document-extract"/>Gerar PDF</button></div>
    </form>
</x-layouts.app>
