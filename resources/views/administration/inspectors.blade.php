<x-layouts.app title="Fiscal de Contratos">
    <div data-contract-editor class="inspector-page" x-data="{}">
    <div class="page-heading"><div><span class="section-label">Cadastros</span><h1>Fiscal de Contratos</h1></div></div>
    <form data-inspector-form data-inspector-lookup-url="{{ route('inspectors.lookup') }}" data-inspector-original-cpf="{{ $record ? \App\Services\Legacy\Normalizer::document($values['CPF_Fiscal'] ?? '') : '' }}" @inspector-cpf-invalid="step = 0; $nextTick(() => $el.querySelector('[data-inspector-cpf]').focus())" method="POST" novalidate action="{{ $record ? route('inspectors.update', $record->id) : route('inspectors.store') }}" class="inspector-editor" x-data="{ fund: @js((string) old('fund_id', $values['fund_id'] ?? '')), municipal: @js($municipalFundIds), step: {{ $errors->any() ? 2 : 0 }} }">
        @csrf
        @if($record) @method('PUT')<input type="hidden" name="revision" value="{{ old('revision', $revision) }}"> @endif
        <nav class="section-tabs contract-section-navigation" aria-label="Seções do fiscal">
            <button type="button" class="section-tab" :class="{ active: step === 0 }" :aria-pressed="step === 0" @click="step = 0">1. Dados do fiscal</button>
            <button type="button" class="section-tab" :class="{ active: step === 1 }" :aria-pressed="step === 1" @click="step = 1">2. Vínculo e decreto</button>
            <button type="button" class="section-tab" :class="{ active: step === 2 }" :aria-pressed="step === 2" @click="step = 2">Expandir Tudo</button>
        </nav>
        @foreach(['Dados do fiscal' => ['CPF_Fiscal', 'RG_Fiscal', 'Data_Nasc', 'Nome_Fiscal', 'Sexo', 'Celular', 'WhatsApp', 'Situacao', 'Endereco'], 'Vínculo e decreto' => ['Decreto', 'Data_Decreto']] as $group => $keys)
            <section class="surface contract-section contract-editor-section" x-show="step === {{ $loop->index }} || step === 2">
                <h2>{{ $group }}</h2>
                <div class="form-grid">
                    @foreach($keys as $key)
                        @php([$label, $type] = $fields[$key])
                        <div @class(['field', 'field-wide' => in_array($key, ['Nome_Fiscal', 'Endereco'])])>
                            <label for="{{ $key }}">{{ $label }}@if($key === 'Nome_Fiscal')<x-required-mark :explain="true"/>@endif</label>
                            @if($key === 'Situacao')
                                <select id="{{ $key }}" name="{{ $key }}">@foreach(['Ativo', 'Inativo'] as $option)<option value="{{ $option }}" @selected(old($key, $values[$key] ?? 'Ativo') === $option)>{{ $option }}</option>@endforeach</select>
                            @elseif($type === 'select')
                                <select id="{{ $key }}" name="{{ $key }}"><option value="">Selecione</option>@foreach(['Feminino', 'Masculino', 'Não informado'] as $option)<option @selected(old($key, $values[$key] ?? '') === $option)>{{ $option }}</option>@endforeach
                                    @if(filled($values[$key] ?? null) && !in_array($values[$key], ['Feminino', 'Masculino', 'Não informado']))<option value="{{ $values[$key] }}" @selected(old($key, $values[$key]) === $values[$key])>{{ $values[$key] }} (histórico)</option>@endif
                                </select>
                            @elseif($key === 'Endereco')
                                <textarea id="{{ $key }}" name="{{ $key }}" data-inspector-address rows="1" maxlength="2000">{{ old($key, $values[$key] ?? '') }}</textarea>
                            @else
                                <input id="{{ $key }}" name="{{ $key }}" type="{{ $type }}" value="{{ old($key, $values[$key] ?? '') }}" @required($key === 'Nome_Fiscal') @if($key === 'CPF_Fiscal') data-inspector-cpf aria-describedby="CPF_Fiscal-error" inputmode="numeric" maxlength="14" @elseif($type === 'tel') data-inspector-phone inputmode="numeric" maxlength="15" @elseif($type !== 'date') maxlength="{{ $key === 'Nome_Fiscal' ? 255 : ($key === 'Decreto' ? 100 : 32) }}" @endif @if($errors->has($key)) aria-invalid="true" @if($key !== 'CPF_Fiscal') aria-describedby="{{ $key }}-error" @endif @endif>
                            @endif
                            @if(isset($legacyDates[$key]))<p class="hint">Valor legado: {{ $legacyDates[$key] }}. Preservado enquanto nenhuma data válida for informada.</p>@endif
                            @if($key === 'CPF_Fiscal')
                                <p class="field-error" id="CPF_Fiscal-error" data-inspector-cpf-error aria-live="polite" @if(!$errors->has($key)) hidden @endif>{{ $errors->first($key) }}</p>
                                <p class="hint" data-inspector-lookup-status role="status" aria-live="polite" hidden></p>
                            @else
                                @error($key)<p class="field-error" id="{{ $key }}-error">{{ $message }}</p>@enderror
                            @endif
                        </div>
                    @endforeach
                    @if($loop->last)
                        <div class="inspector-org-row">
                        <div class="field"><label for="inspector-fund">Fundo<x-required-mark :explain="true"/></label><select id="inspector-fund" name="fund_id" required x-model="fund"><option value="">Selecione o Fundo</option>@foreach($funds as $fund)<option value="{{ $fund->id }}" @selected((string) old('fund_id', $values['fund_id'] ?? '') === (string) $fund->id)>{{ $fund->name }}</option>@endforeach</select>@error('fund_id')<p class="field-error">{{ $message }}</p>@enderror</div>
                        <div class="field" x-show="municipal.includes(fund)"><label for="inspector-department">Secretaria / Setor</label><select id="inspector-department" name="department_id" :disabled="!municipal.includes(fund)"><option value="">Sem secretaria</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected((string) old('department_id', $values['department_id'] ?? '') === (string) $department->id)>{{ $department->name }}</option>@endforeach</select><p class="hint">Disponível para o Município de Niquelândia.</p>@error('department_id')<p class="field-error">{{ $message }}</p>@enderror</div>
                        </div>
                    @endif
                </div>
            </section>
        @endforeach
        <div class="form-actions sticky-actions contract-action-toolbar action-toolbar">
            @if(auth()->user()->isAdmin())
            @if($record)
                <button type="submit" form="inspector-decree-direct" class="document-action registration-decree" aria-label="Gerar Decreto dos Fiscais de Contratos"><x-nav-icon name="document-extract"/><span>Decreto</span><span class="document-action-tooltip">Emitir o decreto com todos os fiscais vinculados ao mesmo número e data.</span></button>
            @else
                <a class="document-action registration-decree" href="{{ route('inspectors.decree.create') }}" aria-label="Gerar Decreto dos Fiscais de Contratos"><x-nav-icon name="document-extract"/><span>Decreto</span></a>
            @endif
            @endif
            <button type="button" class="section-tab" @disabled(!$record) @if($record) @click="$refs.inspectorDelete.showModal()" @endif><x-nav-icon name="trash"/>Excluir</button>
            <a class="section-tab" href="{{ route('catalogs.list', 'inspector') }}"><x-nav-icon name="plus"/>Novo</a>
            <button type="submit" class="section-tab"><x-nav-icon name="save"/>Salvar</button>
        </div>
    </form>
    @if($record)
        <form id="inspector-decree-direct" data-inspector-decree method="POST" action="{{ route('inspectors.decree.store') }}" novalidate>
            @csrf
            <input type="hidden" name="inspector" value="{{ $record->id }}">
            <input type="hidden" name="inspector_revision" value="{{ $revision }}">
        </form>
        <dialog class="catalog-modal" x-ref="inspectorDelete" aria-labelledby="inspector-delete-title">
            <h2 id="inspector-delete-title">Excluir fiscal</h2>
            <p>Confirma a exclusão de <strong>{{ $record->name }}</strong>? O histórico dos contratos será preservado.</p>
            <form method="POST" action="{{ route('inspectors.destroy', $record->id) }}">
                @csrf @method('DELETE')
                <input type="hidden" name="revision" value="{{ $revision }}">
                <label class="checkbox-label"><input type="checkbox" name="confirmation" value="1" required> Confirmo a exclusão deste fiscal.</label>
                <div class="form-actions"><button class="secondary-button" type="button" @click="$refs.inspectorDelete.close()">Cancelar</button><button class="danger-button" type="submit">Excluir fiscal</button></div>
            </form>
        </dialog>
    @endif
    <section class="surface contract-section inspector-registers"><h2>Fiscais cadastrados</h2>
        <div class="table-scroll"><table class="data-table"><thead><tr><th>Nome do fiscal</th><th>Fundo</th><th>Secretaria / Setor</th><th>Ações</th></tr></thead><tbody>
            @forelse($records as $item)<tr><td>{{ $item->name }}</td><td>{{ $item->fund_name_key }}</td><td>{{ \App\Infrastructure\Managers\ManagerSignatory::isMunicipal($item->fund_name_key) ? ($item->department_name_key ?: 'Sem secretaria') : 'Não se aplica' }}</td><td><a class="text-link" href="{{ route('catalogs.list', ['kind' => 'inspector', 'edit' => $item->id, 'page' => $records->currentPage()]) }}">Editar<span class="sr-only"> {{ $item->name }}</span></a></td></tr>
            @empty<tr><td colspan="4" class="empty-state">Nenhum fiscal cadastrado.</td></tr>@endforelse
        </tbody></table></div>{{ $records->links() }}
    </section>
    </div>
</x-layouts.app>
