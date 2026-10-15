<x-layouts.app :title="$amendment->exists ? 'Editar aditivo' : 'Novo aditivo'">
    <div class="page-heading"><div><a class="text-link" href="{{ route('contracts.amendments.index', $contract) }}">← Aditivos do contrato {{ $contract->numero }}</a><h1>{{ $amendment->exists ? 'Editar aditivo '.$amendment->number : 'Novo aditivo' }}</h1><p>Cadastro documental. Os valores e prazos do contrato permanecem separados.</p></div></div>
    @if($conflict)<div class="notice notice-error" role="alert">O contrato ou o aditivo mudou. Seus dados digitados estão abaixo para consulta. Recarregue e revise as diferenças antes de salvar. <a class="text-link" href="{{ $amendment->exists ? route('contracts.amendments.edit', [$contract,$amendment->id]) : route('contracts.amendments.create', $contract) }}">Recarregar dados</a></div>@endif
    <form method="POST" action="{{ $amendment->exists ? route('contracts.amendments.update', [$contract,$amendment->id]) : route('contracts.amendments.store', $contract) }}">
        @csrf @if($amendment->exists)@method('PUT')<input type="hidden" name="version" value="{{ $values['version'] ?? $amendment->version }}">@endif
        <input type="hidden" name="contract_version" value="{{ $values['contract_version'] ?? $contract->version }}">
        <section class="surface contract-section"><h2>Conferência e documento</h2>
            @if(auth()->user()->isAdmin())<label>Estado do registro<select name="status">@foreach(\App\Models\ContractAmendment::STATUSES as $status=>$label)<option value="{{ $status }}" @selected(old('status', $values['status'] ?? $amendment->status) === $status)>{{ $label }}</option>@endforeach</select></label><p class="muted">Registrar exige conferência administrativa e sinaliza revisão da vigência. A operação não aplica datas nem recalcula valores.</p>
            @else<p>Usuários comuns preparam rascunhos. O registro após conferência cabe ao administrador.</p>@endif
            <label>Documento do contrato<select name="attachment_id"><option value="">Sem documento associado</option>@if($amendment->attachment_id && !$documents->contains('id', $amendment->attachment_id))<option value="{{ $amendment->attachment_id }}" selected>Documento histórico removido · {{ $amendment->attachment_id }}</option>@endif @foreach($documents as $document)<option value="{{ $document->id }}" @selected((string) old('attachment_id', $values['attachment_id'] ?? $amendment->attachment_id) === (string) $document->id)>{{ $document->name }}</option>@endforeach</select></label>
            <p><a class="text-link" href="{{ route('contracts.show', $contract) }}">Anexar documento no contrato</a></p>
        </section>
        @foreach($groups as $group=>$fields)<section class="surface contract-section"><h2>{{ $group }}</h2><div class="form-grid">
            @foreach($fields as $field=>$definition)
                @php
                    $readonly = in_array($field, \App\Support\AmendmentFields::DERIVED) || !auth()->user()->isAdmin() && in_array($field, \App\Support\AmendmentFields::PERSONAL);
                    $required = in_array($field, \App\Support\AmendmentFields::REQUIRED);
                    $value = old('fields.'.$field, $values['fields'][$field] ?? $amendment->payload[$field] ?? '');
                @endphp
                <div class="field"><label for="amendment-{{ $field }}">{{ $definition['label'] }}<x-required-mark :when="$required"/></label>
                    @if($readonly)<p class="readonly-field">{{ $amendment->display($field, auth()->user()) }}</p>
                    @elseif($definition['type']==='date')<input id="amendment-{{ $field }}" @if($errors->has('fields.'.$field)) aria-invalid="true" aria-describedby="amendment-{{ $field }}-error" @endif name="fields[{{ $field }}]" type="date" value="{{ $value }}" @required($required)>
                    @elseif($definition['type']==='money')<input id="amendment-{{ $field }}" @if($errors->has('fields.'.$field)) aria-invalid="true" aria-describedby="amendment-{{ $field }}-error" @endif name="fields[{{ $field }}]" inputmode="decimal" placeholder="Ex.: 1.908,6300" value="{{ $value }}">
                    @elseif(in_array($field, ['Motivo_ADT', 'Texto_Artigos', 'End_Fornecedor', 'End_Fundo']) || mb_strlen((string) $value) > 180 || str_contains((string) $value, "\n") || str_contains((string) $value, "\r"))<textarea id="amendment-{{ $field }}" @if($errors->has('fields.'.$field)) aria-invalid="true" aria-describedby="amendment-{{ $field }}-error" @endif name="fields[{{ $field }}]" rows="{{ in_array($field, ['End_Fornecedor', 'End_Fundo']) ? 2 : 3 }}" maxlength="15000" @required($required)>{{ $value }}</textarea>
                    @else<input id="amendment-{{ $field }}" @if($errors->has('fields.'.$field)) aria-invalid="true" aria-describedby="amendment-{{ $field }}-error" @endif name="fields[{{ $field }}]" maxlength="{{ $field === 'N_ADT' ? 64 : 15000 }}" value="{{ $value }}" @required($required) @if(in_array($field,['CPF_Socio','CPF_Gestor'])) data-document-format @endif>@endif
                    @error('fields.'.$field)<p class="field-error" id="amendment-{{ $field }}-error">{{ $message }}</p>@enderror
                </div>
            @endforeach
        </div></section>@endforeach
        <section class="surface contract-section"><label><span class="field-caption">Justificativa desta operação <x-required-mark/></span><textarea name="reason" minlength="5" maxlength="1000" required>{{ old('reason', $values['reason'] ?? '') }}</textarea></label></section>
        <div class="form-actions sticky-actions"><a class="secondary-button" href="{{ route('contracts.amendments.index', $contract) }}">Cancelar</a><button class="primary-button compact" @disabled($conflict)><x-nav-icon name="save"/>Salvar aditivo</button></div>
    </form>
</x-layouts.app>
