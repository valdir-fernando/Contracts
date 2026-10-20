export function advanceProductVersion(field, expected, next) {
    if (field.value !== String(expected) || Number(next) !== Number(expected) + 1) return false;
    field.value = String(next);
    return true;
}

function decimalUnits(value) {
    let text = String(value ?? '').trim();
    if (text.includes(',')) {
        if (!/^(?:\d{1,3}(?:\.\d{3})+|\d+)(?:,\d{1,4})?$/.test(text)) return null;
        text = text.replaceAll('.', '').replace(',', '.');
    }
    if (!/^\d+(?:\.\d{1,4})?$/.test(text)) return null;
    const [integer, fraction = ''] = text.split('.');
    if (integer.replace(/^0+/, '').length > 14) return null;
    return BigInt(integer) * 10000n + BigInt(fraction.padEnd(4, '0'));
}

function decimalText(units) {
    return `${units / 10000n}.${String(units % 10000n).padStart(4, '0')}`;
}

export function formatProductPrice(value) {
    const units = decimalUnits(value);
    return units === null ? null : decimalText(units).replace('.', ',');
}

export function formatFichaValue(value) {
    const match = /^(-?)(\d+)(?:\.(\d{1,4}))?$/.exec(String(value ?? '').trim());
    if (!match) return '—';
    const [, sign, integer, fraction = ''] = match;
    const grouped = integer.replace(/^0+(?=\d)/, '').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return `${sign}${grouped},${fraction.padEnd(4, '0')}`;
}

export function exceedsProductBalance(quantity, remaining) {
    const requested = decimalUnits(quantity);
    const available = decimalUnits(remaining);
    return requested !== null && available !== null && requested > available;
}

export function productLineTotal(quantity, price) {
    const q = decimalUnits(quantity);
    const p = decimalUnits(price);
    if (q === null || p === null) return null;
    return decimalText((q * p + 5000n) / 10000n);
}

export function productBudgetTotals(items) {
    const sums = new Map();
    for (const item of items) {
        const label = item.Ficha || 'Sem ficha';
        const amount = decimalUnits(item.total);
        if (amount === null) continue;
        sums.set(label, (sums.get(label) ?? 0n) + amount);
    }
    return [...sums].map(([label, units]) => ({ label, total: decimalText(units) }));
}

export function synchronizeProductFichas(input, fichas) {
    const values = [...new Set(fichas.map((value) => String(value ?? '').trim()).filter((value) => value !== ''))];
    const option = (value) => {
        const node = input.ownerDocument.createElement('option');
        node.value = value;
        node.textContent = value;
        return node;
    };
    input.list.replaceChildren(...values.map(option));
}

