@php
    $displayField = fn ($source) => blank($contract->{\App\Support\ContractFields::MAP[$source]['column']}) ? '' : $contract->displayField($source, $formActor);
    $formValue = fn ($key, $default = null) => is_scalar(old('_contract_form_id', '')) && (string) old('_contract_form_id', '') === (string) ($contract->id ?? '') ? old($key, $default) : $default;
@endphp
<x-layouts.app :title="$contract->exists ? 'Editar contrato' : 'Novo contrato'" :contract-editor="true">
    @if($contract->exists)
        <x-slot:fundName>{{ filled($contract->fundo_nome_snapshot) ? $contract->fundo_nome_snapshot : '' }}</x-slot:fundName>
    @endif
    <x-slot:contractSummary><x-contract-summary :contract="$contract" :values="$values" :editable="true" :show-supplier="false" :heading="$contract->exists ? 'Editar contrato' : 'Novo contrato'"/></x-slot:contractSummary>
    <x-slot:fundSupplier><x-contract-supplier-heading :contract="$contract" :values="$values" :editable="true"/></x-slot:fundSupplier>
    <x-slot:headerBack><a class="text-link fund-header-back" href="{{ $contract->exists ? route('contracts.show', $contract) : route('contracts.index') }}">← Voltar</a></x-slot:headerBack>
    @if($conflict)<div class="notice notice-error" role="alert">O contrato foi alterado por outra pessoa. Seus valores estão abaixo para consulta; recarregue os dados e revise as diferenças antes de salvar. <a class="text-link" href="{{ route('contracts.edit', $contract) }}">Recarregar contrato</a></div>@endif
    <form data-contract-form data-supplier-original-document="{{ ($contract->exists && ($formActor->isAdmin() || strlen(\App\Services\Legacy\Normalizer::document($contract->fornecedor_documento_snapshot)) === 14)) ? \App\Services\Legacy\Normalizer::document($contract->fornecedor_documento_snapshot) : '' }}" data-supplier-lookup-url="{{ route('suppliers.lookup') }}" x-data="{ step: 0, all: false }" x-init="$nextTick(() => ($el.querySelector('#department_id:not(:disabled)') || $el.querySelector('#tipo'))?.focus({ preventScroll: true }))" method="POST" novalidate @unless($contract->exists) data-supplier-prefill="{{ json_encode(array_intersect_key($values, array_flip(['fornecedor_documento_snapshot', 'fornecedor_nome_snapshot', 'rg_fornec', 'doc_prof', 'pis_pasep', 'end_fornec', 'socio', 'cpf_socio', 'rg_socio'])), JSON_THROW_ON_ERROR) }}" @endunless action="{{ $contract->exists ? route('contracts.update', $contract) : route('contracts.store') }}">
        @csrf @if($contract->exists)@method('PUT')<input type="hidden" name="version" value="{{ $values['version'] ?? $contract->version }}">@endif
        <input type="hidden" name="_contract_form_id" value="{{ $contract->id ?? '' }}">
        <input type="hidden" name="_stay_in_editor" value="1">
        @if($contract->exists)<span hidden data-supplier-document-type="{{ match (strlen(\App\Services\Legacy\Normalizer::document($contract->fornecedor_documento_snapshot))) { 11 => 'CPF', 14 => 'CNPJ', default => '' } }}"></span>@endif
        @if(!$contract->exists || (int) $contract->fund_id === (int) (request()->attributes->get('selected_fund')['id'] ?? 0))
            <span hidden data-inspector-assistance data-url="{{ route('contracts.inspector-defaults') }}" data-contract-id="{{ $contract->exists ? $contract->id : '' }}" data-preserve-fiscal="{{ $contract->exists && (string) $formValue('department_id', $values['department_id'] ?? $contract->department_id) === (string) $contract->department_id ? 'true' : 'false' }}"></span>
        @endif
        @unless($contract->exists)<input type="hidden" name="supplier_id" value="{{ $values['supplier_id'] ?? '' }}">@endunless
        <input type="hidden" name="fornecedor_nome_snapshot" id="fornecedor_nome_snapshot" readonly data-supplier-catalog-field value="{{ $contract->exists ? $contract->fornecedor_nome_snapshot : ($values['fornecedor_nome_snapshot'] ?? '') }}">
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
            <div class="field"><label for="department_id">Secretaria</label><select id="department_id" name="department_id" @disabled(!$departmentEnabled)><option value="">Sem secretaria</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(($contract->exists || $departmentEnabled) && (string) $formValue('department_id', $values['department_id'] ?? $contract->department_id) === (string) $department->id)>{{ $department->name }}</option>@endforeach</select>@error('department_id')<p class="field-error">{{ $message }}</p>@enderror</div>
            <div class="field"><div class="field-label-row"><label for="tipo">Tipo de contrato<x-required-mark :explain="true" :when="!$contract->exists || filled($contract->tipo)"/></label><x-contract-type-help :contract="$contract" :selected-type="$formValue('tipo', $values['tipo'] ?? $contract->tipo)"/></div><select id="tipo" name="tipo" @required(!$contract->exists || filled($contract->tipo)) @if($errors->has('tipo')) aria-invalid="true" aria-describedby="tipo-error" @endif>
                @if($contract->exists && filled($contract->tipo) && !$contractTypes->contains($contract->tipo))<option data-historical value="{{ $contract->tipo }}" @selected($formValue('tipo', $values['tipo'] ?? $contract->tipo) === $contract->tipo)>{{ $contract->tipo }} (histórico)</option>@endif
                <option value="" @selected(blank($formValue('tipo', $values['tipo'] ?? $contract->tipo)))></option>
                @foreach($contractTypes as $contractType)<option value="{{ $contractType }}" @selected($formValue('tipo', $values['tipo'] ?? $contract->tipo) === $contractType)>{{ $contractType }}</option>@endforeach
            </select>@error('tipo')<p class="field-error" id="tipo-error">{{ $message }}</p>@enderror</div>
        </div></section>
        <x-contract-document-actions :contract="$contract"/>
        </div>
        <nav aria-label="Seções do contrato" class="section-tabs contract-section-navigation">@foreach($groups as $group => $fields)@continue($group === 'Publicação distrato e paralisação')<button type="button" class="section-tab" @disabled(!$contract->exists && $group === 'Financeiro garantia e dotação') @if(!$contract->exists && $group === 'Financeiro garantia e dotação') title="Salve o contrato para acessar o Financeiro." @endif :class="{ active: !all && step === {{ $loop->index }} }" :aria-pressed="!all && step === {{ $loop->index }}" @click="step = {{ $loop->index }}; all = false; $nextTick(() => $dispatch('editor-section-change', { step }))">{{ $loop->iteration }}.{{ $group === 'Publicação distrato e paralisação' ? '' : ' ' }}{{ match ($group) { 'Objeto e dados especiais' => 'Dados do Objeto', 'Vigência execução e aditivos' => 'Vigência/OS/Garantia', 'Financeiro garantia e dotação' => 'Financeiro', 'Publicação distrato e paralisação' => 'Distrato/Paralização', default => $group } }}</button>@endforeach<button type="button" class="section-tab products-button" @disabled(!$contract->exists) @unless($contract->exists) title="Salve o contrato para acessar os Produtos." @endunless data-contract-products-open aria-haspopup="dialog" aria-controls="contract-products-dialog">7. Produtos</button><button type="button" class="section-tab expand-all-button" :class="{ active: all }" :aria-pressed="all" @click="all = true"><x-nav-icon name="documents"/>Expandir Tudo</button></nav>
        <div class="contract-panels-scroll" data-contract-panels-scroll role="region" aria-label="Painéis do contrato" tabindex="0">
        @if($typeRules->isNotEmpty())<details class="surface contract-section detail-disclosure"><summary><h2>Campos específicos por tipo</h2><span>{{ $typeRules->count() }} tipos</span></summary>@foreach($typeRules as $typeRule)<p><strong>{{ $typeRule->name }}:</strong> {{ collect($typeRule->required_fields)->map(fn($field)=>\App\Models\ContractTypeRule::FIELDS[$field])->implode(', ') ?: 'Sem requisitos adicionais' }}</p>@endforeach</details>@endif
    @foreach($groups as $group => $fields)
            <section class="surface contract-section contract-editor-section" data-editor-section="{{ $loop->index }}" x-show="all || step === {{ $loop->index }}"><h2 tabindex="-1">{{ match ($group) { 'Objeto e dados especiais' => 'Dados do Objeto', 'Vigência execução e aditivos' => 'Vigência/OS/Garantia', 'Financeiro garantia e dotação' => 'Financeiro', 'Publicação distrato e paralisação' => 'Distrato/Paralização', default => $group } }}</h2><div class="form-grid">
                @foreach($fields as $source => $definition)
                    @continue(in_array($source, \App\Support\ContractFields::EXECUTION_SUMMARY))
                    @continue($source === 'Ext_Garantia')
                    @continue($source === 'Secretaria')
                    @continue(in_array($source, ['VL_Saldo_Disp', 'VL_Saldo_Contrato', 'Ext_Parcial', 'Ext_Total', 'Ext_Acumulado', 'Ficha', 'Empenho']))
                    @php
                        $column = $definition['column'];
                        $supplierReadonly = in_array($column, \App\Support\ContractFields::SUPPLIER_CATALOG);
                        $contractorReadonly = in_array($column, \App\Support\ContractFields::CONTRACTOR_CATALOG);
                        $derived = in_array($column, \App\Support\ContractFields::DERIVED);
                        $automatic = in_array($column, \App\Support\ContractFields::GENERATED) && (!$contract->exists || $contract->number_sequence !== null);
                        $protected = $contract->exists && !$formActor->isAdmin() && (in_array($column, \App\Support\ContractFields::PERSONAL) || ($column === 'fornecedor_documento_snapshot' && strlen(\App\Services\Legacy\Normalizer::document($contract->$column)) !== 14));
                        $required = !$contract->exists && in_array($column, ['processo', 'fornecedor_documento_snapshot', 'objeto', 'data_contrato', 'vigencia_inicio', 'vigencia_fim_original', 'tipo', 'valor_total', 'modalidade', 'n_licit']);
                        $required = $required || ($contract->exists && in_array($column, ['modalidade', 'n_licit']) && $contract->$column !== null && $contract->$column !== '');
                        $required = $required || $column === 'nome_fiscal';
                        $value = $definition['type'] === 'date' ? $contract->$column?->format('Y-m-d') : $contract->$column;
                        $inputValue = $formValue($column, $values[$column] ?? $value);
                        if ($supplierReadonly || $contractorReadonly) {
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
                    @if($column === 'tipo_veiculo')<div class="contract-vehicle-work-row">@endif
                    @if($column === 'instalacoes')<div class="property-area-row">@endif
                    @if($column === 'cargo_credenc')<div class="contract-service-notice-row">@endif
                    @if($column === 'vigencia_fim_original')<div class="contract-validity-end-row">@endif
                    @if($column === 'tipo_garantia')<div class="contract-guarantee-row">@endif
                    <div @class(['field', 'field-iptu' => $column === 'iptu', 'field-wide' => $multiline && !$derived && !$protected && !in_array($column, ['escopo', 'tipo_veiculo', 'iptu', 'end_imovel', 'cargo_credenc', 'local_servico', 'instalacoes'])])>
                        @if(!$contract->exists && $column === 'fornecedor_documento_snapshot')
                            <div class="field-label-row"><label for="{{ $column }}">{{ \App\Support\ContractFields::label($source) }}<x-required-mark :explain="true" :when="$required"/></label><span class="field-label-divider" aria-hidden="true">|</span><button type="button" class="text-link" data-supplier-picker-open data-supplier-list-url="{{ route('suppliers.index') }}">Selecionar fornecedor cadastrado</button></div>
                        @elseif($column === 'escopo')
                            <div class="field-label-row">
                                <label for="escopo">{{ \App\Support\ContractFields::label($source) }}<x-required-mark :explain="true" :when="$required"/></label>
                                <button type="button" class="field-info-button" aria-label="Entenda as opções Escopo e Contínuo" aria-describedby="scope-help" popovertarget="scope-help" data-field-help="scope-help"><span aria-hidden="true">i</span></button>
                            </div>
                            <div id="scope-help" class="field-help-popover" popover>
                                <p>A principal diferença está na <strong>finalidade do serviço e na forma como o contrato chega ao fim</strong>: o contrato por escopo busca um <strong>resultado final</strong>; o contínuo atende a uma <strong>necessidade permanente ou prolongada</strong> do órgão público.</p>
                                <p>Compare os dois modelos sob a ótica da <strong>Nova Lei de Licitações (Lei nº 14.133/2021)</strong>:</p>
                                <h3>Tabela Comparativa</h3>
                                <div class="scope-comparison-scroll" tabindex="0" role="region" aria-label="Comparação entre contrato por escopo e contrato continuado">
                                    <table class="scope-comparison-table">
                                        <thead><tr><th scope="col">Critério</th><th scope="col">Contrato por Escopo</th><th scope="col">Contrato Continuado</th></tr></thead>
                                        <tbody>
                                            <tr><th scope="row">Objetivo Principal</th><td>Entregar um <strong>produto ou resultado final específico</strong>.</td><td>Manter uma <strong>atividade rotineira</strong> ou atender necessidade permanente ou prolongada.</td></tr>
                                            <tr><th scope="row">Foco da Execução</th><td>A <strong>conclusão</strong> da obra ou serviço.</td><td>A <strong>permanência</strong> do serviço no tempo.</td></tr>
                                            <tr><th scope="row">Exemplos Comuns</th><td>Construção de uma creche, reforma de um hospital, elaboração de projeto e pintura de uma fachada.</td><td>Vigilância, limpeza predial, copeiragem, suporte de TI e manutenção de elevadores, conforme a necessidade contratada.</td></tr>
                                            <tr><th scope="row">Como Termina?</th><td>Com a <strong>conclusão e o recebimento do objeto</strong>, sem prejuízo de outras hipóteses legais de extinção.</td><td>Ao chegar ao <strong>fim da vigência</strong>, sem prorrogação, ou por extinção antecipada.</td></tr>
                                            <tr><th scope="row">Vigência e Prazos</th><td>Vinculada à <strong>conclusão do objeto</strong>. O art. 111 prevê prorrogação automática se ele não for concluído no prazo; havendo culpa do contratado, cabem mora, sanções e possível extinção.</td><td>Em regra, prazo inicial de até <strong>5 anos</strong>, com prorrogações até o limite de <strong>10 anos</strong>, conforme os requisitos dos arts. 106 e 107, ressalvadas hipóteses específicas.</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                                <small>Referência: Lei nº 14.133/2021, arts. 6º, XV e XVII, 106, 107 e 111. Observe os documentos e as condições da contratação.</small>
                            </div>
                        @elseif($column === 'tipo_obra')
                            <div class="field-label-row">
                                <label for="tipo_obra">{{ \App\Support\ContractFields::label($source) }}<x-required-mark :explain="true" :when="$required"/></label>
                                <button type="button" class="field-info-button" aria-label="Entenda os tipos de empreitada e contratação" aria-describedby="work-type-help" popovertarget="work-type-help" data-field-help="work-type-help"><span aria-hidden="true">i</span></button>
                            </div>
                            <div id="work-type-help" class="field-help-popover" popover>
                                <h3>Tipos de empreitada e contratação</h3>
                                <ul>
                                    <li><strong>Empreitada por preço global:</strong> Contratação da obra ou serviço por um preço certo e total.</li>
                                    <li><strong>Empreitada por preço unitário:</strong> Contratação por preço certo de unidades determinadas (com pagamento baseado na medição efetiva de cada unidade executada).</li>
                                    <li><strong>Empreitada integral:</strong> Contratação do empreendimento em sua integralidade, abrangendo todas as etapas, obras, serviços e instalações necessárias para entregá-lo em condições de operação.</li>
                                    <li><strong>Contratação por tarefa:</strong> Regime voltado à contratação de mão de obra para pequenos trabalhos por preço certo.</li>
                                    <li><strong>Contratação integrada:</strong> Exclusivo para engenharia; a empresa contratada é responsável pela elaboração e desenvolvimento dos projetos básico e executivo, além da execução da obra.</li>
                                    <li><strong>Contratação semi-integrada:</strong> Exclusivo para engenharia; o projeto básico é elaborado pela Administração Pública e a contratada fica responsável pelo projeto executivo e pela execução da obra.</li>
                                    <li><strong>Fornecimento e prestação de serviço associado:</strong> Regime em que o objeto principal (como o fornecimento de um bem) vem vinculado à obrigação de prestar serviços de operação e/ou manutenção.</li>
                                </ul>
                            </div>
                        @elseif($column === 'iptu')
                            <div class="field-label-row">
                                <label for="iptu">{{ \App\Support\ContractFields::label($source) }}<x-required-mark :explain="true" :when="$required"/></label>
                                <button type="button" class="field-info-button" aria-label="Entenda Locador(a) e Locatário no IPTU" aria-describedby="iptu-help" popovertarget="iptu-help" data-field-help="iptu-help"><span aria-hidden="true">i</span></button>
                            </div>
                            <div id="iptu-help" class="field-help-popover" popover>
                                <p>Este campo indica <strong>quem assume o pagamento do IPTU no contrato de locação</strong>. Locador(a) é quem disponibiliza o imóvel para alugar; Locatário é quem o aluga para utilizar.</p>
                                <h3>Locador(a) ou Locatário?</h3>
                                <div class="scope-comparison-scroll" tabindex="0" role="region" aria-label="Comparação entre Locador(a) e Locatário">
                                    <table class="scope-comparison-table">
                                        <thead><tr><th scope="col">Critério</th><th scope="col">Locador(a)</th><th scope="col">Locatário</th></tr></thead>
                                        <tbody>
                                            <tr><th scope="row">Quem é?</th><td>Quem cede o uso do imóvel, geralmente seu proprietário.</td><td>Quem recebe o imóvel para uso mediante aluguel, também chamado de inquilino.</td></tr>
                                            <tr><th scope="row">Quando selecionar?</th><td>Quando o pagamento do IPTU fica a cargo de quem aluga o imóvel para terceiros.</td><td>Quando o contrato atribui expressamente o pagamento do IPTU a quem utiliza o imóvel alugado.</td></tr>
                                            <tr><th scope="row">Exemplo</th><td>O proprietário mantém o pagamento do IPTU.</td><td>O Município aluga um imóvel e assume o IPTU por cláusula expressa.</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                                <p>Nas locações regidas pela Lei do Inquilinato, impostos e taxas cabem ao locador, salvo previsão contratual expressa em contrário. <strong>Confira a cláusula de IPTU antes de selecionar.</strong></p>
                                <small>Referência: Lei nº 8.245/1991, art. 22, VIII. A seleção registra a responsabilidade prevista no contrato.</small>
                            </div>
                        @else
                            <label for="{{ $column }}">{{ match ($column) { 'vigencia_fim_original' => 'Final da Vigência', 'garantia' => '(%) Garantia', 'vl_parcial' => 'Valor_Mensal', 'valor_total' => 'Valor do Contrato', 'valor_acumulado' => 'Valor acumulado Aditivo', default => \App\Support\ContractFields::label($source) } }}<x-required-mark :explain="true" :when="$required && !in_array($column, ['vl_parcial', 'valor_total', 'valor_acumulado', 'nome_fiscal'])"/></label>
                        @endif
                        @if(in_array($column, ['vl_parcial', 'valor_total', 'valor_acumulado']))
                            <p class="readonly-field" id="{{ $column }}" role="textbox" aria-readonly="true">{{ $contract->exists ? $displayField($source) : 'A calcular' }}</p>
                            @php($wordsColumn = ['vl_parcial' => 'ext_parcial', 'valor_total' => 'ext_total', 'valor_acumulado' => 'ext_acumulado'][$column])
                            @if($contract->exists && $contract->$column !== null)
                                <p class="contract-financial-message" data-financial-words="{{ $column }}">{{ filled($contract->$wordsColumn) ? $contract->$wordsColumn : 'Valor por extenso não informado.' }}</p>
                            @endif
                        @elseif($contractorReadonly)
                            <p class="readonly-field" id="{{ $column }}" @if($column === 'nome_fiscal') role="textbox" aria-readonly="true" aria-required="true" @if($errors->has($column)) aria-invalid="true" aria-describedby="{{ $column }}-error" @endif @endif @if(!$formActor->isAdmin() && in_array($column, \App\Support\ContractFields::PERSONAL)) data-contractor-private-field="{{ $column }}" @else data-contractor-field="{{ $column }}" @endif>{{ $contract->exists ? $displayField($source) : ($inputValue ?: 'Preenchido automaticamente') }}</p>
                            @if($column === 'nome_fiscal')<p class="hint" data-inspector-status role="status" aria-live="polite"></p>@endif
                        @elseif($automatic)<p class="readonly-field" id="{{ $column }}">{{ $contract->exists ? $displayField($source) : 'Gerado ao salvar' }}</p>
                        @elseif($derived || $protected)<p @class(['readonly-field', 'supplier-catalog-field' => $supplierReadonly]) data-derived-field="{{ $column }}">{{ $contract->exists ? $displayField($source) : ($column === 'legacy_id' ? 'Sem origem legada' : ($values[$column] ?? 'Preenchido pelo órgão selecionado')) }}</p>
                        @elseif($supplierReadonly)
                            @if($multiline)<textarea id="{{ $column }}" name="{{ $column }}" data-supplier-catalog-field readonly rows="{{ $rows }}">{{ $inputValue }}</textarea>
                            @else<input id="{{ $column }}" name="{{ $column }}" data-supplier-catalog-field readonly value="{{ $inputValue }}">@endif
                        @elseif($column === 'tipo_obra')
                            <select id="tipo_obra" name="tipo_obra" @required($required) @if($errors->has('tipo_obra')) aria-invalid="true" aria-describedby="tipo_obra-error" @endif>
                                <option value="" @selected(blank($inputValue))></option>
                                @foreach(\App\Support\ContractFields::WORK_TYPES as $choice)<option value="{{ $choice }}" @selected($inputValue === $choice)>{{ $choice }}</option>@endforeach
                                @if($contract->exists && filled($contract->tipo_obra) && !in_array($contract->tipo_obra, \App\Support\ContractFields::WORK_TYPES))<option value="{{ $contract->tipo_obra }}" @selected($inputValue === $contract->tipo_obra)>{{ $contract->tipo_obra }} (histórico)</option>@endif
                            </select>
                        @elseif($column === 'escopo')
                            <select id="escopo" name="escopo" @required($required) @if($errors->has('escopo')) aria-invalid="true" aria-describedby="escopo-error" @endif>
                                <option value="" @selected(blank($inputValue))></option>
                                @foreach(\App\Support\ContractFields::SCOPE_OPTIONS as $choice)<option value="{{ $choice }}" @selected($inputValue === $choice)>{{ $choice }}</option>@endforeach
                                @if($contract->exists && filled($contract->escopo) && !in_array($contract->escopo, \App\Support\ContractFields::SCOPE_OPTIONS))<option value="{{ $contract->escopo }}" @selected($inputValue === $contract->escopo)>{{ $contract->escopo }} (histórico)</option>@endif
                            </select>
                        @elseif($column === 'iptu')
                            <select id="iptu" name="iptu" x-data x-on:change="$el.title = $el.selectedOptions[0]?.title || ''" title="{{ \App\Support\ContractFields::IPTU_HELP[is_string($inputValue) ? $inputValue : ''] ?? '' }}" @if($errors->has('iptu')) aria-invalid="true" aria-describedby="iptu-error" @endif>
                                <option value="" @selected(blank($inputValue))></option>
                                @foreach(\App\Support\ContractFields::IPTU_RESPONSIBILITIES as $choice)<option value="{{ $choice }}" title="{{ \App\Support\ContractFields::IPTU_HELP[$choice] }}" @selected($inputValue === $choice)>{{ $choice }}</option>@endforeach
                                @if($contract->exists && filled($contract->iptu) && !in_array($contract->iptu, \App\Support\ContractFields::IPTU_RESPONSIBILITIES))<option value="{{ $contract->iptu }}" @selected($inputValue === $contract->iptu)>{{ $contract->iptu }}</option>@endif
                            </select>
                        @elseif($column === 'prazo_obra')
                            <div class="service-order-input-control">
                                <input id="prazo_obra" name="prazo_obra" disabled aria-readonly="true" value="{{ $inputValue }}">
                                <button type="button" class="secondary-button compact" data-service-orders-open data-service-order-type-link hidden title="{{ $contract->exists ? 'Emitir Ordem de Serviço' : 'Salve o contrato antes de cadastrar ordens de serviço' }}" @disabled(!$contract->exists || $conflict)>Emitir Ordem de Serviço</button>
                            </div>
                        @elseif($definition['type'] === 'date')<input id="{{ $column }}" name="{{ $column }}" @if($errors->has($column)) aria-invalid="true" aria-describedby="{{ $column }}-error" @endif type="date" value="{{ $inputValue }}" @required($required)>
                        @elseif($definition['type'] === 'money')<input id="{{ $column }}" name="{{ $column }}" @if($errors->has($column)) aria-invalid="true" aria-describedby="{{ $column }}-error" @endif inputmode="decimal" @if($column !== 'vl_garantia') placeholder="Ex.: 1.908,6300" @endif value="{{ $inputValue }}" @required($required)>
                        @elseif($multiline)<textarea @if(in_array($column, ['fornecedor_documento_snapshot','cpf_socio','cpf_gestor','cpf_fiscal'])) data-document-format @endif id="{{ $column }}" name="{{ $column }}" @if($errors->has($column)) aria-invalid="true" aria-describedby="{{ $column }}-error" @endif rows="{{ $rows }}" maxlength="15000" @required($required)>{{ $inputValue }}</textarea>
                        @else<input @if(in_array($column, ['fornecedor_documento_snapshot','cpf_socio','cpf_gestor','cpf_fiscal'])) data-document-format @endif id="{{ $column }}" name="{{ $column }}" @if($errors->has($column)) aria-invalid="true" aria-describedby="{{ $column }}-error" @endif maxlength="15000" value="{{ $inputValue }}" @required($required)>@endif
                        @error($column)<p class="field-error" id="{{ $column }}-error">{{ $message }}</p>@enderror
                        @if($column === 'vl_garantia')
                            <p class="contract-guarantee-message" data-guarantee-message data-contract-saved="{{ $contract->exists ? 'true' : 'false' }}" hidden>{{ $contract->ext_garantia }}</p>
                            @if($contract->exists)<p class="hint" data-guarantee-save-notice role="status" hidden>O valor da garantia foi alterado. O extenso será atualizado após salvar o contrato.</p>@endif
                        @endif
                    </div>
                    @if($column === 'vigencia_fim_original')
                        <div class="field">
                            <label for="vigencia_fim_atual">Final_Vig_Atualizada</label>
                            <p class="readonly-field" id="vigencia_fim_atual" role="textbox" aria-readonly="true" data-derived-field="vigencia_fim_atual">{{ $contract->exists ? $displayField('Final_Vig_Atualiz') : '' }}</p>
                        </div>
                        <div class="field">
                            <label for="n_aditivo">Nº Aditivo</label>
                            <p class="readonly-field" id="n_aditivo" role="textbox" aria-readonly="true" data-derived-field="n_aditivo">{{ $contract->exists ? $displayField('N_Aditivo') : '' }}</p>
                        </div>
                        <div class="field">
                            <label for="proc_aditivo">Proc_Aditivo</label>
                            <p class="readonly-field" id="proc_aditivo" role="textbox" aria-readonly="true" data-derived-field="proc_aditivo">{{ $contract->exists ? $displayField('Proc_Aditivo') : '' }}</p>
                        </div>
                        </div>
                    @endif
                    @if($column === 'prazo_obra')
                        </div>
                        @if($contract->exists && in_array($contract->tipo, ['Serviço', 'Serviços', 'Serviços Diversos', 'Obras'], true))
                            <div class="contract-service-order-summary">
                                <div class="field">
                                    <label for="n_os">Ordem Serviço</label>
                                    <p class="readonly-field" id="n_os" role="textbox" aria-readonly="true" data-derived-field="n_os">{{ $latestServiceOrder?->number ?? $displayField('N_OS') }}</p>
                                </div>
                                <div class="field">
                                    <label for="data_os">Data OS</label>
                                    <p class="readonly-field" id="data_os" role="textbox" aria-readonly="true" data-derived-field="data_os">{{ $latestServiceOrder?->issued_on?->format('d/m/Y') ?? $displayField('Data_OS') }}</p>
                                </div>
                            </div>
                        @endif
                    @endif
                    @if($column === 'end_imovel')</div>@endif
                    @if($column === 'tipo_obra')</div>@endif
                    @if($column === 'areaconstruida')</div>@endif
                    @if($column === 'proc_edital')</div>@endif
                @endforeach
            </div>
            </section>
        @endforeach
        @if($contract->exists)<input type="hidden" name="reason" value="Atualização cadastral pelo formulário de contratos.">@endif
        </div>
        <dialog id="contract-products-dialog" class="catalog-modal contract-products-dialog" data-contract-products-dialog aria-labelledby="contract-products-title">
            <div class="modal-header">
                <h2 id="contract-products-title" tabindex="-1">Cadastro de produtos</h2>
                <div class="product-title-supplier"><x-contract-supplier-heading :contract="$contract"/></div>
                <button type="button" class="modal-close" data-contract-products-close aria-label="Fechar cadastro de produtos">×</button>
            </div>
            <div class="contract-section"><x-contract-products :contract="$contract" :show-accumulated-value="$showAccumulatedValue"/></div>
        </dialog>
        <div class="form-actions sticky-actions contract-action-toolbar action-toolbar">
            <div class="contract-action-values">
                <div class="field contract-items-total">
                    <label for="contract-items-total">Valor do contrato</label>
                    <input id="contract-items-total" readonly aria-readonly="true" placeholder="A calcular" value="{{ $contract->exists ? \App\Models\Contract::moneyLabel($contract->valor_total) : '' }}">
                </div>
                @if($showAccumulatedValue)
                    <div class="field contract-items-total">
                        <label class="contract-accumulated-label" for="contract-accumulated-total">Valor Acumulado do contrato</label>
                        <input id="contract-accumulated-total" readonly aria-readonly="true" value="{{ \App\Models\Contract::moneyLabel($contract->valor_acumulado) }}">
                    </div>
                @endif
            </div>
            @if($contract->exists)
                @can('delete', $contract)<a class="section-tab" href="{{ route('contracts.show', $contract) }}#delete-contract"><x-nav-icon name="trash"/>Excluir</a>@endcan
            @else
                <button class="section-tab" type="button" disabled><x-nav-icon name="trash"/>Excluir</button>
            @endif
            @can('create', \App\Models\Contract::class)<a class="section-tab" href="{{ route('contracts.create') }}"><x-nav-icon name="plus"/>Novo</a>@endcan
            <button class="section-tab" type="submit" @disabled($conflict)><x-nav-icon name="save"/>Salvar</button>
        </div>
    </form>
    @if($contract->exists)<x-service-orders-dialog :contract="$contract"/>@endif
</x-layouts.app>
