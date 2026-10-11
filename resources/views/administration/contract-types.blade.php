<x-layouts.app title="Requisitos por tipo">
    <div class="page-heading"><div><h1>Requisitos por tipo de contrato</h1><p>Defina campos adicionais para novos contratos ou quando o tipo for alterado. Registros existentes mantêm sua compatibilidade histórica.</p></div></div>
    <section class="surface contract-section">
        <h2>Cadastrar ou atualizar requisitos</h2>
        <form method="POST" action="{{ route('contract-types.store') }}">
            @csrf
            <label><span class="field-caption">Tipo de contrato <x-required-mark/></span><input name="name" maxlength="150" required value="{{ old('name') }}"></label>
            <fieldset><legend>Campos adicionais obrigatórios</legend>
                @foreach(\App\Models\ContractTypeRule::FIELDS as $field=>$label)<label><input type="checkbox" name="required_fields[]" value="{{ $field }}" @checked(in_array($field, old('required_fields', [])))> {{ $label }}</label>@endforeach
            </fieldset>
            <label><span class="field-caption">Justificativa <x-required-mark/></span><textarea name="reason" minlength="10" maxlength="1000" required>{{ old('reason') }}</textarea></label>
            <p class="muted">O nome identifica o tipo, ignorando maiúsculas e acentos. Usar um nome já cadastrado substitui os requisitos anteriores. Deixe todos desmarcados para retirar os requisitos adicionais.</p>
            <button class="secondary-button">Salvar requisitos</button>
        </form>
    </section>
    <section class="surface contract-section"><h2>Requisitos cadastrados</h2>
        @forelse($rules as $rule)<details class="audit-entry"><summary>{{ $rule->name }}</summary><p>{{ collect($rule->required_fields)->map(fn($field)=>\App\Models\ContractTypeRule::FIELDS[$field])->implode(', ') ?: 'Sem requisitos adicionais.' }}</p></details>@empty<p>Nenhuma regra adicional configurada. Os campos básicos continuam obrigatórios.</p>@endforelse
        {{ $rules->links() }}
    </section>
</x-layouts.app>