export function initializeContractProducts(root, fetcher = fetch) {
    const form = root.closest('form');
    const version = form.elements.namedItem('version');
    const token = form.elements.namedItem('_token').value;
    const base = root.dataset.base;
    const find = (selector) => root.querySelector(selector);
    const feedback = find('[data-product-feedback]');
    const quantity = find('[data-product-quantity]');
    const unitPrice = find('[data-product-field="unit_price"]');
    const budget = find('[data-product-budget]');
    let fichas = [];
    let fichaTimer;
    const itemInput = find('[data-product-field="item"]');
    const processCatalog = find('[data-products-show]');
    const manual = find('[data-products-manual]');
    const department = form.querySelector('#department_id');
    const save = find('[data-products-save]');
    const remove = find('[data-products-delete]');
    let selected = null;
    let editing = false;
    let busy = false;
    let stale = false;
    let loading = false;
    let loadSequence = 0;
    let budgetSequence = 0;
    let budgetRequest = null;
    let itemSequence = 0;
    let itemRequest = null;
    let itemLoading = false;
    let lastItemWarning = null;
    const picker = find('[data-product-picker]');
    let pickerRequest = null;
    let pickerSequence = 0;
    let pickerPage = 1;
    let pickerLastPage = 1;
    let pickerSelected = false;
    const display = (value) => value === null || value === undefined ? '—' : String(value).replace(/^(\d+)\.(\d+)$/, '$1,$2');
    const message = (text, error = false) => {
        feedback.textContent = text;
        feedback.classList.toggle('field-error', error);
    };
    const element = (tag, text = '') => {
        const el = document.createElement(tag);
        el.textContent = text;
        return el;
    };
    const cell = (tr, value) => {
        const td = element('td');
        if (value instanceof Node) td.append(value);
        else td.textContent = value ?? '—';
        tr.append(td);
        return td;
    };
    const button = (label, action) => {
        const el = element('button', label);
        el.type = 'button';
        el.className = 'secondary-button';
        el.addEventListener('click', action);
        return el;
    };
    function controls() {
        root.setAttribute('aria-busy', String(busy || loading || itemLoading));
        save.disabled = busy || loading || itemLoading || stale || !selected;
        remove.disabled = busy || loading || itemLoading || stale || !selected || !editing;
        manual.disabled = busy || editing;
        processCatalog.disabled = busy || manual.checked;
        budget.disabled = busy || stale;
        const closeDialog = form.querySelector('[data-contract-products-close]');
        if (closeDialog) closeDialog.disabled = busy;
        find('[data-product-item-search]').disabled = busy || loading || itemLoading || stale || editing || manual.checked;
    }
    function cancelItemLookup() {
        itemSequence++;
        itemRequest?.abort();
        itemLoading = false;
    }
    function invalidateItem() {
        lastItemWarning = null;
        cancelItemLookup();
        selected = null;
        editing = false;
        find('[data-product-description]').value = '';
        root.querySelectorAll('[data-product-field]').forEach((input) => { if (input !== itemInput) input.value = ''; });
        quantity.value = '';
        preview();
        controls();
    }
    async function lookupItem() {
        if (busy || loading || stale || editing || manual.checked || picker.open) return;
        const item = itemInput.value.trim();
        const warningKey = JSON.stringify([item, processCatalog.checked, version.value]);
        if (lastItemWarning === warningKey) return;
        if (selected && String(selected.item).trim() === item) return;
        invalidateItem();
        if (!item) return;
        const sequence = itemSequence;
        const expected = version.value;
        const mode = processCatalog.checked;
        itemLoading = true;
        itemRequest = new AbortController();
        controls();
        message('Consultando item…');
        try {
            const params = new URLSearchParams({ item, process_catalog: mode ? '1' : '0' });
            const data = await request(`${base}/item-lookup?${params}`, 'GET', null, itemRequest.signal);
            if (sequence !== itemSequence || item !== itemInput.value.trim() || mode !== processCatalog.checked || expected !== version.value) return;
            itemLoading = false;
            if (data.status === 'found') {
                choose(data.product);
                message('Item carregado. Informe a quantidade a lançar.');
            } else {
                const messages = {
                    supplier_mismatch: 'Este Item não corresponde a este Fornecedor',
                    not_found: 'Item não encontrado neste processo.',
                    unavailable: 'O item não possui saldo disponível ou seus dados precisam ser conferidos no cadastro.',
                    ambiguous: 'Há mais de um registro deste item. Confira o cadastro de produtos do processo para identificar a origem do saldo.',
                };
                if (data.status === 'supplier_mismatch' || data.status === 'not_found') {
                    lastItemWarning = warningKey;
                    message('');
                    find('[data-product-item-warning-message]').textContent = messages[data.status];
                    const dialog = find('[data-product-item-warning]');
                    if (!dialog.open) dialog.showModal();
                } else message(messages[data.status] || 'Não foi possível carregar o item.', true);
            }
        } catch (error) {
            if (error.name !== 'AbortError' && sequence === itemSequence) message('Não foi possível consultar o item. Saia do campo novamente para tentar.', true);
        } finally {
            if (sequence === itemSequence) itemLoading = false;
            controls();
        }
    }
    function clearBudgetLookup() {
        budgetSequence++;
        budgetRequest?.abort();
        find('[data-product-budget-description]').value = '';
        find('[data-product-budget-allocation]').value = '';
        find('[data-product-budget-status]').textContent = '';
    }
    function renderFichaValues(rows, totals) {
        const summary = find('[data-product-budget-totals]');
        summary.replaceChildren();
        for (const row of rows) {
            const tr = element('tr');
            cell(tr, row.label);
            cell(tr, formatFichaValue(row.total));
            summary.append(tr);
        }
        find('[data-product-ficha-total]').textContent = formatFichaValue(totals?.total);
        find('[data-product-ficha-contract-total]').textContent = formatFichaValue(totals?.contract_total);
        const status = find('[data-product-ficha-status]');
        status.classList.toggle('field-error', totals?.matches !== true);
        status.textContent = !totals ? '' : totals.matches === true
            ? 'A soma das fichas confere com o valor original do contrato.'
            : totals.matches === false
                ? `A soma das fichas diverge do valor original do contrato. Diferença: ${formatFichaValue(totals.difference)}. Confira os valores cadastrados.`
                : 'Não foi possível conferir a soma das fichas. Confira os valores cadastrados.';
    }
    async function lookupBudget() {
        clearBudgetLookup();
        const ficha = budget.value.trim();
        if (!ficha) return;
        const sequence = budgetSequence;
        budgetRequest = new AbortController();
        const status = find('[data-product-budget-status]');
        status.textContent = 'Consultando ficha…';
        try {
            const params = new URLSearchParams({ ficha });
            if (department && !department.disabled) params.set('department_id', department.value);
            const data = await request(`${base}/budget-lookup?${params}`, 'GET', null, budgetRequest.signal);
            if (sequence !== budgetSequence || ficha !== budget.value.trim()) return;
            if (data.status === 'found') {
                const confirmed = data.scope_matches === true || window.confirm(`Ficha ${ficha} encontrada com Fundo ou Secretaria diferente dos selecionados.\n\nFundo da ficha: ${data.fund || 'Não informado'}\nSecretaria da ficha: ${data.department || 'Não informada'}\nDescrição: ${data.description}\nDotação: ${data.allocation}\n\nDeseja preencher os campos com estes dados?`);
                if (!confirmed) {
                    status.textContent = 'Consulta cancelada. Os campos não foram preenchidos.';
                    return;
                }
                find('[data-product-budget-description]').value = data.description;
                find('[data-product-budget-allocation]').value = data.allocation;
                status.textContent = '';
            } else status.textContent = 'Ficha não encontrada no cadastro de dotações.';
        } catch (error) {
            if (error.name === 'AbortError' || sequence !== budgetSequence) return;
            status.textContent = 'Não foi possível consultar a ficha. Saia do campo novamente para tentar.';
        }
    }
    function preview() {
        quantity.setCustomValidity(!manual.checked && !editing && selected && exceedsProductBalance(quantity.value, selected.remaining)
            ? 'A quantidade excede o saldo disponível.' : '');
        const total = selected ? productLineTotal(quantity.value, unitPrice.value) : '0.0000';
        find('[data-product-line-total]').value = total === null ? '' : display(total);
        resizeDescription();
    }
    function resizeDescription() {
        const description = find('[data-product-description]');
        description.style.height = 'auto';
        if (description.getClientRects().length) description.style.height = `${description.scrollHeight + 2}px`;
    }
    function choose(product, isEditing = false) {
        if (busy || stale || loading) return;
        cancelItemLookup();
        manual.checked = isEditing && product.product_id === null;
        manualFields();
        const previousMode = processCatalog.checked;
        if (isEditing) processCatalog.checked = Boolean(product.process_catalog);
        itemInput.readOnly = isEditing;
        selected = product;
        editing = isEditing;
        find('[data-product-description]').value = product.description ?? '';
        root.querySelectorAll('[data-product-field]').forEach((input) => {
            const field = input.dataset.productField;
            input.value = ['unit_price', 'disp'].includes(field) ? formatProductPrice(product[field]) ?? '' : product[field] ?? '';
        });
        quantity.value = isEditing ? display(product.quantity) : '';
        const previousFicha = budget.value;
        if (isEditing) budget.value = (product.Ficha ?? '').trim();
        find('[data-product-balance]').textContent = isEditing ? 'Alterando um produto já lançado. A diferença da quantidade ajustará o saldo do processo.' : '';
        if (isEditing) lookupBudget();
        preview();
        controls();
        quantity.focus({ preventScroll: true });
        if (previousMode !== processCatalog.checked || previousFicha !== budget.value) refresh();
    }
    function clear() {
        if (busy) return;
        cancelItemLookup();
        itemInput.readOnly = false;
        selected = null;
        editing = false;
        manual.checked = false;
        manualFields();
        find('[data-product-description]').value = '';
        root.querySelectorAll('[data-product-field]').forEach((input) => { input.value = ''; });
        quantity.value = '';
        clearBudgetLookup();
        if (budget.value) lookupBudget();
        preview();
        find('[data-product-balance]').textContent = 'Informe o Item para um novo lançamento.';
        controls();
    }
    function manualFields() {
        unitPrice.readOnly = !manual.checked;
        ['code', 'brand', 'unit'].forEach((field) => { find(`[data-product-field="${field}"]`).readOnly = !manual.checked; });
        const description = find('[data-product-description]');
        description.readOnly = !manual.checked;
        description.setAttribute('aria-readonly', String(!manual.checked));
    }
    async function loadPicker() {
        pickerRequest?.abort();
        pickerRequest = new AbortController();
        const sequence = ++pickerSequence;
        const expected = version.value;
        const mode = processCatalog.checked;
        const rows = find('[data-product-picker-rows]');
        const status = find('[data-product-picker-status]');
        rows.replaceChildren();
        status.textContent = 'Carregando produtos…';
        picker.setAttribute('aria-busy', 'true');
        find('[data-product-picker-previous]').disabled = true;
        find('[data-product-picker-next]').disabled = true;
        try {
            const data = await request(`${base}?page=${pickerPage}&process_catalog=${mode ? '1' : '0'}`, 'GET', null, pickerRequest.signal);
            if (!picker.open || sequence !== pickerSequence || expected !== version.value || mode !== processCatalog.checked) return;
            if (String(data.contract_version) !== expected) {
                stale = true;
                throw new Error('O contrato mudou. Recarregue a página antes de continuar.');
            }
            for (const product of data.products) {
                const tr = element('tr');
                const select = () => {
                    if (busy || loading || stale || expected !== version.value || mode !== processCatalog.checked) return;
                    choose(product);
                    pickerSelected = true;
                    picker.close();
                };
                const item = button(product.item, select);
                item.disabled = !product.available;
                cell(tr, item);
                [product.code, product.description, product.brand, product.unit, display(product.remaining), formatProductPrice(product.unit_price) ?? '—'].forEach((value) => cell(tr, value));
                if (product.available) {
                    tr.dataset.selectable = '';
                    tr.addEventListener('click', (event) => { if (!event.target.closest('button')) select(); });
                } else item.title = 'Produto indisponível: confira saldo e dados do cadastro.';
                rows.append(tr);
            }
            pickerPage = data.page;
            pickerLastPage = data.last_page;
            find('[data-product-picker-page]').textContent = `Página ${pickerPage} de ${pickerLastPage}`;
            find('[data-product-picker-previous]').disabled = pickerPage <= 1;
            find('[data-product-picker-next]').disabled = pickerPage >= pickerLastPage;
            status.textContent = data.products.length ? 'Clique no item para carregá-lo no cadastro.' : 'Nenhum produto encontrado.';
        } catch (error) {
            if (error.name !== 'AbortError' && sequence === pickerSequence && picker.open) status.textContent = error.message;
        } finally {
            if (sequence === pickerSequence) picker.setAttribute('aria-busy', 'false');
            controls();
        }
    }
    function openPicker() {
        if (busy || loading || stale || editing || manual.checked) return;
        cancelItemLookup();
        controls();
        pickerPage = 1;
        pickerSelected = false;
        find('[data-product-picker-page]').textContent = '';
        find('[data-product-picker-context]').textContent = processCatalog.checked
            ? 'Produtos do processo de todos os fornecedores.' : 'Produtos do fornecedor deste contrato no processo.';
        if (!picker.open) picker.showModal();
        loadPicker();
    }
    async function request(url, method = 'GET', data = null, signal = undefined) {
        const options = { method, signal, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token } };
        if (data) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(data);
        }
        const response = await fetcher(url, options);
        const result = await response.json();
        if (!response.ok) {
            if ([401, 403, 409, 419].includes(response.status)) stale = true;
            throw new Error(response.status === 409 ? 'O contrato mudou. Recarregue a página antes de continuar.'
                : Object.values(result.errors ?? {}).flat().join(' ') || result.message || 'Não foi possível concluir a operação.');
        }
        return result;
    }
    function updateTotal(label) {
        document.getElementById('contract-items-total').value = label;
        find('[data-product-contract-total]').value = label;
        const headingTotal = find('[data-product-heading-total]');
        if (headingTotal) headingTotal.textContent = label;
        const financialTotal = document.getElementById('valor_total');
        if (financialTotal) financialTotal.textContent = label;
    }
    async function load() {
        const sequence = ++loadSequence;
        const expected = version.value;
        loading = true;
        controls();
        try {
            const mode = processCatalog.checked;
            const ficha = budget.value.trim();
            const params = new URLSearchParams({ process_catalog: mode ? '1' : '0' });
            if (ficha !== '') params.set('ficha', ficha);
            const data = await request(`${base}?${params}`);
            if (sequence !== loadSequence || expected !== version.value || mode !== processCatalog.checked || ficha !== budget.value.trim()) return;
            if (String(data.contract_version) !== version.value) {
                stale = true;
                message('O contrato mudou. Recarregue a página antes de alterar os produtos.', true);
                return;
            }
            fichas = data.fichas ?? [];
            synchronizeProductFichas(budget, fichas);
            updateTotal(data.total_label);
            renderFichaValues(data.ficha_values ?? [], data.ficha_summary);
            const list = find('[data-contract-product-list]');
            list.replaceChildren();
            for (const item of data.items) {
                const tr = element('tr');
                [item.item, item.code, item.description, item.unit, display(item.quantity), item.brand, display(item.unit_price), display(item.total), item.Ficha || 'Sem ficha'].forEach((value) => cell(tr, value));
                cell(tr, button('Editar', () => choose(item, true)));
                list.append(tr);
            }
        } finally {
            if (sequence === loadSequence) loading = false;
            controls();
        }
    }
    async function mutate(isRemoval = false) {
        if (busy || loading || itemLoading || stale || !selected) return;
        if (!isRemoval) {
            preview();
            if (!quantity.reportValidity()) return;
        }
        if (isRemoval && (!editing || !window.confirm('Excluir este lançamento, devolver a quantidade ao saldo e recalcular o valor do contrato?'))) return;
        busy = true;
        clearTimeout(fichaTimer);
        controls();
        const expected = version.value;
        const data = { contract_version: expected };
        if (!isRemoval) {
            data.quantity = quantity.value;
            data.unit_price = unitPrice.value;
            data.ficha = budget.value;
            data.process_catalog = processCatalog.checked;
            data.manual = manual.checked;
            if (manual.checked) {
                data.item = itemInput.value.trim() || 'MANUAL';
                data.description = find('[data-product-description]').value;
                ['code', 'brand', 'unit'].forEach((field) => { data[field] = find(`[data-product-field="${field}"]`).value; });
            } else if (!editing) data.product_id = selected.id;
        }
        message('Salvando produto…');
        let committed = false;
        try {
            const result = await request(editing ? `${base}/${selected.id}` : base, isRemoval ? 'DELETE' : editing ? 'PUT' : 'POST', data);
            committed = true;
            if (!advanceProductVersion(version, expected, result.contract_version)) {
                stale = true;
                throw new Error('Os dados da página mudaram. Recarregue para conferir o resultado.');
            }
            updateTotal(result.total_label);
            busy = false;
            clear();
            busy = true;
            await load();
            if (isRemoval && budget.value.trim() !== '' && !fichas.includes(budget.value.trim())) {
                budget.value = '';
                clearBudgetLookup();
                await load();
            }
            message(isRemoval ? 'Produto excluído e quantidade devolvida ao saldo.' : 'Produto salvo. Valor do contrato atualizado.');
        } catch (error) {
            if (committed || error instanceof TypeError || error instanceof SyntaxError) stale = true;
            message(error.message + (stale ? ' Atualize a página para conferir os dados salvos.' : ''), true);
        } finally {
            busy = false;
            controls();
        }
    }
    const refresh = () => { if (!busy) load().catch((e) => message(e.message, true)); };
    form.querySelector('[data-contract-products-open]')?.addEventListener('click', refresh);
    manual.addEventListener('change', () => {
        const enabled = manual.checked;
        invalidateItem();
        itemInput.value = '';
        itemInput.readOnly = false;
        manual.checked = enabled;
        manualFields();
        if (enabled) {
            selected = { id: null };
            quantity.value = '1,0000';
            unitPrice.value = '0,0000';
            find('[data-product-balance]').textContent = '';
        }
        preview();
        controls();
    });
    find('[data-product-item-warning-ok]').addEventListener('click', () => {
        itemInput.value = '';
        invalidateItem();
        find('[data-product-item-warning]').close();
    });
    find('[data-product-item-warning]').addEventListener('close', () => itemInput.focus({ preventScroll: true }));
    find('[data-products-save]').addEventListener('click', () => mutate());
    find('[data-products-delete]').addEventListener('click', () => mutate(true));
    find('[data-products-new]').addEventListener('click', () => {
        clear();
        itemInput.focus({ preventScroll: true });
    });
    processCatalog.addEventListener('change', () => {
        if (busy) return;
        const item = editing ? '' : itemInput.value;
        invalidateItem();
        itemInput.readOnly = false;
        itemInput.value = item;
        load().then(() => { if (item.trim()) lookupItem(); }).catch((error) => message(error.message, true));
    });
    quantity.addEventListener('input', preview);
    find('[data-product-description]').addEventListener('input', resizeDescription);
    new ResizeObserver(resizeDescription).observe(find('[data-product-description]').parentElement);
    unitPrice.addEventListener('input', preview);
    unitPrice.addEventListener('blur', () => {
        const formatted = formatProductPrice(unitPrice.value);
        if (formatted !== null) unitPrice.value = formatted;
        preview();
    });
    itemInput.addEventListener('input', () => { if (!manual.checked) invalidateItem(); });
    itemInput.addEventListener('blur', (event) => { if (event.relatedTarget !== find('[data-product-item-search]')) lookupItem(); });
    find('[data-product-item-search]').addEventListener('click', openPicker);
    find('[data-product-picker-cancel]').addEventListener('click', () => picker.close());
    picker.addEventListener('close', () => {
        pickerSequence++;
        pickerRequest?.abort();
        (pickerSelected ? quantity : find('[data-product-item-search]')).focus({ preventScroll: true });
    });
    find('[data-product-picker-previous]').addEventListener('click', () => { if (pickerPage > 1) { pickerPage--; loadPicker(); } });
    find('[data-product-picker-next]').addEventListener('click', () => { if (pickerPage < pickerLastPage) { pickerPage++; loadPicker(); } });
    const refreshFicha = () => {
        if (busy || stale) return;
        clearTimeout(fichaTimer);
        lookupBudget();
        refresh();
    };
    budget.addEventListener('input', () => {
        clearTimeout(fichaTimer);
        clearBudgetLookup();
        loadSequence++;
        find('[data-contract-product-list]').replaceChildren();
        fichaTimer = setTimeout(refreshFicha, 200);
    });
    budget.addEventListener('change', refreshFicha);
    department?.addEventListener('change', lookupBudget);
    form.addEventListener('submit', (event) => {
        if (busy || stale) {
            event.preventDefault();
            event.stopImmediatePropagation();
            message(busy ? 'Aguarde o lançamento do produto terminar antes de salvar o contrato.' : 'Recarregue a página antes de salvar o contrato.', true);
        }
    }, true);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
    refresh();
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-contract-products-open]').forEach((opener) => {
        const dialog = opener.closest('form').querySelector('[data-contract-products-dialog]');
        const close = dialog.querySelector('[data-contract-products-close]');
        opener.addEventListener('click', () => {
            if (!dialog.open) dialog.showModal();
            dialog.querySelector('[data-product-budget]')?.focus({ preventScroll: true });
        });
        close.addEventListener('click', () => dialog.close());
        dialog.addEventListener('cancel', (event) => { if (close.disabled) event.preventDefault(); });
        dialog.addEventListener('close', () => opener.focus({ preventScroll: true }));
    });
    document.querySelectorAll('[data-contract-products]').forEach((root) => initializeContractProducts(root));
}
