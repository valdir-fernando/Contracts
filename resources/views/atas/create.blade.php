<x-layouts.app title="Nova ATA" :contract-editor="true">
    <x-slot:fundName><span data-ata-managing-fund-heading>FUNDO GERENCIADOR</span></x-slot:fundName>
    <x-slot:headerBack><a class="text-link fund-header-back" href="{{ route('atas.dashboard') }}">← Voltar</a></x-slot:headerBack>
    <x-slot:fundSupplier><p class="contract-heading-supplier"><span class="sr-only">Fornecedor: </span><span data-contract-summary-field="fornecedor_nome_snapshot">Nome / Razão Social</span></p></x-slot:fundSupplier>
    <x-slot:contractSummary>
        <div class="contract-heading-title" aria-label="Resumo da nova ata">
            <h1 class="sr-only">Nova ATA</h1>
            <div class="contract-number-field">
                <label for="ata-summary-number">Nº ATA</label>
                <div class="contract-number-control">
                    <input id="ata-summary-number" class="contract-number-highlight" type="text" maxlength="100" aria-label="Número da ata para busca">
                    <button type="button" class="contract-number-search" aria-label="Buscar atas" title="Buscar atas"><x-nav-icon name="search"/></button>
                </div>
                <p class="hint" role="status" aria-live="polite" data-ata-lookup-status></p>
            </div>
        </div>
    </x-slot:contractSummary>
    <div x-data="{
        step: 0,
        loaded: false,
        loadedAtaId: 0,
        loadedVersion: 0,
        pendingRemoval: null,
        pendingParticipantRemoval: null,
        replacementFund: '',
        replacementMessage: '',
        draftSnapshot: null,
        managingFund: '',
        participantFund: '',
        process: '',
        version: 0,
        participants: [],
        busy: false,
        message: '',
        endpoint: '',
        draftToken: '',
        csrfToken: '',
        funds: @js($funds),
        clearLoaded() {
            if (!this.loaded && !this.busy) {
                this.draftSnapshot = { managingFund: this.managingFund, process: this.process, participants: [...this.participants] };
            }
            if (this.draftSnapshot) Object.assign(this, this.draftSnapshot);
            this.loaded = false;
            this.loadedAtaId = 0;
            this.loadedVersion = 0;
            this.pendingRemoval = null;
            this.pendingParticipantRemoval = null;
            this.$refs?.ataParticipantRemoval?.close();
            this.replacementFund = '';
            this.replacementMessage = '';
            this.$refs?.ataManagerReplacement?.close();
            this.participantFund = '';
            this.message = '';
            this.busy = false;
        },
        get participantFunds() {
            return this.funds.filter(fund => !this.participants.some(participant => String(participant.fund_id) === String(fund.id)));
        },
        get replacementFunds() {
            return this.funds.filter(fund => String(fund.id) !== String(this.pendingRemoval?.fund_id));
        },
        openManagerReplacement(participant, message = '') {
            this.pendingRemoval = participant;
            this.replacementFund = '';
            this.replacementMessage = message;
            if (!this.$refs.ataManagerReplacement.open) this.$refs.ataManagerReplacement.showModal();
        },
        init() {
            if (this.$el) {
                this.endpoint = this.$el.dataset.participantsUrl;
                this.draftToken = this.$el.dataset.draftToken;
                this.csrfToken = this.$el.dataset.csrfToken;
                const initial = JSON.parse(this.$el.dataset.draftState);
                this.managingFund = initial.managing_fund_id;
                this.process = initial.process;
                this.version = initial.version;
                this.participants = initial.participants;
                const url = new URL(window.location.href);
                url.searchParams.set('draft', this.$el.dataset.draftToken);
                window.history.replaceState(null, '', url);
            }
        },
        async confirmParticipantRemoval() {
            if (this.busy || !this.pendingParticipantRemoval) return;
            const participant = this.pendingParticipantRemoval;
            this.pendingParticipantRemoval = null;
            this.$refs.ataParticipantRemoval.close();
            return this.removeParticipant(participant, true);
        },
        async removeParticipant(participant, confirmed = false) {
            if (this.busy) return;
            if (this.loaded && !this.loadedAtaId) return;
            if (participant.Tipo_Fundo !== 'Gerenciador' && !confirmed) {
                this.pendingParticipantRemoval = participant;
                this.$refs.ataParticipantRemoval.showModal();
                return;
            }
            if (this.loaded) {
                if (participant.Tipo_Fundo === 'Gerenciador') {
                    this.openManagerReplacement(participant);
                    return;
                }
                return this.removeSavedParticipant(participant);
            }
            this.busy = true;
            this.message = '';
            try {
                const response = await fetch(this.endpoint + '/' + participant.id, {
                    method: 'DELETE', credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken },
                    body: JSON.stringify({ draft_token: this.draftToken, version: this.version })
                });
                const data = await response.json();
                if (!response.ok) {
                    this.message = data.message || 'Não foi possível excluir o Fundo.';
                    return;
                }
                this.participants = data.participants;
                this.version = data.version;
                this.message = 'Fundo excluído da lista.';
            } catch {
                this.message = 'Não foi possível confirmar a exclusão. Recarregue o painel.';
            } finally {
                this.busy = false;
            }
        },
        async removeSavedParticipant(participant, replacement = null) {
            if (this.busy || !this.loaded || !this.loadedAtaId || !participant) return;
            this.busy = true;
            this.message = '';
            this.replacementMessage = '';
            let reload = null;
            try {
                const url = this.endpoint.replace(/\/participants$/, '/' + this.loadedAtaId + '/participants/') + participant.id;
                const payload = { version: this.loadedVersion };
                if (replacement) payload.replacement_fund_id = replacement;
                const response = await fetch(url, { method: 'DELETE', credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken },
                    body: JSON.stringify(payload) });
                const data = await response.json();
                if (!response.ok) {
                    const message = Object.values(data.errors || {}).flat().join(' ') || data.message || 'Não foi possível excluir o Fundo.';
                    if (data.errors?.replacement_fund_id && !this.pendingRemoval) this.openManagerReplacement(participant, message);
                    this.message = message;
                    this.replacementMessage = message;
                    return;
                }
                this.participants = data.participants;
                this.loadedVersion = data.version;
                this.participantFund = '';
                this.$refs?.ataManagerReplacement?.close();
                this.pendingRemoval = null;
                this.replacementFund = '';
                this.message = data.manager_changed ? 'Fundo excluído e novo gerenciador definido.' : 'Fundo excluído da lista.';
                if (data.manager_changed) reload = { number: data.number };
            } catch {
                this.message = 'Não foi possível confirmar a exclusão. Consulte novamente a ata pelo Nº ATA.';
                this.replacementMessage = this.message;
            } finally {
                this.busy = false;
            }
            if (reload) this.$el.dispatchEvent(new CustomEvent('ata-saved', { bubbles: true, detail: reload }));
        },
        async addParticipant() {
            if (this.busy || (this.loaded && !this.loadedAtaId)) return;
            const saved = this.loaded;
            const dateField = this.$root?.querySelector('[data-ata-date]');
            if (!saved && dateField && !dateField.reportValidity()) return;
            this.busy = true;
            this.message = '';
            try {
                const url = saved ? this.endpoint.replace(/\/participants$/, '/' + this.loadedAtaId + '/participants') : this.endpoint;
                const payload = saved ? { version: this.loadedVersion, participant_fund_id: this.participantFund }
                    : { draft_token: this.draftToken, version: this.version,
                        ata_process: this.process, managing_fund_id: this.managingFund, participant_fund_id: this.participantFund };
                const response = await fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                if (!response.ok) {
                    this.message = Object.values(data.errors || {}).flat().join(' ') || data.message || 'Não foi possível adicionar o Fundo.';
                    return;
                }
                this.participants = data.participants;
                if (saved) {
                    this.loadedVersion = data.version;
                } else {
                    this.version = data.version;
                    this.process = data.process;
                    this.managingFund = data.managing_fund_id;
                }
                this.participantFund = '';
                this.message = 'Fundo participante adicionado.';
            } catch {
                this.message = 'Não foi possível confirmar a gravação. Recarregue o painel antes de tentar novamente.';
            } finally {
                this.busy = false;
            }
        }
    }" data-draft-state="{{ json_encode($draftState, JSON_THROW_ON_ERROR) }}" data-draft-token="{{ $draftToken }}" data-participants-url="{{ route('atas.participants.store') }}" data-csrf-token="{{ csrf_token() }}" class="ata-editor" data-ata-lookup-url="{{ route('atas.lookup') }}" :data-editor-busy="busy" :data-ata-version="loadedVersion" @ata-product-saving="busy = true" @ata-product-finished="busy = false" @ata-loading="busy = true" @ata-loaded="loaded = true; busy = false; loadedAtaId = $event.detail.id; loadedVersion = $event.detail.version; participantFund = ''; message = ''; process = $event.detail.process; managingFund = $event.detail.managing_fund_id; participants = $event.detail.participants" @ata-cleared="clearLoaded()">
    <section class="surface contract-section" aria-label="Nova ATA">
        <div class="field-grid ata-managing-fund-grid">
            <div class="field">
                <label for="ata-managing-fund">Fundo Gerenciador<x-required-mark/></label>
                <select id="ata-managing-fund" name="managing_fund_id" x-model="managingFund" :disabled="busy || loaded" required>
                    <option value="">Selecione o Fundo Gerenciador</option>
                    @foreach($funds as $fund)<option value="{{ $fund->id }}">{{ $fund->name }}</option>@endforeach
                </select>
            </div>
            <div class="field ata-date-field">
                <label for="ata-date">Data_ATA<x-required-mark/></label>
                <input id="ata-date" name="Data_ATA" type="date" min="0001-01-01" max="9999-12-31" data-ata-date :disabled="busy || loaded" required>
            </div>
        </div>
    </section>
    <nav class="section-tabs contract-section-navigation" aria-label="Seções da ata">
        <button type="button" class="section-tab active" :class="{ active: step === 0 }" aria-pressed="true" :aria-pressed="step === 0" aria-controls="ata-process-panel" @click="step = 0">1. Dados Processuais</button>
        <button type="button" class="section-tab" :class="{ active: step === 1 }" aria-pressed="false" :aria-pressed="step === 1" aria-controls="ata-manager-panel" @click="step = 1">2. Dados Gerenciador</button>
        <button type="button" class="section-tab" :class="{ active: step === 2 }" aria-pressed="false" :aria-pressed="step === 2" aria-controls="ata-supplier-panel" @click="step = 2">3. Dados do Fornecedor</button>
        <button type="button" class="section-tab products-button" disabled title="Salve ou carregue uma ata para acessar os Produtos." :disabled="!loaded || busy" :title="loaded ? 'Consultar produtos da ata' : 'Salve ou carregue uma ata para acessar os Produtos.'" aria-haspopup="dialog" aria-controls="ata-products-dialog" @click="$refs.ataProductsSupplier.textContent = $root.querySelector('[name=fornecedor_nome_snapshot]').value || 'Nome / Razão Social'; $refs.ataProducts.showModal()">4. Produtos</button>
    </nav>
    <section id="ata-process-panel" class="surface contract-section contract-editor-section min-h-64" aria-label="Dados Processuais" x-show="step === 0">
        <div class="field-grid">
            <div class="ata-process-fields">
            <div class="field"><label for="ata-process">Processo ATA<x-required-mark/></label><input id="ata-process" name="ata_process" type="text" maxlength="64" x-model="process" :disabled="busy || loaded" required></div>
            <div class="field"><label for="ata-bidding">Licitação<x-required-mark/></label><input id="ata-bidding" name="bidding" type="text" :readonly="loaded" required></div>
            <div class="field"><label for="ata-bidding-number">Nº Licit<x-required-mark/></label><input id="ata-bidding-number" name="bidding_number" type="text" :readonly="loaded" required></div>
            <div class="field">
                <label for="ata-participant-fund">Fundos Participantes</label>
                <div class="flex items-center gap-2">
                    <select id="ata-participant-fund" class="min-w-0 flex-1" name="participant_fund_id" x-model="participantFund" disabled :disabled="busy || !loaded">
                        <option value="">Selecione o Fundo Participante</option>
                        <template x-for="fund in participantFunds" :key="fund.id">
                            <option :value="fund.id" x-text="fund.name"></option>
                        </template>
                    </select>
                    <button type="button" class="primary-button compact shrink-0" @click="addParticipant()" disabled :disabled="busy || !loaded || !participantFund"><x-nav-icon name="plus"/>Adicionar</button>
                </div>
            </div>
            </div>
        </div>
        <p role="status" aria-live="polite" x-text="message"></p>
        <div class="table-scroll" tabindex="0" role="region" aria-label="Fundos participantes adicionados">
            <table class="data-table ata-participants-table">
                <colgroup><col class="ata-participant-number"><col class="ata-participant-process"><col class="ata-participant-cnpj"><col><col class="ata-participant-type"></colgroup>
                <thead><tr><th>ATA</th><th>Processo ATA</th><th>CNPJ do Fundo</th><th>Fundo</th><th>Tipo de Fundo</th></tr></thead>
                <tbody>
                    <template x-for="participant in participants" :key="participant.id">
                        <tr><td x-text="participant.ATA || 'Aguardando salvar a ata'"></td><td x-text="participant.Processo_ATA"></td><td x-text="participant.CNPJ_Fundo.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/, '$1.$2.$3/$4-$5')"></td><td x-text="participant.FUNDO"></td><td><div class="ata-participant-actions"><span x-text="participant.Tipo_Fundo"></span><button type="button" class="text-link" :aria-label="'Excluir ' + participant.FUNDO" @click="removeParticipant(participant)" :disabled="busy">Excluir</button></div></td></tr>
                    </template>
                    <tr x-show="participants.length === 0"><td colspan="5">Nenhum Fundo participante adicionado.</td></tr>
                </tbody>
            </table>
        </div>
    </section>
    <section id="ata-manager-panel" class="surface contract-section contract-editor-section min-h-64" aria-label="Dados Gerenciador" data-ata-contractor-url="{{ route('atas.contractor-defaults', ['fund' => 0]) }}" x-cloak x-show="step === 1">
        <h2>Dados Gerenciador</h2>
        <div class="form-grid ata-contractor-grid">
            @foreach(['CNPJ_Fundo', 'End_Fundo', 'CPF_Gestor', 'RG_Gestor', 'Cargo_Gestor'] as $source)
                @php($definition = \App\Support\ContractFields::MAP[$source])
                @php($column = $definition['column'])
                @if($column === 'fundo_documento_snapshot')<div class="ata-contractor-fund-row">@endif
                @if($column === 'cpf_gestor')<div class="ata-contractor-manager-row">@endif
                <div @class(['field', 'field-wide' => $column === 'nome_gestor', 'ata-contractor-document' => $column === 'fundo_documento_snapshot', 'ata-contractor-address' => $column === 'end_fundo'])>
                    <label for="ata-{{ $column }}">{{ match ($column) { 'cpf_gestor' => 'CPF do Gestor', 'rg_gestor' => 'RG do Gestor', default => \App\Support\ContractFields::label($source) } }}</label>
                    <p class="readonly-field" id="ata-{{ $column }}" role="textbox" aria-readonly="true" data-ata-contractor-field="{{ $column }}"></p>
                </div>
                @if($column === 'end_fundo')</div>@endif
                @if($column === 'cargo_gestor')</div>@endif
            @endforeach
            <div class="ata-manager-registration-row">
                <div class="field"><label for="ata-decreto_gestor">Nº Decreto</label><p class="readonly-field" id="ata-decreto_gestor" role="textbox" aria-readonly="true" data-ata-contractor-field="decreto_gestor"></p></div>
                <div class="field"><label for="ata-matricula_gestor">Matrícula Funcional</label><p class="readonly-field" id="ata-matricula_gestor" role="textbox" aria-readonly="true" data-ata-contractor-field="matricula_gestor"></p></div>
            </div>
        </div>
        <p class="hint" data-ata-contractor-status role="status" aria-live="polite"></p>
    </section>
    <section id="ata-supplier-panel" class="surface contract-section contract-editor-section min-h-64" aria-label="Dados do Fornecedor" x-cloak x-show="step === 2">
        <form data-ata-supplier-form data-supplier-contacts-url="{{ route('suppliers.lookup', ['ata_contacts' => 1]) }}" data-supplier-success-message="" data-supplier-lookup-url="{{ route('suppliers.lookup') }}" @submit.prevent>
            <h2 class="ata-supplier-heading">Dados do Fornecedor<span class="field-label-divider" aria-hidden="true">|</span><button type="button" class="text-link" :disabled="loaded || busy" data-supplier-picker-open data-supplier-list-url="{{ route('suppliers.index') }}">Selecionar fornecedor cadastrado</button></h2>
            @csrf
            <input type="hidden" name="supplier_id" value="">
            <input type="hidden" name="fornecedor_nome_snapshot" id="fornecedor_nome_snapshot" data-supplier-catalog-field readonly value="">
            <div class="form-grid">
                @foreach(\App\Support\ContractFields::MAP as $source => $definition)
                    @continue($definition['group'] !== 'Dados Fornecedor' || $source === 'Fornecedor')
                    @php($column = $definition['column'])
                    @if($column === 'fornecedor_documento_snapshot')<div class="ata-supplier-identification-row">@endif
                    @if($column === 'socio')<div class="ata-supplier-partner-documents">@endif
                    @if($column === 'end_fornec')<div class="ata-supplier-address-row">@endif
                    <div class="field">
                        @if($column === 'fornecedor_documento_snapshot')
                            <div class="field-label-row"><label for="{{ $column }}"><span data-ata-supplier-document-label>{{ \App\Support\ContractFields::label($source) }}</span><x-required-mark :explain="true"/></label></div>
                            <input id="{{ $column }}" name="{{ $column }}" data-document-format maxlength="15000" :readonly="loaded" required>
                        @else
                            <label for="{{ $column }}">{{ \App\Support\ContractFields::label($source) }}</label>
                            @if($column === 'end_fornec')
                                <textarea id="{{ $column }}" name="{{ $column }}" data-supplier-catalog-field readonly rows="1"></textarea>
                            @else
                                <input id="{{ $column }}" name="{{ $column }}" data-supplier-catalog-field readonly value="">
                            @endif
                        @endif
                    </div>
                    @if($column === 'end_fornec')
                        <div class="field"><label for="ata-supplier-email">e-mail</label><input id="ata-supplier-email" name="supplier_email" data-supplier-catalog-field readonly type="email" autocomplete="email" maxlength="254"></div>
                        </div>
                    @endif
                    @if($column === 'pis_pasep')</div>@endif
                    @if($column === 'rg_socio')</div>@endif
                @endforeach
                <div class="ata-supplier-contact-row">
                    <div class="field"><label for="ata-supplier-mobile">Celular</label><input id="ata-supplier-mobile" name="supplier_mobile" data-supplier-catalog-field readonly type="tel" autocomplete="tel" maxlength="25"></div>
                    <div class="field"><label for="ata-supplier-whatsapp">WhatsApp</label><input id="ata-supplier-whatsapp" name="supplier_whatsapp" data-supplier-catalog-field readonly type="tel" maxlength="25"></div>
                </div>
            </div>
        </form>
    </section>
    <div class="form-actions sticky-actions contract-action-toolbar action-toolbar" aria-label="Ações da ata">
        <div class="contract-action-values">
            <div class="field contract-items-total">
                <label for="ata-total">Valor da ATA</label>
                <input id="ata-total" data-money name="Valor_ATA" readonly aria-readonly="true" value="0,0000">
            </div>
        </div>
        <button class="section-tab" type="button" disabled title="Salve ou carregue uma ata para excluir."><x-nav-icon name="trash"/>Excluir</button>
        <a class="section-tab" href="{{ route('atas.create') }}" :class="{ 'pointer-events-none': busy }" :aria-disabled="busy"><x-nav-icon name="plus"/>Novo</a>
        <button class="section-tab" type="button" data-ata-save data-save-url="{{ route('atas.store') }}" :data-version="version" :disabled="busy || loaded" @ata-saving.window="busy = true" @ata-save-finished.window="busy = false" @ata-validation-failed.window="step = $event.detail.step"><x-nav-icon name="save"/>Salvar</button>
    </div>
    <dialog class="catalog-modal" x-ref="ataParticipantRemoval" role="alertdialog" aria-labelledby="ata-participant-removal-title" aria-describedby="ata-participant-removal-message" @close="pendingParticipantRemoval = null">
        <div class="modal-header"><h2 id="ata-participant-removal-title">Excluir Fundo Participante</h2></div>
        <p id="ata-participant-removal-message">Confirma a exclusão do Fundo participante <strong x-text="pendingParticipantRemoval?.FUNDO"></strong> desta ata?</p>
        <div class="form-actions modal-actions">
            <button type="button" class="secondary-button" autofocus @click="$refs.ataParticipantRemoval.close(); pendingParticipantRemoval = null">Cancelar</button>
            <button type="button" class="danger-button" :disabled="busy || !pendingParticipantRemoval" @click="confirmParticipantRemoval()">Excluir participante</button>
        </div>
    </dialog>
    <dialog class="catalog-modal" x-ref="ataManagerReplacement" aria-labelledby="ata-manager-replacement-title" aria-describedby="ata-manager-replacement-rule" @cancel="if (busy) $event.preventDefault()" @close="pendingRemoval = null; replacementFund = ''; replacementMessage = ''">
        <div class="modal-header"><h2 id="ata-manager-replacement-title">Substituir Fundo Gerenciador</h2></div>
        <p id="ata-manager-replacement-rule">Uma ata salva não pode ficar sem Fundo Gerenciador. Selecione outro Fundo para assumir essa função antes de excluir o atual.</p>
        <div class="field"><label for="ata-replacement-fund">Novo Fundo Gerenciador<x-required-mark/></label>
            <select id="ata-replacement-fund" x-model="replacementFund" :disabled="busy" required>
                <option value="">Selecione o novo Fundo Gerenciador</option>
                <template x-for="fund in replacementFunds" :key="fund.id"><option :value="fund.id" x-text="fund.name"></option></template>
            </select>
        </div>
        <p class="field-error" role="alert" x-text="replacementMessage"></p>
        <div class="form-actions modal-actions">
            <button type="button" class="secondary-button" :disabled="busy" @click="$refs.ataManagerReplacement.close(); pendingRemoval = null; replacementFund = ''">Cancelar</button>
            <button type="button" class="primary-button" :disabled="busy || !replacementFund" @click="removeSavedParticipant(pendingRemoval, replacementFund)">Substituir e excluir</button>
        </div>
    </dialog>
    <dialog id="ata-products-dialog" class="catalog-modal contract-products-dialog" x-ref="ataProducts" aria-labelledby="ata-products-title">
        <div class="modal-header">
            <h2 id="ata-products-title" tabindex="-1"><span>PRODUTOS</span><span class="product-options-separator" aria-hidden="true"></span><span class="ata-products-reference">Processo: <span class="ata-products-reference-value" x-text="process || 'Não informado'"></span></span><span class="product-options-separator" aria-hidden="true"></span><span class="ata-products-reference">ATA <span class="ata-products-reference-value" data-ata-products-number></span></span></h2>
            <div class="product-title-supplier"><p class="contract-heading-supplier" data-fit-supplier-name x-ref="ataProductsSupplier">Nome / Razão Social</p></div>
            <button type="button" class="modal-close" @click="$refs.ataProducts.close()" aria-label="Fechar cadastro de produtos">×</button>
        </div>
        <div class="contract-section">
            <p class="hint" x-show="!loaded">Salve ou carregue uma ata para consultar seus produtos.</p>
            <x-contract-products :preview="true" document-label="ATA" :item-lookup-url="route('atas.products.item-lookup', ['ata' => 0])"/>
        </div>
    </dialog>
    </div>
</x-layouts.app>
