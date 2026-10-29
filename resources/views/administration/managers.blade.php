<x-layouts.app title="Gestor_Fundo">
    <div data-contract-editor class="inspector-page" x-data="{}">
        <div class="page-heading"><div><span class="section-label">Cadastros</span><h1>Gestor do Fundo</h1></div></div>
        <form data-manager-form @manager-field-invalid="step = 2; $nextTick(() => $event.detail.field.focus())" @manager-cpf-invalid="step = 0; $nextTick(() => $el.querySelector('[name=CPF_Gestor]').focus())" method="POST" action="{{ $record ? route('managers.update', $record->id) : route('managers.store') }}" class="inspector-editor" x-data="{ step: {{ $errors->any() ? 2 : 0 }}, fund: @js((string) old('fund_id', $values['fund_id'] ?? '')), municipal: @js($municipalFundIds), signatureAvailable: @js($signatureAvailable) }">
            @php($managerFeedback = array_merge($errors->all(), session('status') ? [session('status')] : []))
            <script type="application/json" data-manager-feedback>@json($managerFeedback)</script>
            @csrf
            @if($record) @method('PUT')<input type="hidden" name="revision" value="{{ old('revision', $revision) }}"> @endif
            <nav class="section-tabs contract-section-navigation" aria-label="Seções do gestor">
                <button type="button" class="section-tab" :class="{ active: step === 0 }" :aria-pressed="step === 0" @click="step = 0">1. Dados do gestor</button>
                <button type="button" class="section-tab" :class="{ active: step === 1 }" :aria-pressed="step === 1" @click="step = 1">2. Vínculo e decreto</button>
                <button type="button" class="section-tab" :class="{ active: step === 2 }" :aria-pressed="step === 2" @click="step = 2">Expandir Tudo</button>
            </nav>
            <div class="editor-panels">
            @foreach(['Dados do gestor' => ['CPF_Gestor', 'RG_Gestor', 'Data_Nasc', 'Sexo', 'Nome_Gestor', 'Endereco', 'Fone_Celular', 'WhatsApp', 'Email'], 'Vínculo e decreto' => ['Cargo', 'Cargo_Abreviado', 'Decreto', 'Data_Decreto', 'Matricula', 'Assinatura']] as $group => $keys)
                <section class="surface contract-section contract-editor-section" x-show="step === {{ $loop->index }} || step === 2">
                    <h2>{{ $group }}</h2><div class="form-grid">
                        @if($loop->first)
                            <div class="field field-wide"><label for="manager-fund">Fundo<x-required-mark :explain="true"/></label><select id="manager-fund" name="fund_id" required x-model="fund"><option value="">Selecione o Fundo</option>@foreach($funds as $fund)<option value="{{ $fund->id }}" @selected((string) old('fund_id', $values['fund_id'] ?? '') === (string) $fund->id)>{{ $fund->name }}</option>@endforeach</select>@error('fund_id')<p class="field-error">{{ $message }}</p>@enderror</div>
                        @endif
                        @foreach($keys as $key)
                            @php([$label, $type] = $fields[$key])
                            <div @class(['field', 'field-wide' => in_array($key, ['Nome_Gestor', 'Endereco', 'Cargo', 'Assinatura']), 'manager-email' => $key === 'Email'])>
                                @if($type !== 'checkbox')<label for="{{ $key }}">{{ $label }}@if(in_array($key, ['Nome_Gestor', 'CPF_Gestor']))<x-required-mark :explain="true"/>@endif</label>@endif
                                @if($type === 'select')
                                    <select id="{{ $key }}" name="{{ $key }}"><option value="">Selecione</option>@foreach(array_unique(array_filter(['Feminino', 'Masculino', 'Não informado', $values[$key] ?? null])) as $option)<option value="{{ $option }}" @selected(old($key, $values[$key] ?? '') === $option)>{{ $option }}</option>@endforeach</select>
                                @elseif($type === 'checkbox')
                                    <input type="hidden" name="{{ $key }}" value="0" :disabled="!municipal.includes(fund) || !signatureAvailable">
                                    <label class="checkbox-label" for="{{ $key }}"><input id="{{ $key }}" name="{{ $key }}" type="checkbox" value="1" :disabled="!municipal.includes(fund) || !signatureAvailable" @disabled(!$signatureAvailable || !in_array((string) old('fund_id', $values['fund_id'] ?? ''), $municipalFundIds->all(), true)) @checked(old($key, $values[$key] ?? 0) == 1)><span>{{ $label }}</span></label>
                                    <p class="hint">Disponível para o Município de Niquelândia. Se já houver um gestor marcado, desmarque-o antes de ativar outro. Sem gestor marcado, os documentos usam o Prefeito.</p>
                                @elseif($key === 'Endereco')
                                    <textarea id="{{ $key }}" name="{{ $key }}" rows="1" maxlength="2000">{{ old($key, $values[$key] ?? '') }}</textarea>
                                @else
                                    <input id="{{ $key }}" name="{{ $key }}" type="{{ $type }}" value="{{ old($key, $values[$key] ?? '') }}" @required(in_array($key, ['Nome_Gestor', 'CPF_Gestor'])) @if($key === 'CPF_Gestor') aria-describedby="CPF_Gestor-error" maxlength="14" inputmode="numeric" @elseif($type === 'tel') maxlength="15" inputmode="numeric" @elseif($type !== 'date') maxlength="{{ in_array($key, ['RG_Gestor', 'Fone_Celular', 'WhatsApp']) ? 32 : (in_array($key, ['Matricula', 'Decreto']) ? 100 : 255) }}" @endif @if($errors->has($key)) aria-invalid="true" @if($key !== 'CPF_Gestor') aria-describedby="{{ $key }}-error" @endif @endif>
                                @endif
                                @if(isset($legacyDates[$key]))<p class="hint">Valor legado: {{ $legacyDates[$key] }}. Preservado enquanto nenhuma data válida for informada.</p>@endif
                                @if($key === 'CPF_Gestor')
                                    <p class="field-error" id="CPF_Gestor-error" data-manager-cpf-error aria-live="polite" @if(!$errors->has($key)) hidden @endif>{{ $errors->first($key) }}</p>
                                @else
                                    @error($key)<p class="field-error" id="{{ $key }}-error">{{ $message }}</p>@enderror
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>
            @endforeach
            </div>
            <div class="form-actions sticky-actions contract-action-toolbar action-toolbar">
                <div class="manager-decree-guidance">
                    <x-registration-decree label="Decreto dos Gestores do Fundo" tooltip-id="manager-decree-tooltip"/>
                    <p class="hint">Para a emissão de Decreto de Gestor selecione o(s) gestor(es) que sera(ão) incluído(s) no Decreto</p>
                </div>
                <button type="button" class="section-tab" @disabled(!$record) @if($record) @click="$refs.managerDelete.showModal()" @endif><x-nav-icon name="trash"/>Excluir</button>
                <a class="section-tab" href="{{ route('catalogs.list', 'manager-fund') }}"><x-nav-icon name="plus"/>Novo</a>
                <button type="submit" class="section-tab"><x-nav-icon name="save"/>Salvar</button>
            </div>
        </form>
        @if($record)
            <dialog class="catalog-modal" x-ref="managerDelete" aria-labelledby="manager-delete-title">
                <h2 id="manager-delete-title">Excluir gestor</h2><p>Confirma a exclusão de <strong>{{ $record->name }}</strong>? O histórico dos contratos será preservado.</p>
                <form method="POST" action="{{ route('managers.destroy', $record->id) }}">@csrf @method('DELETE')<input type="hidden" name="revision" value="{{ $revision }}">
                    <label class="checkbox-label"><input type="checkbox" name="confirmation" value="1" required> Confirmo a exclusão deste gestor.</label>
                    <div class="form-actions"><button class="secondary-button" type="button" @click="$refs.managerDelete.close()">Cancelar</button><button class="danger-button" type="submit">Excluir gestor</button></div>
                </form>
            </dialog>
        @endif
        <section class="surface contract-section inspector-registers" x-data="{ selectedManagerIds: [] }">
            <h2>Gestores cadastrados</h2>
            <div class="table-scroll"><button type="button" class="text-link manager-selection-clear" @click="selectedManagerIds = []" :disabled="selectedManagerIds.length === 0" disabled>Limpar Selecionados</button><table class="data-table"><thead><tr><th>Nome do gestor</th><th>Fundo</th><th>Decreto</th><th>Data_Decreto</th><th>Ações</th></tr></thead><tbody>
                @forelse($records as $item)<tr><td><label><input type="checkbox" name="manager_ids[]" value="{{ $item->id }}" aria-label="Selecionar gestor {{ $item->name }}" x-model="selectedManagerIds"><span>{{ $item->name }}</span></label></td><td>{{ $item->fund_name_key }}</td><td>{{ $item->decree ?: '—' }}</td><td>{{ $item->decree_date ?: '—' }}</td><td><a class="text-link" href="{{ route('catalogs.list', ['kind' => 'manager-fund', 'edit' => $item->id, 'page' => $records->currentPage()]) }}">Editar<span class="sr-only"> {{ $item->name }}</span></a></td></tr>
                @empty<tr><td colspan="5" class="empty-state">Nenhum gestor cadastrado.</td></tr>@endforelse
            </tbody></table></div>{{ $records->links() }}
        </section>
    </div>
</x-layouts.app>
