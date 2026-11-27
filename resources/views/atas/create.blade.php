<x-layouts.app title="Nova ATA" :contract-editor="true">
    <x-slot:fundName><span data-ata-managing-fund-heading>FUNDO GERENCIADOR</span></x-slot:fundName>
    <x-slot:headerBack><a class="text-link fund-header-back" href="{{ route('atas.dashboard') }}">← Voltar</a></x-slot:headerBack>
    <x-slot:fundSupplier><p class="contract-heading-supplier"><span class="sr-only">Fornecedor: </span><span class="ata-header-supplier-name" data-fit-supplier-name data-contract-summary-field="fornecedor_nome_snapshot">Nome / Razão Social</span></p></x-slot:fundSupplier>
    <x-slot:contractSummary>
        <div class="contract-heading-title" aria-label="Resumo da nova ata">
            <h1 class="sr-only">Nova ATA</h1>
            <div class="contract-number-field ata-summary-number-field">
                <div class="ata-summary-number-actions">
                    <span id="ata-summary-document-actions" class="ata-product-document-actions" role="group" aria-label="Documentos da ATA">
                        <button type="button" class="section-tab document-action" aria-label="ATA" aria-describedby="ata-document-tip" data-ata-document="ata" data-ata-document-url="{{ route('atas.document', ['ata' => 0]) }}">
                            <x-nav-icon name="contracts"/>ATA
                            <span class="document-action-tooltip" role="tooltip" id="ata-document-tip">Emite Ata de Registro de Preços</span>
                        </button>
                        <button type="button" class="section-tab document-action" aria-label="Extrato" aria-describedby="ata-extract-tip" data-ata-document="extract" data-ata-extract-url="{{ route('atas.extract', ['ata' => 0]) }}">
                            <x-nav-icon name="document-extract"/>EXTRATO
                            <span class="document-action-tooltip" role="tooltip" id="ata-extract-tip">Emite Extrato da ATA</span>
                        </button>
                        <button type="button" class="section-tab document-action" aria-label="Publicação" aria-describedby="ata-publication-tip" data-ata-document="publication" data-ata-publication-url="{{ route('atas.publication', ['ata' => 0]) }}">
                            <x-nav-icon name="document-publication"/>PUBLICAÇÃO
                            <span class="document-action-tooltip" role="tooltip" id="ata-publication-tip">Emite Extrato para Publicação da ATA</span>
                        </button>
                    </span>
                    <span class="product-actions-separator" role="separator" aria-orientation="vertical"></span>
                    <div class="ata-summary-number-search">
                        <label for="ata-summary-number">Nº ATA</label>
                        <div class="contract-number-control">
                            <input id="ata-summary-number" class="contract-number-highlight" type="text" maxlength="100" aria-label="Número da ata para busca">
                            <button type="button" class="contract-number-search" aria-label="Buscar atas" title="Buscar atas"><x-nav-icon name="search"/></button>
                        </div>
                    </div>
                </div>
                <p class="hint" role="status" aria-live="polite" data-ata-lookup-status></p>
                @if(auth()->user()->isAdmin())
                    @include('atas.signed-document-links')
                @endif
            </div>
        </div>
    </x-slot:contractSummary>
    @php($variablePeriodType = \App\Infrastructure\Atas\SaveAtaAmendment::VARIABLE_QUANTITY)
    @php($annualIndexType = \App\Infrastructure\Atas\SaveAtaAmendment::ANNUAL_INDEX)
    @php($apostilleAnnualIndexType = \App\Infrastructure\Atas\SaveAtaAmendment::APOSTILLE)
    @php($amendmentTypes = [
        \App\Infrastructure\Atas\SaveAtaAmendment::EXTENSION,
        \App\Infrastructure\Atas\SaveAtaAmendment::EQUAL_QUANTITY,
        $variablePeriodType,
        $annualIndexType,
        'Prorrogação/Quantitativo/Reajuste Reequilibrio',
        'Apenas Reequilibrio - Saldo Positivo',
        $apostilleAnnualIndexType,
        'Troca de marca ou modelo do produto registrado',
        'Alteração subjetiva (mudança no fornecedor)',
    ])
    <div x-data="{
        step: 0,
        loaded: false,
        publicationLocked: false,
        loadedAtaId: 0,
        loadedVersion: 0,
        amendmentNumber: '',
        amendmentNumbers: [],
        amendments: {},
        amendmentSignedDocuments: {},
        amendmentBaseProducts: [],
        amendmentBalanceQuantities: {},
        amendmentExcludedProducts: [],
        showAllAmendmentProducts: false,
        amendmentBalanceLineTotals: {},
        amendmentAdjustedPrices: {},
        amendmentManualPrices: {},
        amendmentBalanceTotal: '0,0000',
        amendmentBaseTotal: '',
        amendmentSpecialQuantity: '',
        amendmentUpdatedTotal: '',
        amendmentBudgetDate: '',
        amendmentAnnualIndexType: '',
        amendmentMaximumEnd: '',
        amendmentOpen: false,
        amendmentFields: { type: '' },
        amendmentTypes: @js($amendmentTypes),
        variablePeriodType: @js($variablePeriodType),
        amendmentTypeCopyStatus: '',
        amendmentLookupNumber: '',
        amendmentLookupApostille: false,
        apostilleNumbers: [],
        apostilles: {},
        apostilleSignedDocuments: {},
        get isApostille() {
            return this.amendmentFields.type === @js($apostilleAnnualIndexType);
        },
        get currentAmendments() {
            return this.isApostille ? this.apostilles : this.amendments;
        },
        get currentAmendmentNumbers() {
            return this.isApostille ? this.apostilleNumbers : this.amendmentNumbers;
        },
        get currentAmendmentDocuments() {
            return this.isApostille ? this.apostilleSignedDocuments : this.amendmentSignedDocuments;
        },
        get showAmendmentQuantities() {
            return this.amendmentFields.type === @js(\App\Infrastructure\Atas\SaveAtaAmendment::EQUAL_QUANTITY);
        },
        get limitAmendmentQuantitiesToOriginal() {
            return this.amendmentFields.type === @js(\App\Infrastructure\Atas\SaveAtaAmendment::EXTENSION);
        },
        get editAmendmentBalanceQuantities() {
            return this.limitAmendmentQuantitiesToOriginal || this.amendmentFields.type === this.variablePeriodType || this.isAnnualIndexAmendment;
        },
        get amendmentDisplayedValue() {
            if (!this.amendmentOpen) return '';
            if (this.limitAmendmentQuantitiesToOriginal) return '0,0000';
            if (this.editAmendmentBalanceQuantities) return this.amendmentBalanceTotal;
            return this.showAmendmentQuantities ? this.amendmentBaseTotal : '';
        },
        canEditAmendmentProduct(product) {
            return this.loaded && !this.busy && !this.publicationLocked && !product.balanceHistoryOnly;
        },
        get savedBalanceAmendment() {
            if (this.amendmentLookupApostille !== this.isApostille) return null;
            const saved = this.currentAmendments[this.amendmentLookupNumber];
            return this.editAmendmentBalanceQuantities && saved?.type === this.amendmentFields.type ? saved : null;
        },
        get amendmentProductRows() {
            if (!this.editAmendmentBalanceQuantities) return this.amendmentBaseProducts;
            const byId = new Map(this.amendmentBaseProducts.map(product => [String(product.id), product]));
            const snapshot = this.isAnnualIndexAmendment ? this.savedBalanceAmendment?.balance_products : this.amendmentFields.balance_products;
            for (const product of snapshot || []) {
                const id = String(product.id);
                byId.set(id, { ...product, balanceHistoryOnly: !byId.has(id) });
            }
            return Array.from(byId.values());
        },
        get amendmentProducts() {
            const products = this.amendmentProductRows;
            const saved = this.savedBalanceAmendment;
            if (!saved || this.showAllAmendmentProducts) return products;
            const excluded = new Set((saved.excluded_product_ids || []).map(String));
            const included = new Set((saved.balance_products || []).map(product => String(product.id)));
            return products.filter(product => {
                const id = String(product.id);
                return !excluded.has(id) && (included.size === 0 || included.has(id) || this.amendmentBalanceQuantities[id]?.trim());
            });
        },
        get amendmentBalanceSubmission() {
            return Object.entries(this.amendmentBalanceQuantities)
                .filter(([id, quantity]) => quantity?.trim() && !this.amendmentExcludedProducts.includes(id))
                .map(([product_id, quantity]) => ({ product_id, quantity: quantity.trim(),
                    ...(this.isAnnualIndexAmendment ? { adjusted_unit_price: this.amendmentManualPrices[product_id]?.trim() || null } : {}),
                }));
        },
        get amendmentBalanceQuantityWidth() {
            if (!this.editAmendmentBalanceQuantities) return 86;
            return Object.values(this.amendmentBalanceQuantities).reduce((width, value) => {
                const integer = (value.includes(',') ? value.split(',')[0].replaceAll('.', '') : value.split('.')[0]).length;
                const formattedLength = integer + Math.floor(Math.max(0, integer - 1) / 3) + 5;
                return Math.max(width, Math.max(value.length, formattedLength) * 8 + 24);
            }, 86);
        },
        get requiresAmendmentAdvantage() {
            return @js(\App\Infrastructure\Atas\SaveAtaAmendment::ADVANTAGE_TYPES).includes(this.amendmentFields.type)
                && (!this.isAnnualIndexAmendment || this.requiresAnnualIndex);
        },
        get showsAmendmentAdvantage() {
            return this.requiresAmendmentAdvantage || this.isAnnualIndexAmendment;
        },
        get removableAmendment() {
            const number = this.amendmentNumber.trim().replace(/^0+(?=[0-9])/, '');
            return this.amendmentLookupApostille === this.isApostille
                && number === this.amendmentLookupNumber && number === String(this.currentAmendmentNumbers[0]) ? number : '';
        },
        get savedAmendmentNumber() {
            const number = this.amendmentNumber.trim().replace(/^0+(?=[0-9])/, '');
            return /^[0-9]+$/.test(number) && this.currentAmendments[number] ? number : '';
        },
        get historicalAmendmentType() {
            const type = (this.currentAmendments[this.savedAmendmentNumber]?.type || '').trim();
            return @js(\App\Infrastructure\Atas\SaveAtaAmendment::SUPPORTED_TYPES).includes(type) ? '' : type;
        },
        get usesHistoricalVariablePeriodLabel() {
            return this.historicalAmendmentType.toLowerCase() === 'prorrogação de vigência';
        },
        get isAnnualIndexAmendment() {
            return @js(\App\Infrastructure\Atas\SaveAtaAmendment::ANNUAL_INDEX_TYPES).includes(this.amendmentFields.type);
        },
        get requiresAnnualIndex() {
            const saved = this.currentAmendments[this.savedAmendmentNumber];
            return this.isApostille || this.isAnnualIndexAmendment && (saved?.type !== this.amendmentFields.type
                || Boolean(saved.annual_index || this.amendmentFields.annualIndex));
        },
        editAnnualIndex(input) {
            const previous = this.amendmentFields.annualIndex ?? '';
            const normalized = input.value.trim().replace(/\s*%$/, '').replace('.', ',');
            const value = /^\d*(?:,\d*)?$/.test(normalized) ? normalized : previous;
            this.amendmentFields.annualIndex = value;
            if (input.value === value) return;
            const removed = value === normalized ? 0 : Math.max(0, input.value.length - value.length);
            const start = Math.max(0, (input.selectionStart ?? value.length) - removed);
            const end = Math.max(start, (input.selectionEnd ?? start) - removed);
            input.value = value;
            input.setSelectionRange(Math.min(start, value.length), Math.min(end, value.length));
        },
        formatAnnualIndex() {
            const value = (this.amendmentFields.annualIndex ?? '').replace(/%$/, '').replace(/,$/, '').replace(/^,/, '0,');
            this.amendmentFields.annualIndex = value ? value + '%' : '';
        },
        async copyAmendmentType() {
            const select = this.$refs.ataAmendType;
            const type = select.value;
            const label = select.selectedOptions[0]?.label.trim();
            if (!type || !label) return;

            this.amendmentTypeCopyStatus = '';
            try {
                await navigator.clipboard.writeText(label);
                if (select.value === type) this.amendmentTypeCopyStatus = 'copied';
            } catch {
                if (select.value !== type) return;
                this.amendmentTypeCopyStatus = 'manual';
                this.$nextTick(() => {
                    const text = this.$refs.ataAmendTypeCopyText;
                    text.value = label;
                    text.focus();
                    text.select();
                });
            }
        },
        get selectedAmendmentDocument() {
            return this.currentAmendmentDocuments[this.savedAmendmentNumber] || {};
        },
        get printableAmendment() {
            const number = this.savedAmendmentNumber;
            const saved = this.currentAmendments[number];
            if (!saved?.advantage || number !== this.amendmentLookupNumber || this.amendmentLookupApostille !== this.isApostille || saved.type !== this.amendmentFields.type) return '';
            const variable = saved.type === this.variablePeriodType;
            const annual = this.isAnnualIndexAmendment;
            if (!this.showAmendmentQuantities && !this.editAmendmentBalanceQuantities) return '';
            if ((variable || annual) && !saved.has_registered_products) return '';
            if (annual && (!saved.annual_index || this.amendmentFields.annualIndex?.replace(/%$/, '').replace(',', '.') !== saved.annual_index)) return '';
            const unchangedDates = ['date', 'start', 'end'].every(field =>
                saved[field] && (this.amendmentFields[field] === saved[field].split('-').reverse().join('/') || this.amendmentFields[field] === saved[field]));
            if (!unchangedDates || !['process', 'period', 'advantage'].every(field => saved[field] && this.amendmentFields[field]?.trim() === saved[field])) return '';
            if (this.editAmendmentBalanceQuantities && !this.savedQuantitiesMatch(saved)) return '';
            return number;
        },
        savedQuantitiesMatch(saved) {
            const products = saved.balance_products || [];
            const excluded = (saved.excluded_product_ids || []).map(String);
            const quantities = this.amendmentBalanceSubmission;
            if (this.limitAmendmentQuantitiesToOriginal && !products.some(product => product.balance_quantity !== '0.0000' && !excluded.includes(String(product.id)))) return false;
            if (excluded.length !== this.amendmentExcludedProducts.length || !excluded.every(id => this.amendmentExcludedProducts.includes(id))) return false;
            return products.length === quantities.length && quantities.every(({ product_id, quantity, adjusted_unit_price = null }) => {
                const product = products.find(product => String(product.id) === product_id);
                if (product?.balance_quantity !== this.normalizeAmendmentDecimal(quantity)) return false;
                const price = adjusted_unit_price === null ? null : this.normalizeAmendmentDecimal(adjusted_unit_price);
                return !this.isAnnualIndexAmendment || (product.adjusted_unit_price ?? null) === price;
            });
        },
        normalizeAmendmentDecimal(value) {
            if (value.includes(',') && !/^(?:\d+|\d{1,3}(?:\.\d{3})+),\d{0,4}$/.test(value)) return '';
            const decimal = value.includes(',') ? value.replaceAll('.', '').replace(',', '.') : value;
            const match = /^(\d+)(?:\.(\d{0,4}))?$/.exec(decimal);
            return match ? match[1].replace(/^0+(?=\d)/, '') + '.' + (match[2] || '').padEnd(4, '0') : '';
        },
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
        resetAmendment(data = {}) {
            this.amendmentNumbers = data.amendment_numbers || [];
            this.amendments = data.amendments || {};
            this.amendmentSignedDocuments = data.amendment_signed_documents || {};
            this.apostilleNumbers = data.apostille_numbers || [];
            this.apostilles = data.apostilles || {};
            this.apostilleSignedDocuments = data.apostille_signed_documents || {};
            this.amendmentBaseProducts = data.amendment_base_products || [];
            this.amendmentBaseTotal = data.amendment_base_total || '';
            this.amendmentSpecialQuantity = data.amendment_special_quantity_label || '';
            this.amendmentUpdatedTotal = data.amendment_updated_total || '';
            this.amendmentBudgetDate = (data.budget_date ?? '').split('-').reverse().join('/');
            this.amendmentAnnualIndexType = data.annual_index_type ?? '';
            this.amendmentMaximumEnd = data.amendment_maximum_end || '';
            this.clearAmendmentFields();
        },
        clearAmendmentFields() {
            this.amendmentNumber = '';
            this.amendmentFields = { type: '' };
            this.amendmentBalanceQuantities = {};
            this.amendmentManualPrices = {};
            this.amendmentExcludedProducts = [];
            this.showAllAmendmentProducts = false;
            this.amendmentLookupNumber = '';
            this.amendmentLookupApostille = false;
        },
        applySavedAmendment(data) {
            const number = data.amendment_number;
            const apostille = data.amendment.type === @js($apostilleAnnualIndexType);
            const records = apostille ? this.apostilles : this.amendments;
            const documents = apostille ? this.apostilleSignedDocuments : this.amendmentSignedDocuments;
            const numbers = apostille ? this.apostilleNumbers : this.amendmentNumbers;
            this.loadedVersion = data.version;
            this.amendmentUpdatedTotal = data.amendment_updated_total;
            records[number] = data.amendment;
            if (data.signed_amendment_id && !documents[number]) {
                documents[number] = { id: data.signed_amendment_id, copies: [] };
            }
            if (!numbers.includes(number)) {
                numbers.unshift(number);
            }
            if (this.isApostille === apostille && this.savedAmendmentNumber === String(number)) {
                this.amendmentLookupNumber = String(number);
                this.amendmentLookupApostille = this.isApostille;
                this.amendmentExcludedProducts = (data.amendment.excluded_product_ids || []).map(String);
                this.loadAmendmentBalances(data.amendment);
                this.showAllAmendmentProducts = false;
            }
        },
        loadAmendmentBalances(amendment) {
            const products = amendment?.balance_products || [];
            this.amendmentFields.balance_products = products;
            this.amendmentBalanceQuantities = Object.fromEntries(products.map(product => [product.id, product.balance_quantity.replace('.', ',')]));
            this.amendmentManualPrices = Object.fromEntries(products.filter(product => product.adjusted_unit_price != null)
                .map(product => [product.id, product.adjusted_unit_price.replace('.', ',')]));
            this.amendmentFields.annualIndex = (amendment?.annual_index ?? '').replace('.', ',');
            this.formatAnnualIndex();
        },
        closeAmendment() {
            this.amendmentOpen = false;
            this.clearAmendmentFields();
            this.$refs.ataAmend.querySelectorAll('[data-brazilian-date]').forEach(input => input.setCustomValidity(''));
            this.$refs.ataAmendProductList.scrollTop = 0;
        },
        lookupAmendment(force = false) {
            const number = this.amendmentNumber.trim().replace(/^0+(?=[0-9])/, '');
            const apostille = this.isApostille;
            if (!force && number === this.amendmentLookupNumber && apostille === this.amendmentLookupApostille) return;
            const previous = (this.amendmentLookupApostille ? this.apostilles : this.amendments)[this.amendmentLookupNumber];
            this.amendmentLookupNumber = number;
            this.amendmentLookupApostille = apostille;
            const amendment = /^[0-9]+$/.test(number) ? this.currentAmendments[number] : null;
            if (!amendment && !previous) return;
            const type = (amendment?.type || (apostille ? @js($apostilleAnnualIndexType) : '')).trim();
            this.amendmentFields = { ...amendment, type, advantage: amendment?.advantage || '' };
            this.loadAmendmentBalances(amendment);
            this.amendmentExcludedProducts = (amendment?.excluded_product_ids || []).map(String);
            this.showAllAmendmentProducts = false;
            for (const field of ['date', 'start', 'end']) {
                this.amendmentFields[field] = (amendment?.[field] || '').split('-').reverse().join('/');
            }
        },
        changeAmendmentType(type) {
            this.amendmentFields.type = type;
            if (this.isApostille !== this.amendmentLookupApostille) {
                this.lookupAmendment(true);
                if (!this.savedAmendmentNumber) this.amendmentFields.type = type;
            }
        },
        openAmendment() {
            this.$refs.ataAmendNumber.textContent = this.$refs.ataProducts.querySelector('[data-ata-products-number]').textContent;
            this.$refs.ataAmendSupplier.textContent = this.$root.querySelector('[name=fornecedor_nome_snapshot]').value || 'Nome / Razão Social';
            this.$refs.ataAmend.showModal();
            this.amendmentOpen = true;
            this.sizeAmendmentProductList();
        },
        sizeAmendmentProductList() {
            if (!this.$refs.ataAmend.open) return;
            const list = this.$refs.ataAmendProductList;
            const rows = list.querySelectorAll('tbody > tr[data-amendment-product]');
            const lastVisibleRow = rows[3];
            const nextRow = rows[4];
            if (!nextRow) {
                list.style.maxHeight = '';
                list.style.removeProperty('--ata-amend-list-spacer');
                return;
            }
            const listTop = list.getBoundingClientRect().top - list.scrollTop;
            const heightThrough = row => Math.ceil(row.getBoundingClientRect().bottom - listTop);
            const visibleHeight = heightThrough(lastVisibleRow);
            const reservedSpace = heightThrough(nextRow) - visibleHeight;
            list.style.maxHeight = visibleHeight + 'px';
            list.style.setProperty('--ata-amend-list-spacer', reservedSpace + 'px');
        },
        clearLoaded() {
            if (!this.loaded && !this.busy) {
                this.draftSnapshot = { managingFund: this.managingFund, process: this.process, participants: [...this.participants] };
            }
            if (this.draftSnapshot) Object.assign(this, this.draftSnapshot);
            this.loaded = false;
            this.publicationLocked = false;
            this.loadedAtaId = 0;
            this.resetAmendment();
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
    }" data-draft-state="{{ json_encode($draftState, JSON_THROW_ON_ERROR) }}" data-draft-token="{{ $draftToken }}" data-participants-url="{{ route('atas.participants.store') }}" data-csrf-token="{{ csrf_token() }}" class="ata-editor" data-ata-lookup-url="{{ route('atas.lookup') }}" :data-editor-busy="busy" :data-ata-version="loadedVersion" @ata-amendment-saved="applySavedAmendment($event.detail)" @ata-product-saving="busy = true" @ata-product-finished="busy = false" @ata-loading="busy = true; amendmentUpdatedTotal = $event.detail.amendment_updated_total || amendmentUpdatedTotal" @ata-loaded="loaded = true; publicationLocked = Boolean($event.detail.publication_date); busy = false; loadedAtaId = $event.detail.id; loadedVersion = $event.detail.version; resetAmendment($event.detail); participantFund = ''; message = ''; process = $event.detail.process; managingFund = $event.detail.managing_fund_id; participants = $event.detail.participants" @ata-special-quantity-updated="loadedVersion = $event.detail.version" @ata-cleared="clearLoaded()">
    <section class="surface contract-section" aria-label="Nova ATA">
        <p class="notice notice-warning" x-cloak x-show="publicationLocked" role="status">{{ \App\Infrastructure\Atas\AtaWritePolicy::PUBLISHED_MESSAGE }}</p>
        <div class="field-grid ata-managing-fund-grid">
            <div class="field">
                <label for="ata-managing-fund">Fundo Gerenciador<x-required-mark/></label>
                <select id="ata-managing-fund" name="managing_fund_id" x-model="managingFund" :disabled="busy || loaded" required>
                    <option value="">Selecione o Fundo Gerenciador</option>
                    @foreach($funds as $fund)<option value="{{ $fund->id }}">{{ $fund->name }}</option>@endforeach
                </select>
            </div>
            <div class="field ata-date-field">
                <label for="ata-date">Data da ATA<x-required-mark/></label>
                <input id="ata-date" name="Data_ATA" type="date" min="0001-01-01" max="9999-12-31" data-ata-date :disabled="busy || publicationLocked" required>
            </div>
            <div class="field">
                <label for="ata-admit-admission">Admitir Adesão a ATA</label>
                <select id="ata-admit-admission" name="admitir_adesao" :disabled="busy || publicationLocked">
                    <option value="">Selecione</option>
                    <option value="SIM">SIM</option>
                    <option value="NAO">NÃO</option>
                </select>
            </div>
        </div>
    </section>
    <nav class="section-tabs contract-section-navigation" aria-label="Seções da ata">
        <button type="button" class="section-tab active" :class="{ active: step === 0 }" aria-pressed="true" :aria-pressed="step === 0" aria-controls="ata-process-panel" @click="step = 0">1. Dados Processuais</button>
        <button type="button" class="section-tab" :class="{ active: step === 1 }" aria-pressed="false" :aria-pressed="step === 1" aria-controls="ata-manager-panel" @click="step = 1">2. Dados Gerenciador</button>
        <button type="button" class="section-tab" :class="{ active: step === 2 }" aria-pressed="false" :aria-pressed="step === 2" aria-controls="ata-supplier-panel" @click="step = 2">3. Dados do Fornecedor</button>
        <button type="button" class="section-tab products-button" disabled title="Salve ou carregue uma ata para acessar os Produtos." :disabled="!loaded || busy" :title="loaded ? 'Consultar produtos da ata' : 'Salve ou carregue uma ata para acessar os Produtos.'" aria-haspopup="dialog" aria-controls="ata-products-dialog" @click="$refs.ataProductsSupplier.textContent = $root.querySelector('[name=fornecedor_nome_snapshot]').value || 'Nome / Razão Social'; $refs.ataProducts.showModal(); $nextTick(() => $dispatch('ata-products-opened'))">4. Produtos</button>
        <button type="button" class="section-tab ata-delivery-button" aria-haspopup="dialog" aria-controls="ata-delivery-dialog" :disabled="busy" @click="$refs.ataDeliveryNumber.textContent = $refs.ataProducts.querySelector('[data-ata-products-number]').textContent; $refs.ataDeliverySupplier.textContent = $root.querySelector('[name=fornecedor_nome_snapshot]').value || 'Nome / Razão Social'; $refs.ataDelivery.showModal(); $dispatch('ata-delivery-opened')">5. Aut. Entrega</button>
        <button type="button" class="section-tab ata-amend-tab" disabled :disabled="!loaded || busy" aria-haspopup="dialog" aria-controls="ata-amend-dialog" @click="openAmendment()" x-text="isApostille ? '6. Apostilar ATA' : '6. Aditivar ATA'">6. Aditivar ATA</button>
        <button type="button" class="section-tab ata-cancel-tab" disabled :disabled="!loaded || busy">7. Cancelar ATA</button>
    </nav>
    <section id="ata-process-panel" class="surface contract-section contract-editor-section min-h-64" aria-label="Dados Processuais" x-show="step === 0">
        <div class="field-grid">
            <div class="ata-process-fields">
            <div class="field"><label for="ata-process">Processo ATA<x-required-mark/></label><input id="ata-process" name="ata_process" type="text" maxlength="64" x-model="process" :disabled="busy || publicationLocked" required></div>
            <div class="field"><label for="ata-bidding">Licitação<x-required-mark/></label><input id="ata-bidding" name="bidding" type="text" maxlength="100" :disabled="busy || publicationLocked" required></div>
            <div class="field"><label for="ata-bidding-number">Nº Licit<x-required-mark/></label><input id="ata-bidding-number" name="bidding_number" type="text" maxlength="32" :disabled="busy || publicationLocked" required></div>
            <div class="ata-process-participants">
            <div class="field">
                <label for="ata-participant-fund">Fundos Participantes</label>
                <div class="flex items-center gap-2">
                    <select id="ata-participant-fund" class="min-w-0 flex-1" name="participant_fund_id" x-model="participantFund" disabled :disabled="busy || publicationLocked || !loaded">
                        <option value="">Selecione o Fundo Participante</option>
                        <template x-for="fund in participantFunds" :key="fund.id">
                            <option :value="fund.id" x-text="fund.name"></option>
                        </template>
                    </select>
                    <button type="button" class="primary-button compact shrink-0" @click="addParticipant()" disabled :disabled="busy || publicationLocked || !loaded || !participantFund"><x-nav-icon name="plus"/>Adicionar</button>
                </div>
            </div>
            <div class="field">
                <label for="ata-budget-date">Data_Orçamento Estimado<x-required-mark/></label>
                <div class="ata-budget-date-control">
                    <input id="ata-budget-date" name="Data_Orçamento" type="date" min="0001-01-01" max="9999-12-31" :disabled="busy || publicationLocked" required>
                    <button type="button" class="field-info-button" aria-label="Informações sobre Data_Orçamento Estimado" aria-describedby="ata-budget-date-help"
                        popovertarget="ata-budget-date-help" data-field-help="ata-budget-date-help"><span aria-hidden="true">i</span></button>
                </div>
                <div id="ata-budget-date-help" class="field-help-popover" popover>
                    <p>A data servirá para a validação da anuidade para aditivos de reajute de preços</p>
                </div>
            </div>
            </div>
            </div>
        </div>
        <div class="ata-validity-fields" data-ata-validity>
            <div class="field ata-validity-period-field">
                <div class="ata-validity-heading">
                    <label for="ata-validity-period">Período da Vigência</label>
                    <span class="ata-validity-separator" aria-hidden="true"></span>
                    <label class="ata-validity-option" title="1 (um) ano, contado a partir do primeiro dia útil subsequente à data de divulgação no PNCP"><input type="checkbox" name="one_year_inpc" value="1" :disabled="busy || publicationLocked">1 Ano INPC</label>
                </div>
                <div class="ata-validity-period-control">
                    <input id="ata-validity-period" name="validity_period" type="text" maxlength="5000" aria-describedby="ata-inpc-notice" :disabled="busy || publicationLocked">
                </div>
            </div>
            <div class="field"><label for="ata-validity-start">Data_Inicial</label><div class="ata-validity-date-control"><input id="ata-validity-start" name="start_date" type="date" min="0001-01-01" max="9999-12-31" :disabled="busy || publicationLocked || $el.dataset.validityDisabled === 'true'"><button type="button" class="text-link ata-locked-date-notice" data-ata-locked-date aria-label="Data_Inicial: campo bloqueado para edição"></button></div></div>
            <div class="field"><label for="ata-validity-end">Data_Final</label><div class="ata-validity-date-control"><input id="ata-validity-end" name="end_date" type="date" min="0001-01-01" max="9999-12-31" :disabled="busy || publicationLocked || $el.dataset.validityDisabled === 'true'"><button type="button" class="text-link ata-locked-date-notice" data-ata-locked-date aria-label="Data_Final: campo bloqueado para edição"></button></div></div>
            <div class="field ata-annual-index-type-field">
                <label for="ata-annual-index-type">Tipo_Indice_Anual</label>
                <select id="ata-annual-index-type" name="annual_index_type" :disabled="busy || publicationLocked">
                    <option value="">Selecione</option>
                    @foreach(\App\Support\ContractFields::ADJUSTMENT_INDICES as $index => $description)
                        <option value="{{ $index }}" title="{{ $description }}">{{ $index }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field ata-publication-date-field" data-ata-publication-field>
                <label for="ata-publication-date">Data_Public_INPC</label>
                <input id="ata-publication-date" name="publication_date" type="date" min="0001-01-01" max="9999-12-31" :disabled="busy || publicationLocked">
            </div>
            @if(auth()->user()->isAdmin())
                @include('atas.signed-document-control')
            @endif
            <p id="ata-inpc-notice" class="ata-inpc-notice" data-ata-inpc-notice hidden>A validade da Ata de Registro de Preços será de 1 (um) ano, contado a partir do primeiro dia útil subsequente à data de divulgação no PNCP</p>
        </div>
        <div class="table-scroll" tabindex="0" role="region" aria-label="Fundos participantes adicionados" data-visible-rows="3">
            <table class="data-table ata-participants-table">
                <colgroup><col class="ata-participant-number"><col class="ata-participant-process"><col class="ata-participant-cnpj"><col><col class="ata-participant-type"></colgroup>
                <thead><tr><th>ATA</th><th>Processo ATA</th><th>CNPJ do Fundo</th><th>Fundo</th><th>Tipo de Fundo</th></tr></thead>
                <tbody>
                    <template x-for="participant in participants" :key="participant.id">
                        <tr><td x-text="participant.ATA || 'Aguardando salvar a ata'"></td><td x-text="participant.Processo_ATA"></td><td x-text="participant.CNPJ_Fundo.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/, '$1.$2.$3/$4-$5')"></td><td x-text="participant.FUNDO"></td><td><div class="ata-participant-actions"><span x-text="participant.Tipo_Fundo"></span><button type="button" class="text-link" :aria-label="'Excluir ' + participant.FUNDO" @click="removeParticipant(participant)" :disabled="busy || publicationLocked">Excluir</button></div></td></tr>
                    </template>
                    <tr x-show="participants.length === 0"><td colspan="5">Nenhum Fundo participante adicionado.</td></tr>
                </tbody>
            </table>
        </div>
        <p role="status" aria-live="polite" x-text="message"></p>
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
                <div class="field">
                    <label id="ata-manager-name-label">Nome do Gestor</label>
                    <p class="readonly-field" role="textbox" aria-readonly="true" aria-labelledby="ata-manager-name-label">
                        <span class="ata-manager-name" data-fit-fund-name data-ata-contractor-field="nome_gestor"></span>
                    </p>
                </div>
            </div>
        </div>
        <p class="hint" data-ata-contractor-status role="status" aria-live="polite"></p>
    </section>
    <section id="ata-supplier-panel" class="surface contract-section contract-editor-section min-h-64" aria-label="Dados do Fornecedor" x-cloak x-show="step === 2">
        <form data-ata-supplier-form data-supplier-contacts-url="{{ route('suppliers.lookup', ['ata_contacts' => 1]) }}" data-supplier-success-message="" data-supplier-lookup-url="{{ route('suppliers.lookup') }}" @submit.prevent>
            <h2 class="ata-supplier-heading">Dados do Fornecedor<span class="field-label-divider" aria-hidden="true">|</span><button type="button" class="text-link" :disabled="busy || publicationLocked" data-supplier-picker-open data-supplier-list-url="{{ route('suppliers.index') }}">Selecionar fornecedor cadastrado</button></h2>
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
                            <input id="{{ $column }}" name="{{ $column }}" data-document-format maxlength="32" :readonly="busy || publicationLocked" required>
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
        <button class="section-tab" type="button" data-ata-delete data-delete-url="{{ route('atas.destroy', ['ata' => 0]) }}" disabled :disabled="!loaded || publicationLocked"><x-nav-icon name="trash"/>Excluir</button>
        <a class="section-tab" href="{{ route('atas.create') }}" data-ata-new><x-nav-icon name="plus"/>Novo</a>
        <button class="section-tab" type="button" data-ata-save data-save-url="{{ route('atas.store') }}" data-update-url="{{ route('atas.update', ['ata' => 0]) }}" :disabled="publicationLocked" :data-version="version" @ata-saving.window="busy = true" @ata-save-finished.window="busy = false" @ata-validation-failed.window="step = $event.detail.step"><x-nav-icon name="save"/>Salvar</button>
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
            <p class="notice notice-warning" x-cloak x-show="publicationLocked" role="status">{{ \App\Infrastructure\Atas\AtaWritePolicy::PUBLISHED_MESSAGE }}</p>
            <x-contract-products :preview="true" document-label="ATA" :item-lookup-url="route('atas.products.item-lookup', ['ata' => 0])"/>
        </div>
    </dialog>
    <dialog id="ata-delivery-dialog" class="catalog-modal contract-products-dialog" x-ref="ataDelivery" aria-labelledby="ata-delivery-title"
        data-order-lookup-url="{{ route('atas.delivery-orders.lookup', ['ata' => 0]) }}">
        <div class="modal-header">
            <h2 id="ata-delivery-title" tabindex="-1"><span>Autorização de Entrega</span><span class="product-options-separator" aria-hidden="true"></span><span class="ata-products-reference">Processo: <span class="ata-products-reference-value" x-text="process || 'Não informado'"></span></span><span class="product-options-separator" aria-hidden="true"></span><span class="ata-products-reference">ATA <span class="ata-products-reference-value" x-ref="ataDeliveryNumber"></span></span></h2>
            <div class="product-title-supplier"><p class="contract-heading-supplier" data-fit-supplier-name x-ref="ataDeliverySupplier">Nome / Razão Social</p></div>
            <button type="button" class="modal-close" aria-label="Fechar autorização de entrega" data-order-close @click="$refs.ataDelivery.close()">×</button>
        </div>
        <div class="contract-section">
            <div class="ata-delivery-order-toolbar">
                <form class="field ata-delivery-order-number" data-order-lookup>
                    <label for="ata-delivery-order-number">Nº Pedido</label>
                    <div class="product-item-search">
                        <input id="ata-delivery-order-number" type="text" maxlength="24" placeholder="001/26" autocomplete="off">
                        <button type="submit" class="product-item-search-button" aria-label="Consultar pedido" title="Consultar pedido"><x-nav-icon name="search"/></button>
                    </div>
                </form>
                <button type="button" class="primary-button ata-delivery-products-button">Consultar Produtos ATA</button>
                <p class="hint" role="status" data-order-status>Salve ou consulte uma ATA para consultar seus pedidos.</p>
            </div>
            <div class="product-list-scroll product-contract-list" data-delivery-product-list>
                <table class="data-table" aria-label="Produtos do pedido"><tbody></tbody></table>
            </div>
        </div>
    </dialog>
    <dialog id="ata-amend-dialog" class="catalog-modal contract-products-dialog" x-ref="ataAmend" aria-labelledby="ata-amend-title"
        @ata-amendment-balance-calculated="amendmentBalanceLineTotals = $event.detail.lines; amendmentBalanceTotal = $event.detail.total; amendmentAdjustedPrices = $event.detail.prices"
        x-effect="amendmentProducts; $nextTick(() => sizeAmendmentProductList())" @resize.window="sizeAmendmentProductList()" @close="closeAmendment()">
        <div class="modal-header">
            <h2 id="ata-amend-title" tabindex="-1"><span x-text="isApostille ? 'Apostilar ATA' : (savedAmendmentNumber ? 'Aditivo Nº ' + savedAmendmentNumber : 'Aditivar ATA')">Aditivar ATA</span><span class="product-options-separator" aria-hidden="true"></span><span class="ata-products-reference">Processo: <span class="ata-products-reference-value" x-text="process || 'Não informado'"></span></span><span class="product-options-separator" aria-hidden="true"></span><span class="ata-products-reference">ATA <span class="ata-products-reference-value" x-ref="ataAmendNumber"></span></span></h2>
            <div class="product-title-supplier"><p class="contract-heading-supplier" data-fit-supplier-name x-ref="ataAmendSupplier">Nome / Razão Social</p></div>
            <button type="button" class="modal-close" @click="$refs.ataAmend.close()" aria-label="Fechar painel de aditivos"
                :aria-label="isApostille ? 'Fechar painel de apostilamentos' : 'Fechar painel de aditivos'">×</button>
        </div>
        <div class="contract-section">
            <div class="ata-amend-fields">
                <div class="field ata-amend-number">
                    <label for="ata-amend-number" x-text="isApostille ? 'Nº Apostilamento' : 'Nº Aditivo'">Nº Aditivo</label>
                    <div class="product-item-search">
                        <input id="ata-amend-number" type="text" list="ata-previous-amendments" maxlength="32" autocomplete="off" required x-model="amendmentNumber" @blur="lookupAmendment()">
                        <button type="button" class="product-item-search-button" aria-label="Consultar aditivo" title="Consultar aditivo" @click="lookupAmendment(true)"
                            :aria-label="isApostille ? 'Consultar apostilamento' : 'Consultar aditivo'" :title="isApostille ? 'Consultar apostilamento' : 'Consultar aditivo'"><x-nav-icon name="search"/></button>
                    </div>
                    <datalist id="ata-previous-amendments">
                        <template x-for="number in currentAmendmentNumbers" :key="number">
                            <option :value="number"></option>
                        </template>
                    </datalist>
                </div>
                <div class="field ata-amend-date">
                    <label for="ata-amend-date" x-text="isApostille ? 'Data_Apost.' : 'Data_ADT'">Data_ADT</label>
                    <input id="ata-amend-date" name="Data_ADT" type="date" required x-model="amendmentFields.date">
                </div>
                <div class="field ata-amend-process">
                    <label for="ata-amend-process" x-text="isApostille ? 'Proc_Apost.' : 'Proc_ADT'">Proc_ADT</label>
                    <input id="ata-amend-process" name="Proc_ADT" type="text" maxlength="64" required x-model="amendmentFields.process">
                </div>
                <div class="field ata-amend-type">
                    <div class="field-label-row">
                        <label for="ata-amend-type">Tipo_ADT</label>
                        <button type="button" class="text-link" aria-label="Copiar Tipo_ADT" disabled
                            :disabled="!amendmentFields.type" @click="copyAmendmentType()">Copiar</button>
                        <span role="status" x-text="amendmentTypeCopyStatus === 'copied' ? 'Copiado' : ''"></span>
                    </div>
                    <select id="ata-amend-type" name="Tipo_ADT" data-fit-field required x-ref="ataAmendType" x-model="amendmentFields.type" @change="changeAmendmentType($el.value)"
                        x-effect="amendmentFields.type; amendmentTypeCopyStatus = ''; $nextTick(() => $dispatch('fit-field'))"
                        @keydown.ctrl.c.prevent="copyAmendmentType()" @keydown.meta.c.prevent="copyAmendmentType()">
                        <option value="" disabled hidden></option>
                        @foreach($amendmentTypes as $type)
                            @if($type === $variablePeriodType)
                                <option value="{{ $type }}" :hidden="usesHistoricalVariablePeriodLabel" :disabled="usesHistoricalVariablePeriodLabel">{{ $type }}</option>
                            @elseif($type === $annualIndexType)
                                <option value="{{ $type }}">Prorrogação/Quantitativo/Reajuste ANUAL INDICE</option>
                            @elseif($type === $apostilleAnnualIndexType)
                                <option value="{{ $type }}">Apostilamento/Reajuste ANUAL INDICE</option>
                            @else
                                <option value="{{ $type }}"@unless(in_array($type, \App\Infrastructure\Atas\SaveAtaAmendment::SUPPORTED_TYPES, true)) disabled :disabled="historicalAmendmentType !== $el.value"@endunless>{{ $type }}</option>
                            @endif
                        @endforeach
                        <template x-if="historicalAmendmentType && !amendmentTypes.includes(historicalAmendmentType)">
                            <option :value="historicalAmendmentType" x-text="usesHistoricalVariablePeriodLabel ? variablePeriodType : historicalAmendmentType"></option>
                        </template>
                    </select>
                </div>
                <div class="field ata-amend-date">
                    <label for="ata-amend-start">INICIO</label>
                    <input id="ata-amend-start" name="INICIO" type="date" required x-model="amendmentFields.start">
                </div>
                <div class="field ata-amend-date">
                    <label for="ata-amend-end">FINAL</label>
                    <input id="ata-amend-end" name="FINAL" type="date" required x-model="amendmentFields.end" :data-amendment-end-limit="isApostille ? null : amendmentMaximumEnd">
                </div>
                <div class="field ata-amend-period">
                    <label for="ata-amend-period">Período</label>
                    <input id="ata-amend-period" name="Período" type="text" maxlength="1000" data-fit-field required x-model="amendmentFields.period"
                        x-effect="amendmentFields.period; $nextTick(() => $dispatch('fit-field'))">
                </div>
            </div>
            <div class="field" x-cloak x-show="amendmentTypeCopyStatus === 'manual'">
                <label for="ata-amend-type-copy-text">Copie o texto selecionado</label>
                <input id="ata-amend-type-copy-text" x-ref="ataAmendTypeCopyText" type="text" readonly>
            </div>
            <div class="ata-amend-fields ata-amend-adjustment-fields" x-cloak x-show="showsAmendmentAdvantage">
                <div class="field">
                    <label for="ata-amend-advantage">Vantajosidade</label>
                    <select id="ata-amend-advantage" name="VANTAJOSIDADE" x-model="amendmentFields.advantage"
                        :required="requiresAmendmentAdvantage" :disabled="!showsAmendmentAdvantage || busy">
                        <option value="" disabled>Selecione</option>
                        @foreach(\App\Infrastructure\Atas\SaveAtaAmendment::ADVANTAGE_OPTIONS as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field" x-show="isAnnualIndexAmendment">
                    <label for="ata-amend-annual-index">Indice_Anual</label>
                    <input id="ata-amend-annual-index" name="Indice_Anual" type="text" inputmode="decimal" maxlength="64" data-percentage
                        :value="amendmentFields.annualIndex ?? ''"
                        @focus="if ($el.value.endsWith('%')) { editAnnualIndex($el); $el.select(); }"
                        @input="editAnnualIndex($el)" @blur="formatAnnualIndex()"
                        :required="requiresAnnualIndex" :disabled="!isAnnualIndexAmendment || busy">
                </div>
                <div class="ata-amend-reference-fields" x-show="isAnnualIndexAmendment">
                    <div class="field">
                        <label for="ata-amend-budget-date">Data_Orçamento Estimado</label>
                        <input id="ata-amend-budget-date" type="text" readonly :value="amendmentBudgetDate || 'Não informado'">
                    </div>
                    <div class="field">
                        <label for="ata-amend-annual-index-type">Tipo_Indice_Anual</label>
                        <input id="ata-amend-annual-index-type" type="text" readonly :value="amendmentAnnualIndexType || 'Não informado'">
                    </div>
                </div>
            </div>
            <div class="ata-amend-product-notices" x-cloak x-show="editAmendmentBalanceQuantities">
                <p class="hint" x-show="savedBalanceAmendment && !savedBalanceAmendment.balance_products?.length"
                    x-text="isApostille ? 'Nenhuma quantidade foi gravada neste apostilamento.' : 'Nenhuma quantidade foi gravada neste aditivo.'"></p>
                <button type="button" class="text-link" x-show="savedBalanceAmendment?.excluded_product_ids?.length || savedBalanceAmendment?.balance_products?.length"
                    @click="showAllAmendmentProducts = !showAllAmendmentProducts" :aria-pressed="showAllAmendmentProducts">Mostrar todos os produtos</button>
                <p id="ata-amend-exclusion-notice" class="ata-amend-exclusion-notice"
                    x-text="isApostille ? 'Os itens marcados ficam fora do reajuste deste apostilamento.' : 'Os itens selecionados serão excluídos da prorrogação e não farão mais parte da ATA.'"></p>
            </div>
            <div class="product-list-scroll product-contract-list ata-amend-product-list" :class="{ 'ata-amend-list-with-exclusion-notice': editAmendmentBalanceQuantities }"
                x-ref="ataAmendProductList" tabindex="0" role="region" aria-label="Lista de produtos">
                <table class="data-table" aria-label="Produtos de referência"
                    x-effect="if (editAmendmentBalanceQuantities) $dispatch('ata-amendment-balance-input', { products: amendmentProductRows.filter(product => !amendmentExcludedProducts.includes(String(product.id))), quantities: { ...amendmentBalanceQuantities }, limitToOriginal: limitAmendmentQuantitiesToOriginal, annualIndex: isAnnualIndexAmendment ? amendmentFields.annualIndex ?? '' : null, manualPrices: { ...amendmentManualPrices } })"
                    :style="{ '--ata-amend-quantity-width': amendmentBalanceQuantityWidth + 'px' }">
                    <thead><tr><th>Item</th><th>Código</th><th>Produto</th><th>UND</th><th>QTD</th>
                        <th x-show="amendmentSpecialQuantity" x-text="amendmentSpecialQuantity"></th><th>Marca</th><th>VL. Unitário</th>
                        <th class="ata-amend-adjusted-price" x-show="isAnnualIndexAmendment">Novo_VL_Unitário</th><th class="ata-amend-line-total">VL. Total</th>
                        <th class="ata-amend-exclusion" x-show="editAmendmentBalanceQuantities" aria-label="Excluir da prorrogação"
                            :aria-label="isApostille ? 'Excluir do reajuste' : 'Excluir da prorrogação'"></th>
                    </tr></thead>
                    <tbody>
                        <template x-for="product in amendmentProducts" :key="product.id">
                            <tr data-amendment-product><td x-text="product.item"></td><td x-text="product.code"></td>
                                <td><div class="ata-product-cell-content" x-text="product.description"></div></td>
                                <td x-text="product.unit"></td>
                                <td>
                                    <template x-if="editAmendmentBalanceQuantities">
                                        <input type="text" inputmode="decimal" data-quantity data-decimal-keep-blank class="ata-amend-balance-quantity"
                                            x-model="amendmentBalanceQuantities[product.id]"
                                            :data-amendment-original-quantity="limitAmendmentQuantitiesToOriginal ? product.quantity : null"
                                            :title="'Quantidade original: ' + product.quantity"
                                            :aria-label="(limitAmendmentQuantitiesToOriginal ? 'Saldo do item ' : 'Quantidade do item ') + product.item"
                                            :disabled="!canEditAmendmentProduct(product) || (limitAmendmentQuantitiesToOriginal && !product.quantity) || amendmentExcludedProducts.includes(String(product.id))">
                                    </template>
                                    <span x-show="showAmendmentQuantities" x-text="product.quantity"></span>
                                </td>
                                <td x-show="amendmentSpecialQuantity" x-text="product.special_quantity"></td>
                                <td x-text="product.brand"></td><td x-text="product.unit_price"></td>
                                <td class="ata-amend-adjusted-price" x-show="isAnnualIndexAmendment">
                                    <input type="text" inputmode="decimal" data-money data-decimal-keep-blank
                                        :value="amendmentManualPrices[product.id] ?? amendmentAdjustedPrices[product.id] ?? ''"
                                        @input="amendmentManualPrices[product.id] = $el.value"
                                        @blur="if (!$el.value.trim()) delete amendmentManualPrices[product.id]"
                                        :aria-label="'Novo_VL_Unitário do item ' + product.item"
                                        :disabled="!isAnnualIndexAmendment || !canEditAmendmentProduct(product) || amendmentExcludedProducts.includes(String(product.id))">
                                </td>
                                <td class="ata-amend-line-total" x-text="editAmendmentBalanceQuantities ? (amendmentBalanceLineTotals[product.id] ?? '') : (showAmendmentQuantities ? product.total : '')"></td>
                                <td class="ata-amend-exclusion" x-show="editAmendmentBalanceQuantities">
                                    <input type="checkbox" :value="String(product.id)" x-model="amendmentExcludedProducts"
                                        :aria-label="'Excluir item ' + product.item + (isApostille ? ' do reajuste' : ' da prorrogação')" aria-describedby="ata-amend-exclusion-notice"
                                        :disabled="!canEditAmendmentProduct(product)">
                                </td>
                            </tr>
                        </template>
                        <tr x-show="!amendmentProducts.length">
                            <td :colspan="8 + Number(Boolean(amendmentSpecialQuantity)) + Number(editAmendmentBalanceQuantities) + Number(isAnnualIndexAmendment)"
                                x-text="savedBalanceAmendment ? 'Nenhum produto incluído.' : 'Nenhum produto original ativo nesta ATA.'"></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        @if(auth()->user()->isAdmin())
            @include('atas.signed-document-links', ['amendmentLinks' => true])
        @endif
        <div class="form-actions action-toolbar ata-amend-actions">
            <div class="contract-action-values">
                <div class="field">
                    <label for="ata-amend-value" x-text="isApostille ? 'VL_Apostilamento' : 'VL_Aditivo'">VL_Aditivo</label>
                    <input id="ata-amend-value" name="VL_Aditivo" type="text"
                        :value="amendmentDisplayedValue" readonly>
                </div>
                <div class="field">
                    <label for="ata-amend-updated-value">VL_Atualizado_ATA</label>
                    <input id="ata-amend-updated-value" name="VL_Atualizado_ATA" type="text" :value="amendmentOpen ? amendmentUpdatedTotal : ''" readonly>
                </div>
            </div>
            @if(auth()->user()->isAdmin())
                @include('atas.signed-document-control', ['signedAmendment' => true])
            @endif
            <div class="contract-document-actions ata-amend-document-actions">
                <nav aria-label="Emissão de documentos">
                    <button type="button" class="document-action" data-ata-amendment-document-url="{{ route('atas.amendments.document', ['ata' => 0, 'amendment' => 0]) }}"
                        :data-amendment-number="printableAmendment" :data-instrument="isApostille ? 'apostille' : null" disabled :disabled="!printableAmendment || busy"><x-nav-icon name="contracts"/><span x-text="isApostille ? 'Termo de Apostilamento' : 'Aditivo'">Aditivo</span></button>
                    <button type="button" class="document-action" disabled><x-nav-icon name="document-extract"/><span>Extrato</span></button>
                    <button type="button" class="document-action" disabled><x-nav-icon name="document-publication"/><span>Publicação</span></button>
                </nav>
            </div>
            <span class="product-actions-separator" role="separator" aria-orientation="vertical"></span>
            <button type="button" class="section-tab" data-ata-amendment-save :data-saved-amendment-number="savedAmendmentNumber" data-save-url="{{ route('atas.amendments.store', ['ata' => 0]) }}"
                :data-instrument="isApostille ? 'apostille' : null"
                :data-excluded-products="editAmendmentBalanceQuantities ? JSON.stringify(amendmentExcludedProducts) : null"
                :data-balance-quantities="editAmendmentBalanceQuantities ? JSON.stringify(amendmentBalanceSubmission) : null"
                disabled :disabled="!loaded || busy || publicationLocked"><x-nav-icon name="save"/>Salvar</button>
            <button type="button" class="section-tab" @click="clearAmendmentFields()" :disabled="busy || publicationLocked"><x-nav-icon name="plus"/>Novo</button>
            <button type="button" class="section-tab" data-ata-amendment-delete
                data-delete-url="{{ route('atas.amendments.destroy', ['ata' => 0, 'amendment' => 0]) }}"
                :data-amendment-number="removableAmendment" :data-instrument="isApostille ? 'apostille' : null" disabled :disabled="!removableAmendment || busy || publicationLocked"><x-nav-icon name="trash"/>Excluir</button>
        </div>
    </dialog>
    </div>
</x-layouts.app>
