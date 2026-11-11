<x-layouts.app title="Nova ATA" :contract-editor="true">
    <x-slot:fundName>FUNDO GERENCIADOR</x-slot:fundName>
    <x-slot:headerBack><a class="text-link fund-header-back" href="{{ route('atas.dashboard') }}">← Voltar</a></x-slot:headerBack>
    <x-slot:fundSupplier><p class="contract-heading-supplier"><span class="sr-only">Fornecedor: </span>Nome / Razão Social</p></x-slot:fundSupplier>
    <x-slot:contractSummary>
        <div class="contract-heading-title" aria-label="Resumo da nova ata">
            <h1 class="sr-only">Nova ATA</h1>
            <div class="contract-number-field">
                <label for="ata-summary-number">Nº ATA</label>
                <div class="contract-number-control">
                    <input id="ata-summary-number" class="contract-number-highlight" type="text" maxlength="100" placeholder="Nº ATA" aria-label="Número da ata para busca">
                    <button type="button" class="contract-number-search" aria-label="Buscar atas" title="Buscar atas"><x-nav-icon name="search"/></button>
                </div>
            </div>
        </div>
    </x-slot:contractSummary>
    <div x-data="{
        step: 0,
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
        get participantFunds() {
            return this.funds.filter(fund => String(fund.id) !== this.managingFund
                && !this.participants.some(participant => String(participant.fund_id) === String(fund.id)));
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
            this.$watch('managingFund', value => {
                if (this.participantFund === value) this.participantFund = '';
            });
        },
        async addParticipant() {
            if (this.busy) return;
            this.busy = true;
            this.message = '';
            try {
                const response = await fetch(this.endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken },
                    body: JSON.stringify({ draft_token: this.draftToken, version: this.version,
                        ata_process: this.process, managing_fund_id: this.managingFund, participant_fund_id: this.participantFund })
                });
                const data = await response.json();
                if (!response.ok) {
                    this.message = Object.values(data.errors || {}).flat().join(' ') || data.message || 'Não foi possível adicionar o Fundo.';
                    return;
                }
                this.participants = data.participants;
                this.version = data.version;
                this.process = data.process;
                this.managingFund = data.managing_fund_id;
                this.participantFund = '';
                this.message = 'Fundo participante adicionado.';
            } catch {
                this.message = 'Não foi possível confirmar a gravação. Recarregue o painel antes de tentar novamente.';
            } finally {
                this.busy = false;
            }
        }
    }" data-draft-state="{{ json_encode($draftState, JSON_THROW_ON_ERROR) }}" data-draft-token="{{ $draftToken }}" data-participants-url="{{ route('atas.participants.store') }}" data-csrf-token="{{ csrf_token() }}">
    <section class="surface contract-section" aria-label="Nova ATA">
        <div class="field-grid">
            <div class="field">
                <label for="ata-managing-fund">Fundo Gerenciador</label>
                <select id="ata-managing-fund" name="managing_fund_id" x-model="managingFund" :disabled="busy">
                    <option value="">Selecione o Fundo Gerenciador</option>
                    @foreach($funds as $fund)<option value="{{ $fund->id }}">{{ $fund->name }}</option>@endforeach
                </select>
            </div>
        </div>
    </section>
    <nav class="section-tabs contract-section-navigation" aria-label="Seções da ata">
        <button type="button" class="section-tab active" :class="{ active: step === 0 }" aria-pressed="true" :aria-pressed="step === 0" aria-controls="ata-process-panel" @click="step = 0">1. Dados Processuais</button>
        <button type="button" class="section-tab" :class="{ active: step === 1 }" aria-pressed="false" :aria-pressed="step === 1" aria-controls="ata-supplier-panel" @click="step = 1">2. Dados do Fornecedor</button>
    </nav>
    <section id="ata-process-panel" class="surface contract-section contract-editor-section min-h-64" aria-label="Dados Processuais" x-show="step === 0">
        <div class="field-grid">
            <div class="field"><label for="ata-process">Processo ATA</label><input id="ata-process" name="ata_process" type="text" maxlength="64" x-model="process" :disabled="busy"></div>
            <div class="field"><label for="ata-bidding">Licitação</label><input id="ata-bidding" name="bidding" type="text"></div>
            <div class="field"><label for="ata-bidding-number">Nº Licit</label><input id="ata-bidding-number" name="bidding_number" type="text"></div>
            <div class="field">
                <label for="ata-participant-fund">Fundos Participantes</label>
                <div class="flex items-center gap-2">
                    <select id="ata-participant-fund" class="min-w-0 flex-1" name="participant_fund_id" x-model="participantFund" :disabled="busy">
                        <option value="">Selecione o Fundo Participante</option>
                        <template x-for="fund in participantFunds" :key="fund.id">
                            <option :value="fund.id" x-text="fund.name"></option>
                        </template>
                    </select>
                    <button type="button" class="primary-button compact shrink-0" @click="addParticipant()" :disabled="busy || !process.trim() || !managingFund || !participantFund"><x-nav-icon name="plus"/>Adicionar</button>
                </div>
            </div>
        </div>
        <p role="status" aria-live="polite" x-text="message"></p>
        <div class="table-scroll" tabindex="0" role="region" aria-label="Fundos participantes adicionados">
            <table class="data-table">
                <thead><tr><th>ATA</th><th>Processo ATA</th><th>CNPJ do Fundo</th><th>Fundo</th><th>Tipo de Fundo</th></tr></thead>
                <tbody>
                    <template x-for="participant in participants" :key="participant.id">
                        <tr><td x-text="participant.ATA || 'Aguardando salvar a ata'"></td><td x-text="participant.Processo_ATA"></td><td x-text="participant.CNPJ_Fundo"></td><td x-text="participant.FUNDO"></td><td x-text="participant.Tipo_Fundo"></td></tr>
                    </template>
                    <tr x-show="participants.length === 0"><td colspan="5">Nenhum Fundo participante adicionado.</td></tr>
                </tbody>
            </table>
        </div>
    </section>
    <section id="ata-supplier-panel" class="surface contract-section contract-editor-section min-h-64" aria-label="Dados do Fornecedor" x-cloak x-show="step === 1"></section>
    </div>
</x-layouts.app>
