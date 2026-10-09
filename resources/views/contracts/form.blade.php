<x-layouts.app :title="$contract->exists ? 'Editar contrato' : 'Novo contrato'">
    <div class="page-heading"><div><a class="text-link" href="{{ $contract->exists ? route('contracts.show', $contract) : route('contracts.index') }}">← Voltar</a><h1>{{ $contract->exists ? 'Editar contrato '.$contract->numero : 'Novo contrato' }}</h1><p>Preencha as seções abaixo. Campos com * são obrigatórios para novos contratos.</p></div></div>
    @if($conflict)<div class="notice notice-error" role="alert">O contrato foi alterado por outra pessoa. Seus valores estão abaixo para consulta; recarregue os dados e revise as diferenças antes de salvar. <a class="text-link" href="{{ route('contracts.edit', $contract) }}">Recarregar contrato</a></div>@endif
    <form method="POST" action="{{ $contract->exists ? route('contracts.update', $contract) : route('contracts.store') }}">
        @csrf @if($contract->exists)@method('PUT')<input type="hidden" name="version" value="{{ $values['version'] ?? $contract->version }}">@endif
        <section class="surface contract-section"><h2>Órgão responsável</h2><div class="form-grid">
            <div class="field"><label for="fund_id">Fundo{{ !$contract->exists ? ' *' : '' }}</label><select id="fund_id" name="fund_id" @required(!$contract->exists)><option value="">{{ $contract->exists ? 'Manter fundo atual / histórico' : 'Selecione' }}</option>@foreach($funds as $fund)<option value="{{ $fund->id }}" @selected((string) old('fund_id', $values['fund_id'] ?? $contract->fund_id) === (string) $fund->id)>{{ $fund->name }}</option>@endforeach</select>@error('fund_id')<p class="field-error">{{ $message }}</p>@enderror</div>
            <div class="field"><label for="department_id">Secretaria</label><select id="department_id" name="department_id"><option value="">Sem secretaria</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected((string) old('department_id', $values['department_id'] ?? $contract->department_id) === (string) $department->id)>{{ $department->name }}</option>@endforeach</select>@error('department_id')<p class="field-error">{{ $message }}</p>@enderror</div>
        </div><p class="hint">O fundo e a secretaria devem corresponder aos órgãos autorizados para sua conta. Os dados do órgão são preenchidos a partir do cadastro selecionado.</p></section>
        @foreach($groups as $group => $fields)
            <section class="surface contract-section"><h2>{{ $group }}</h2><div class="form-grid">
                @foreach($fields as $source => $definition)
                    @php
                        $column = $definition['column'];
                        $derived = in_array($column, \App\Support\ContractFields::DERIVED);
                        $protected = $contract->exists && !auth()->user()->isAdmin() && (in_array($column, \App\Support\ContractFields::PERSONAL) || ($column === 'fornecedor_documento_snapshot' && strlen(\App\Services\Legacy\Normalizer::document($contract->$column)) !== 14));
                        $required = !$contract->exists && in_array($column, ['numero', 'processo', 'fornecedor_nome_snapshot', 'fornecedor_documento_snapshot', 'objeto', 'data_contrato', 'vigencia_inicio', 'vigencia_fim_original', 'tipo', 'valor_total']);
                        $value = $definition['type'] === 'date' ? $contract->$column?->format('Y-m-d') : $contract->$column;
                    @endphp
                    <div class="field"><label for="{{ $column }}">{{ \App\Support\ContractFields::label($source) }}{{ $required ? ' *' : '' }}</label>
                        @if($derived || $protected)<p class="readonly-field">{{ $contract->exists ? $contract->displayField($source, auth()->user()) : ($column === 'legacy_id' ? 'Sem origem legada' : 'Preenchido pelo órgão selecionado') }}</p>
                        @elseif($definition['type'] === 'date')<input id="{{ $column }}" name="{{ $column }}" type="date" value="{{ old($column, $values[$column] ?? $value) }}" @required($required)>
                        @elseif($definition['type'] === 'money')<input id="{{ $column }}" name="{{ $column }}" inputmode="decimal" placeholder="Ex.: 1.908,6300" value="{{ old($column, $values[$column] ?? $value) }}" @required($required)>
                        @else<textarea id="{{ $column }}" name="{{ $column }}" rows="2" maxlength="15000" @required($required)>{{ old($column, $values[$column] ?? $value) }}</textarea>@endif
                        @error($column)<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                @endforeach
            </div></section>
        @endforeach
        @if($contract->exists)<section class="surface contract-section"><h2>Justificativa da alteração</h2><div class="field"><label for="reason">Motivo *</label><textarea id="reason" name="reason" required minlength="5" maxlength="1000" rows="3">{{ old('reason', $values['reason'] ?? '') }}</textarea>@error('reason')<p class="field-error">{{ $message }}</p>@enderror</div><p class="hint">Alterações de vigência, valores e órgão ficam registradas com este motivo.</p></section>@endif
        <div class="form-actions"><a class="secondary-button" href="{{ $contract->exists ? route('contracts.show', $contract) : route('contracts.index') }}">Cancelar</a><button class="primary-button compact" type="submit" @disabled($conflict)>Salvar contrato</button></div>
    </form>
</x-layouts.app>
