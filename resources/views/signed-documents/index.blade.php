<x-layouts.app title="Publicação">
    <div class="page-heading"><div><span class="section-label">DOCUMENTOS ASSINADOS</span><h1>Publicação</h1><p>Selecione o contrato para enviar ou consultar os PDFs assinados.</p></div></div>
    <section class="surface form-surface">
        <h2>Buscar contrato</h2>
        <form method="GET" action="{{ route('publication.index') }}" class="filter-bar">
            <div class="field"><label for="publication-search">Número ou processo</label><input id="publication-search" name="q" value="{{ request('q') }}" maxlength="100" placeholder="Ex.: 001/26"></div>
            <button class="primary-button compact" type="submit"><x-nav-icon name="search"/>Buscar</button>
        </form>
        <div class="table-scroll"><table><thead><tr><th>Contrato</th><th>Exercício</th><th>Processo</th><th>Ação</th></tr></thead><tbody>
            @forelse($contracts as $item)<tr><td>{{ $item->numero ?: 'Sem número' }}</td><td>{{ $item->exercicio ?: '—' }}</td><td>{{ $item->processo ?: '—' }}</td><td><a class="text-link" href="{{ route('publication.index', ['contract' => $item->id, 'q' => request('q'), 'page' => request('page')]) }}">{{ $selected?->id === $item->id ? 'Selecionado' : 'Selecionar' }}<span class="sr-only"> contrato {{ $item->numero }}</span></a></td></tr>
            @empty<tr><td colspan="4">Nenhum contrato encontrado neste Fundo.</td></tr>@endforelse
        </tbody></table></div>
        {{ $contracts->links() }}
    </section>
    @if($selected)
        <section class="surface form-surface">
            <h2>Enviar PDF — Contrato {{ $selected->numero }}</h2>
            <p>Envie o documento já assinado, em PDF de até 10 MB. A substituição mantém os arquivos anteriores no histórico. O envio não publica o arquivo na internet.</p>
            <form method="POST" action="{{ route('publication.store', $selected->id) }}" enctype="multipart/form-data">
                @csrf<input type="hidden" name="version" value="{{ $selected->version }}">
                <div class="form-grid">
                    <div class="field"><label for="signed-category">Tipo de documento <x-required-mark/></label><select id="signed-category" name="category" required><option value="">Selecione</option>@foreach($categories as $key => $label)<option value="{{ $key }}" @selected(old('category') === $key)>{{ $label }}{{ $selected->getAttribute($key) ? ' — já possui PDF' : '' }}</option>@endforeach</select>@error('category')<p class="field-error">{{ $message }}</p>@enderror</div>
                    <div class="field"><label for="signed-file">Arquivo PDF <x-required-mark/></label><input id="signed-file" type="file" name="file" accept="application/pdf,.pdf" required aria-describedby="signed-file-help"><p id="signed-file-help" class="hint">Máximo de 10 MB. O sistema armazena o arquivo recebido e não verifica a validade da assinatura digital.</p>@error('file')<p class="field-error" role="alert">{{ $message }}</p>@enderror</div>
                </div>
                <p><label><input type="checkbox" name="replace_confirmation" value="1"> Se já existir PDF deste tipo, confirmo a substituição, preservando o anterior no histórico.</label></p>
                @error('replace_confirmation')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                @error('version')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                <button class="primary-button" type="submit"><x-nav-icon name="save"/>Enviar PDF</button>
            </form>
        </section>
        <section class="surface form-surface">
            <h2>Documentos do contrato {{ $selected->numero }}</h2>
            <div class="table-scroll"><table><thead><tr><th>Tipo</th><th>Arquivo</th><th>Enviado em</th><th>Situação</th><th>Ação</th></tr></thead><tbody>
                @forelse($documents as $document)<tr><td>{{ $categories[$document->signed_category] }}</td><td>{{ $document->name }}</td><td>{{ $document->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</td><td>{{ (int) $selected->getAttribute($document->signed_category) === $document->id ? 'Atual' : 'Histórico' }}</td><td><a class="text-link" href="{{ route('publication.download', [$selected->id, $document->id]) }}">Baixar PDF<span class="sr-only"> {{ $document->name }}</span></a></td></tr>
                @empty<tr><td colspan="5">Nenhum PDF enviado para este contrato.</td></tr>@endforelse
            </tbody></table></div>
            {{ $documents->links() }}
        </section>
    @else
        <div class="notice">Selecione um contrato na lista para enviar ou consultar os documentos.</div>
    @endif
</x-layouts.app>
