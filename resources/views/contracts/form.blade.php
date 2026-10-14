<x-layouts.app :title="$contract->exists ? 'Editar contrato' : 'Novo contrato'" :contract-editor="true">
    <x-slot:contractSummary><x-contract-summary :contract="$contract" :values="$values" :editable="true" :heading="$contract->exists ? 'Editar contrato' : 'Novo contrato'"/></x-slot:contractSummary>
    <x-slot:headerBack><a class="text-link fund-header-back" href="{{ $contract->exists ? route('contracts.show', $contract) : route('contracts.index') }}">← Voltar</a></x-slot:headerBack>
    @if($conflict)<div class="notice notice-error" role="alert">O contrato foi alterado por outra pessoa. Seus valores estão abaixo para consulta; recarregue os dados e revise as diferenças antes de salvar. <a class="text-link" href="{{ route('contracts.edit', $contract) }}">Recarregar contrato</a></div>@endif
    <form data-contract-form data-supplier-original-document="{{ ($contract->exists && ($formActor->isAdmin() || strlen(\App\Services\Legacy\Normalizer::document($contract->fornecedor_documento_snapshot)) === 14)) ? \App\Services\Legacy\Normalizer::document($contract->fornecedor_documento_snapshot) : '' }}" data-supplier-lookup-url="{{ route('suppliers.lookup') }}" x-data="{ step: 0, all: true }" method="POST" novalidate @unless($contract->exists) data-supplier-prefill="{{ json_encode(array_intersect_key($values, array_flip(['fornecedor_documento_snapshot', 'fornecedor_nome_snapshot', 'rg_fornec', 'doc_prof', 'pis_pasep', 'end_fornec', 'socio', 'cpf_socio', 'rg_socio'])), JSON_THROW_ON_ERROR) }}" @endunless action="{{ $contract->exists ? route('contracts.update', $contract) : route('contracts.store') }}">
        @csrf @if($contract->exists)@method('PUT')<input type="hidden" name="version" value="{{ $values['version'] ?? $contract->version }}">@endif
        @unless($contract->exists)<input type="hidden" name="supplier_id" value="{{ $values['supplier_id'] ?? '' }}">@endunless
        @if(!$contract->exists && $errors->has('duplicate_confirmation'))
            <section class="notice notice-error" role="alert">
                <p>{{ $errors->first('duplicate_confirmation') }}</p>
                <label><input type="checkbox" name="duplicate_confirmation" value="1"> Conferi os dados e confirmo o cadastro de outro contrato com esta identificação.</label>
            </section>
        @endif
        @php
            $departmentEnabled = \App\Services\Legacy\Normalizer::name(request()->attributes->get('selected_fund')['name'] ?? null) === 'MUNICIPIO DE NIQUELANDIA';
            $departmentEnabled = $departmentEnabled && (!$contract->exists || (int) $contract->fund_id === (int) (request()->attributes->get('selected_fund')['id'] ?? 0));
        @endphp
        <div class="contract-administration-row">
        <section class="contract-administration-fields" aria-label="Órgão e situação administrativa"><div class="field-grid">
            <div class="field"><label for="department_id">Secretaria</label><select id="department_id" name="department_id" @disabled(!$departmentEnabled)><option value="">Sem secretaria</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(($contract->exists || $departmentEnabled) && (string) old('department_id', $values['department_id'] ?? $contract->department_id) === (string) $department->id)>{{ $department->name }}</option>@endforeach</select>@error('department_id')<p class="field-error">{{ $message }}</p>@enderror</div>
            <div class="field"><label for="tipo">Tipo de contrato<x-required-mark :explain="true" :when="!$contract->exists || filled($contract->tipo)"/></label><input id="tipo" name="tipo" list="contract-types" maxlength="15000" value="{{ old('tipo', $values['tipo'] ?? $contract->tipo) }}" @required(!$contract->exists || filled($contract->tipo)) @if($errors->has('tipo')) aria-invalid="true" aria-describedby="tipo-error" @endif>@error('tipo')<p class="field-error" id="tipo-error">{{ $message }}</p>@enderror</div>
        </div></section>
        <x-contract-document-actions :contract="$contract"/>
        </div>
        <nav aria-label="Seções do contrato" class="section-tabs contract-section-navigation">@foreach($groups as $group => $fields)<button type="button" class="section-tab" :class="{ active: !all && step === {{ $loop->index }} }" :aria-pressed="!all && step === {{ $loop->index }}" @click="step = {{ $loop->index }}; all = false; $nextTick(() => $dispatch('editor-section-change', { step }))">{{ $loop->iteration }}. {{ $group }}</button>@endforeach<button type="button" class="section-tab expand-all-button" :class="{ active: all }" :aria-pressed="all" @click="all = true">Expandir Tudo</button></nav>
        <div class="contract-panels-scroll" data-contract-panels-scroll role="region" aria-label="Painéis do contrato" tabindex="0">
        <datalist id="contract-types">@foreach($typeRules as $typeRule)<option value="{{ $typeRule->name }}"></option>@endforeach</datalist>
        @if($typeRules->isNotEmpty())<details class="surface contract-section detail-disclosure"><summary><h2>Campos específicos por tipo</h2><span>{{ $typeRules->count() }} tipos</span></summary>@foreach($typeRules as $typeRule)<p><strong>{{ $typeRule->name }}:</strong> {{ collect($typeRule->required_fields)->map(fn($field)=>\App\Models\ContractTypeRule::FIELDS[$field])->implode(', ') ?: 'Sem requisitos adicionais' }}</p>@endforeach</details>@endif
    @foreach($groups as $group => $fields)
            <section class="surface contract-section contract-editor-section" data-editor-section="{{ $loop->index }}" x-show="all || step === {{ $loop->index }}"><h2 tabindex="-1">{{ $group }}</h2><div class="form-grid">
                @foreach($fields as $source => $definition)
                    @php
                        $column = $definition['column'];
                        $supplierReadonly = in_array($column, \App\Support\ContractFields::SUPPLIER_CATALOG);
                        $derived = in_array($column, \App\Support\ContractFields::DERIVED);
                        $automatic = in_array($column, \App\Support\ContractFields::GENERATED) && (!$contract->exists || $contract->number_sequence !== null);
                        $protected = $contract->exists && !$formActor->isAdmin() && (in_array($column, \App\Support\ContractFields::PERSONAL) || ($column === 'fornecedor_documento_snapshot' && strlen(\App\Services\Legacy\Normalizer::document($contract->$column)) !== 14));
                        $required = !$contract->exists && in_array($column, ['processo', 'fornecedor_documento_snapshot', 'objeto', 'data_contrato', 'vigencia_inicio', 'vigencia_fim_original', 'tipo', 'valor_total', 'modalidade', 'n_licit']);
                        $required = $required || ($contract->exists && in_array($column, ['modalidade', 'n_licit']) && $contract->$column !== null && $contract->$column !== '');
                        $value = $definition['type'] === 'date' ? $contract->$column?->format('Y-m-d') : $contract->$column;
                        $inputValue = old($column, $values[$column] ?? $value);
                        if ($supplierReadonly) {
                            $inputValue = $contract->exists ? $value : ($values[$column] ?? '');
                        }
                        $multiline = in_array($column, ['objeto', 'escopo', 'tipo_veiculo', 'end_fornec', 'end_fundo', 'end_imovel', 'cargo_credenc', 'local_servico', 'dados_veiculo', 'instalacoes', 'motivo_distrato', 'motivo_paraliz_obra']) || str_contains((string) $inputValue, "\n") || str_contains((string) $inputValue, "\r") || mb_strlen((string) $inputValue) > 180;
                        $rows = match ($column) {
                            'local_servico', 'cargo_credenc', 'instalacoes', 'escopo', 'tipo_veiculo', 'dados_veiculo', 'end_imovel' => 1,
                            'end_fornec', 'end_fundo' => 2,
                            default => 3,
                        };
                    @endphp
                    @if($column === 'iptu')<div class="property-field-pair">@endif
                    <div @class(['field', 'field-iptu' => $column === 'iptu', 'field-wide' => $multiline && !$derived && !$protected && !in_array($column, ['escopo', 'tipo_veiculo', 'iptu', 'end_imovel', 'cargo_credenc', 'local_servico', 'instalacoes'])])>
                        @if(!$contract->exists && $column === 'fornecedor_documento_snapshot')
                            <div class="field-label-row"><label for="{{ $column }}">{{ \App\Support\ContractFields::label($source) }}<x-required-mark :explain="true" :when="$required"/></label><span class="field-label-divider" aria-hidden="true">|</span><a class="text-link" href="{{ route('suppliers.index') }}">Selecionar fornecedor cadastrado</a></div>
                        @else
                            <label for="{{ $column }}">{{ \App\Support\ContractFields::label($source) }}<x-required-mark :explain="true" :when="$required"/></label>
                        @endif
                        @if($automatic)<p class="readonly-field" id="{{ $column }}">{{ $contract->exists ? $contract->displayField($source, $formActor) : 'Gerado ao salvar' }}</p>
                        @elseif($derived || $protected)<p @class(['readonly-field', 'supplier-catalog-field' => $supplierReadonly]) data-derived-field="{{ $column }}">{{ $contract->exists ? $contract->displayField($source, $formActor) : ($column === 'legacy_id' ? 'Sem origem legada' : ($values[$column] ?? 'Preenchido pelo órgão selecionado')) }}</p>
                        @elseif($supplierReadonly)
                            @if($multiline)<textarea id="{{ $column }}" name="{{ $column }}" data-supplier-catalog-field readonly rows="{{ $rows }}">{{ $inputValue }}</textarea>
                            @else<input id="{{ $column }}" name="{{ $column }}" data-supplier-catalog-field readonly value="{{ $inputValue }}">@endif
                        @elseif($column === 'iptu')
                            <select id="iptu" name="iptu" x-data x-on:change="$el.title = $el.selectedOptions[0]?.title || ''" title="{{ \App\Support\ContractFields::IPTU_HELP[is_string($inputValue) ? $inputValue : ''] ?? '' }}" @if($errors->has('iptu')) aria-invalid="true" aria-describedby="iptu-error" @endif>
                                <option value="">Selecione</option>
                                @foreach(\App\Support\ContractFields::IPTU_RESPONSIBILITIES as $choice)<option value="{{ $choice }}" title="{{ \App\Support\ContractFields::IPTU_HELP[$choice] }}" @selected($inputValue === $choice)>{{ $choice }}</option>@endforeach
                                @if($contract->exists && filled($contract->iptu) && !in_array($contract->iptu, \App\Support\ContractFields::IPTU_RESPONSIBILITIES))<option value="{{ $contract->iptu }}" @selected($inputValue === $contract->iptu)>{{ $contract->iptu }}</option>@endif
                            </select>
                        @elseif($column === 'tipo')<input id="tipo" name="tipo" @if($errors->has('tipo')) aria-invalid="true" aria-describedby="tipo-error" @endif list="contract-types" maxlength="15000" value="{{ old($column, $values[$column] ?? $value) }}" @required($required)>
                        @elseif($definition['type'] === 'date')<input id="{{ $column }}" name="{{ $column }}" @if($errors->has($column)) aria-invalid="true" aria-describedby="{{ $column }}-error" @endif type="date" value="{{ old($column, $values[$column] ?? $value) }}" @required($required)>
                        @elseif($definition['type'] === 'money')<input id="{{ $column }}" name="{{ $column }}" @if($errors->has($column)) aria-invalid="true" aria-describedby="{{ $column }}-error" @endif inputmode="decimal" placeholder="Ex.: 1.908,6300" value="{{ old($column, $values[$column] ?? $value) }}" @required($required)>
                        @elseif($multiline)<textarea @if(in_array($column, ['fornecedor_documento_snapshot','cpf_socio','cpf_gestor','cpf_fiscal'])) data-document-format @endif id="{{ $column }}" name="{{ $column }}" @if($errors->has($column)) aria-invalid="true" aria-describedby="{{ $column }}-error" @endif rows="{{ $rows }}" maxlength="15000" @required($required)>{{ $inputValue }}</textarea>
                        @else<input @if(in_array($column, ['fornecedor_documento_snapshot','cpf_socio','cpf_gestor','cpf_fiscal'])) data-document-format @endif id="{{ $column }}" name="{{ $column }}" @if($errors->has($column)) aria-invalid="true" aria-describedby="{{ $column }}-error" @endif maxlength="15000" value="{{ $inputValue }}" @required($required)>@endif
                        @error($column)<p class="field-error" id="{{ $column }}-error">{{ $message }}</p>@enderror
                    </div>
                    @if($column === 'end_imovel')</div>@endif
                @endforeach
            </div></section>
        @endforeach
        @if($contract->exists)<section class="surface contract-section"><h2>Justificativa da alteração</h2><div class="field"><label for="reason">Motivo <x-required-mark :explain="true"/></label><textarea id="reason" name="reason" required minlength="5" maxlength="1000" rows="3">{{ old('reason', $values['reason'] ?? '') }}</textarea>@error('reason')<p class="field-error">{{ $message }}</p>@enderror</div></section>@endif
        </div>
        <div class="form-actions sticky-actions"><a class="secondary-button" href="{{ $contract->exists ? route('contracts.show', $contract) : route('contracts.index') }}">Cancelar</a><button class="primary-button compact" type="submit" @disabled($conflict)><x-nav-icon name="check"/>Salvar contrato</button></div>
    </form>
</x-layouts.app>
