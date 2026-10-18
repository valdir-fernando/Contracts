<x-layouts.app title="Cadastro de Fundo">
    <div data-contract-editor class="fund-page" x-data="{}">
        <div class="page-heading"><div><span class="section-label">Cadastros</span><h1>Cadastro de Fundo</h1></div></div>
        <form method="POST" data-fund-form data-cnpj-lookup-url="{{ route('cnpj.lookup') }}" data-cnpj-review="{{ request('consultar_cnpj') === '1' && $record ? 'true' : 'false' }}" data-fund-lookup-url="{{ route('funds.lookup') }}" data-fund-original-cnpj="{{ $record ? \App\Services\Legacy\Normalizer::document($record->document) : '' }}" novalidate action="{{ $record ? route('funds.update', $record->id) : route('funds.store') }}">
            @csrf
            @if($record) @method('PUT')<input type="hidden" name="revision" value="{{ old('revision', $revision) }}"> @endif
            <section class="surface contract-section">
                <h2>Dados do Fundo</h2>
                <div class="fund-identity-row">
                    <div class="field">
                        <div class="field-label-row"><label for="fund-document">CNPJ do Fundo<x-required-mark/></label><a class="text-link" href="https://www.gov.br/pt-br/servicos/consultar-cadastro-nacional-de-pessoas-juridicas" target="_blank" rel="noopener noreferrer">Dados Online</a></div>
                        <input id="fund-document" name="document" data-fund-cnpj type="text" inputmode="numeric" maxlength="18" required value="{{ old('document', $values['document'] ?? '') }}" aria-describedby="fund-document-error" @if($errors->has('document')) aria-invalid="true" @endif>
                        <p id="fund-document-error" class="field-error" data-fund-cnpj-error aria-live="polite" @if(!$errors->has('document')) hidden @endif>{{ $errors->first('document') }}</p>
                        <p class="hint" data-fund-lookup-status role="status" aria-live="polite" hidden></p>
                    </div>
                    <div class="field"><label for="fund-name">Nome do Fundo<x-required-mark :explain="true"/></label><input id="fund-name" name="name" maxlength="255" required @readonly($record && ($record->legacy_id !== null || !$canDelete)) value="{{ old('name', $values['name'] ?? '') }}" @if($errors->has('name')) aria-invalid="true" aria-describedby="fund-name-error" @endif>@error('name')<p class="field-error" id="fund-name-error">{{ $message }}</p>@enderror</div>
                </div>
                <div class="field fund-address"><label for="fund-address">Endereço completo</label><textarea rows="1" name="End_Fundo" id="fund-address" maxlength="2000" data-fund-address>{{ old('End_Fundo', $values['End_Fundo'] ?? '') }}</textarea>@error('End_Fundo')<p class="field-error">{{ $message }}</p>@enderror</div>
                <div class="fund-contact-row">
                    @foreach($details as $key => [$label, $max])
                        @continue($key === 'End_Fundo')
                        <div class="field"><label for="fund-{{ $key }}">{{ $label }}</label><input id="fund-{{ $key }}" name="{{ $key }}" type="{{ $key === 'Telefone' ? 'tel' : 'text' }}" maxlength="{{ $key === 'Telefone' ? 15 : $max }}" @if($key === 'Telefone') data-fund-phone inputmode="numeric" @endif value="{{ old($key, $values[$key] ?? '') }}" @if($errors->has($key)) aria-invalid="true" aria-describedby="fund-{{ $key }}-error" @endif>@error($key)<p class="field-error" id="fund-{{ $key }}-error">{{ $message }}</p>@enderror</div>
                    @endforeach
                </div>
            </section>
            <div class="form-actions sticky-actions contract-action-toolbar action-toolbar">
                <button class="section-tab" type="button" @disabled(!$canDelete) @if($canDelete) @click="$refs.fundDelete.showModal()" @elseif($record) title="Fundo com vínculos existentes: exclusão indisponível." @endif><x-nav-icon name="trash"/>Excluir</button>
                <a class="section-tab" href="{{ route('funds.index') }}"><x-nav-icon name="plus"/>Novo</a>
                <button class="section-tab" type="submit"><x-nav-icon name="save"/>Salvar</button>
            </div>
        </form>
        @if($canDelete)
            <dialog class="catalog-modal" x-ref="fundDelete" aria-labelledby="fund-delete-title"><h2 id="fund-delete-title">Excluir Fundo</h2><p>Confirma a exclusão de <strong>{{ $record->name }}</strong>? O registro será preservado no histórico.</p>
                <form method="POST" action="{{ route('funds.destroy', $record->id) }}">@csrf @method('DELETE')<input type="hidden" name="revision" value="{{ $revision }}"><label class="checkbox-label"><input name="confirmation" type="checkbox" value="1" required> Confirmo a exclusão deste Fundo.</label><div class="form-actions"><button class="secondary-button" type="button" @click="$refs.fundDelete.close()">Cancelar</button><button class="danger-button" type="submit">Excluir Fundo</button></div></form>
            </dialog>
        @endif
        <section class="surface contract-section fund-registers"><h2>Fundos cadastrados</h2>
            <div class="table-scroll"><table class="data-table"><thead><tr><th>CNPJ</th><th>Fundo</th><th>Endereço</th><th>Abreviatura</th><th>Código de gestão</th><th>Telefone</th><th>Contato</th><th>Ações</th></tr></thead><tbody>
                @forelse($records as $item)<tr data-row-href="{{ route('funds.index', ['edit' => $item->id, 'page' => $records->currentPage()]) }}"><td>{{ $item->document ?: 'Não informado' }}</td><td><a class="text-link" href="{{ route('funds.index', ['edit' => $item->id]) }}">{{ $item->name }}</a></td><td>{{ $item->End_Fundo ?? 'Não informado' }}</td><td>{{ $item->Abreviatuda ?? 'Não informado' }}</td><td>{{ $item->Codigo ?? 'Não informado' }}</td><td>{{ $item->Telefone ?? 'Não informado' }}</td><td>{{ $item->Nome ?? 'Não informado' }}</td><td><a class="text-link" href="{{ route('funds.index', ['edit' => $item->id]) }}">Editar</a></td></tr>
                @empty<tr><td colspan="8" class="empty-state">Nenhum Fundo cadastrado.</td></tr>@endforelse
            </tbody></table></div>{{ $records->links() }}
        </section>
    </div>
</x-layouts.app>
