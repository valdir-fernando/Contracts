const normalize = (value) => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('pt-BR').trim();

export function formatDfdQuantity(value) {
    const text = value.trim();
    if (!text) return '';
    const match = /^(\d+|\d{1,3}(?:\.\d{3})+)(?:,(\d{0,3}))?$/.exec(text);
    if (!match) return null;
    const integer = match[1].replace(/\./g, '').replace(/^0+(?=\d)/, '');
    return `${integer.replace(/\B(?=(\d{3})+(?!\d))/g, '.')},${(match[2] ?? '').padEnd(3, '0')}`;
}

export function initializeDfdProductFilter(editor) {
    const query = editor.querySelector('[data-dfd-product-query]');
    if (!query) return;
    query.addEventListener('input', () => {
        const start = query.selectionStart;
        const end = query.selectionEnd;
        query.value = query.value.toLocaleUpperCase('pt-BR');
        if (start !== null && end !== null) query.setSelectionRange(start, end);
    });
    initializeDfdProductLaunch(editor);
    const manualDialog = editor.querySelector('[data-dfd-manual-dialog]');
    const manualForm = editor.querySelector('[data-dfd-manual-form]');
    editor.querySelector('[data-dfd-manual-open]')?.addEventListener('click', () => manualDialog?.showModal());
    editor.querySelector('[data-dfd-manual-close]')?.addEventListener('click', () => manualDialog?.close());
    const manualSuccess = editor.querySelector('[data-dfd-manual-success]');
    editor.querySelector('[data-dfd-manual-success-ok]')?.addEventListener('click', () => manualSuccess?.close());
    const manualQuantity = manualForm?.querySelector('[name="quantity"]');
    editor.querySelector('[data-dfd-delete-selected]')?.addEventListener('click', async (event) => {
        event.preventDefault();
        const selected = [...editor.querySelectorAll('[data-dfd-linked-select]:checked')];
        if (!selected.length) { window.alert('Selecione ao menos um item para excluir.'); return; }
        if (!window.confirm(`Excluir ${selected.length} item(ns) selecionado(s) da DFD?`)) return;
        const link = event.currentTarget;
        link.setAttribute('aria-disabled', 'true');
        let version = Number(editor.querySelector('[data-dfd-product-launch] input[name="version"]')?.value);
        try {
            for (const checkbox of selected) {
                const body = new FormData(); body.append('_token', manualForm.querySelector('[name="_token"]').value); body.append('version', String(version)); body.append('confirmation', '1');
                const response = await fetch(checkbox.dataset.deleteUrl, { method: 'DELETE', body, headers: { Accept: 'application/json' }, cache: 'no-store' });
                const result = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(result.message || Object.values(result.errors ?? {}).flat().join(' ') || 'Não foi possível excluir os itens.');
                version = Number(result.version);
                checkbox.closest('tr').remove();
                editor.querySelectorAll('.dfd-products-linked tbody tr:not(:has(.empty-state))').forEach((row, index) => {
                    if (row.cells[1]) row.cells[1].textContent = String(index + 1);
                });
            }
            editor.querySelectorAll('input[name="version"]').forEach((input) => { input.value = String(version); });
            if (manualForm) manualForm.dataset.version = String(version);
        } catch (error) { window.alert(error.message); window.location.reload(); }
        finally { link.removeAttribute('aria-disabled'); }
    });
    manualForm?.querySelectorAll('[name="description"], [name="unit"]').forEach((input) => {
        input.addEventListener('input', () => {
            const start = input.selectionStart;
            const end = input.selectionEnd;
            input.value = input.value.toLocaleUpperCase('pt-BR');
            if (start !== null && end !== null) input.setSelectionRange(start, end);
        });
    });
    manualQuantity?.addEventListener('blur', () => {
        const text = manualQuantity.value.trim();
        if (!text) return;
        const match = /^(\d+|\d{1,3}(?:\.\d{3})+)(?:,(\d{0,4}))?$/.exec(text);
        if (!match) { manualQuantity.setCustomValidity('Use milhar com ponto e até quatro casas decimais com vírgula.'); manualQuantity.reportValidity(); return; }
        const integer = match[1].replace(/\./g, '').replace(/^0+(?=\d)/, '');
        manualQuantity.value = `${integer.replace(/\B(?=(\d{3})+(?!\d))/g, '.')},${(match[2] ?? '').padEnd(4, '0')}`;
        manualQuantity.setCustomValidity('');
    });
    manualQuantity?.addEventListener('input', () => manualQuantity.setCustomValidity(''));
    manualForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const status = manualForm.querySelector('[data-dfd-manual-status]');
        status.hidden = false; status.textContent = 'Salvando produto…';
        const body = new FormData(manualForm); body.append('version', manualForm.dataset.version);
        try {
            const response = await fetch(manualForm.dataset.saveUrl, { method: 'POST', body, headers: { Accept: 'application/json' }, cache: 'no-store' });
            const result = await response.json();
            if (!response.ok) throw new Error(Object.values(result.errors ?? {}).flat().join(' ') || result.message || 'Não foi possível salvar.');
            const row = document.createElement('tr');
            row.className = 'dfd-manual-linked-item';
            const selectCell = document.createElement('td');
            const selectItem = document.createElement('input');
            selectItem.type = 'checkbox';
            selectItem.setAttribute('aria-label', `Selecionar item ${result.item.ITEM}`);
            selectItem.setAttribute('data-dfd-linked-select', '');
            selectItem.dataset.deleteUrl = result.delete_url;
            selectCell.append(selectItem);
            row.append(selectCell);
            [result.item.ITEM, result.item.COD_PROD, result.item.PROD_SIMPLES, result.item.UND, result.item.QTD]
                .forEach((value, index) => {
                    const cell = document.createElement('td');
                    cell.textContent = value == null ? '' : String(value).replace('.', ',');
                    if (index === 2 && result.item.CARACT) {
                        const detail = document.createElement('p'); detail.textContent = result.item.CARACT; cell.append(detail);
                    }
                    row.append(cell);
                });
            const linkedBody = editor.querySelector('.dfd-products-linked tbody');
            linkedBody.querySelector('.empty-state')?.closest('tr')?.remove();
            linkedBody.append(row);
            editor.querySelectorAll('input[name="version"]').forEach((input) => { input.value = result.version; });
            manualForm.dataset.version = result.version;
            manualSuccess.querySelector('[data-dfd-manual-success-message]').textContent = 'Produto salvo na DFD. Você pode cadastrar outro.';
            manualSuccess.showModal();
            manualForm.querySelector('[name="description"]').value = '';
            manualForm.querySelector('[name="unit"]').value = '';
            manualQuantity.value = '';
            manualForm.querySelector('[name="description"]').focus();
        } catch (error) { status.textContent = error.message; }
    });
    const catalogList = editor.querySelector('.dfd-products-catalog');
    const linkedList = editor.querySelector('.dfd-products-linked');
    if (catalogList && linkedList && typeof ResizeObserver !== 'undefined') {
        const observer = new ResizeObserver(() => {
            linkedList.style.height = `${catalogList.getBoundingClientRect().height}px`;
        });
        observer.observe(catalogList);
    }
    editor.querySelectorAll('[data-dfd-product-quantity]').forEach((input) => {
        input.addEventListener('focus', () => {
            if (input.value.trim() === '0,000') input.value = '';
        });
        input.addEventListener('input', () => input.setCustomValidity(''));
        input.addEventListener('blur', () => {
            const formatted = formatDfdQuantity(input.value);
            input.setCustomValidity(formatted === null ? 'Informe uma quantidade com até três casas decimais.' : '');
            if (formatted !== null) input.value = formatted;
            else input.reportValidity();
            if (!input.value.trim()) input.value = '0,000';
        });
    });
    const rows = [...editor.querySelectorAll('[data-dfd-product-row]')];
    const empty = editor.querySelector('[data-dfd-product-empty]');
    const total = editor.querySelector('[data-dfd-product-total]');
    let appliedQuery = normalize(query.value);
    let selectedRow = rows.find((row) => row.querySelector('input[type="checkbox"]').checked) ?? null;
    const render = () => {
        let visible = 0;
        let selected = 0;
        for (const row of rows) {
            const checkbox = row.querySelector('input[type="checkbox"]');
            const checked = row === selectedRow;
            checkbox.checked = checked;
            checkbox.disabled = selectedRow !== null && !checked;
            row.classList.toggle('dfd-product-selected', checked);
            const quantity = row.querySelector('[data-dfd-product-quantity]');
            const launch = row.querySelector('[data-dfd-product-launch-button]');
            if (launch) launch.disabled = !checked;
            if (quantity) {
                quantity.disabled = !checked;
                if (!checked) {
                    quantity.value = '0,000';
                    quantity.setCustomValidity('');
                }
            }
            if (checked) selected++;
            row.hidden = !checked && !normalize(row.dataset.productName).includes(appliedQuery)
                && !normalize(row.dataset.productCode ?? '').includes(appliedQuery);
            if (!row.hidden) visible++;
        }
        if (empty) {
            empty.textContent = query.value.trim()
                ? `O produto “${query.value.trim()}” não foi encontrado.`
                : 'Nenhum produto corresponde à pesquisa.';
            empty.hidden = visible > 0 || rows.length === 0;
        }
        if (total) {
            total.textContent = `Total de Itens Selecionados: ${selected}`;
            total.hidden = selected === 0;
        }
    };
    query.addEventListener('blur', () => {
        const requested = normalize(query.value);
        if (requested && !rows.some((row) => normalize(row.dataset.productName).includes(requested)
            || normalize(row.dataset.productCode ?? '').includes(requested))) {
            appliedQuery = '';
            render();
            window.alert(`O produto “${query.value.trim()}” não foi encontrado.`);
            return;
        }
        appliedQuery = requested;
        render();
    });
    query.addEventListener('input', () => {
        if (!query.value.trim()) {
            appliedQuery = '';
            render();
        }
    });
    rows.forEach((row) => row.querySelector('input[type="checkbox"]').addEventListener('change', () => {
        const checkbox = row.querySelector('input[type="checkbox"]');
        if (row === selectedRow && !checkbox.checked) selectedRow = null;
        else if (selectedRow === null && checkbox.checked) selectedRow = row;
        render();
    }));
    render();
}

export function initializeDfdProductLaunch(editor, fetcher = fetch) {
    let saving = false;
    editor.querySelectorAll('[data-dfd-product-launch]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (saving) return;
            const quantity = form.querySelector('[data-dfd-product-quantity]');
            const formatted = formatDfdQuantity(quantity.value);
            if (quantity.disabled) return;
            if (!formatted || /^0,000$/.test(formatted)) {
                quantity.setCustomValidity('Informe uma quantidade positiva com até três casas decimais.');
                quantity.reportValidity();
                return;
            }
            quantity.value = formatted;
            const status = editor.querySelector('[data-dfd-launch-status]');
            status.hidden = false;
            status.textContent = 'Lançando produto…';
            saving = true;
            try {
                const response = await fetcher(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' }, cache: 'no-store' });
                if (!response.ok) {
                    const error = await response.json().catch(() => ({}));
                    throw new Error(Object.values(error.errors ?? {}).flat().join(' ') || error.message || 'Não foi possível lançar o produto.');
                }
                const page = new DOMParser().parseFromString(await response.text(), 'text/html');
                const linked = page.querySelector('.dfd-products-linked');
                const version = page.querySelector('[data-dfd-product-launch] input[name="version"]');
                if (!linked || !version) throw new Error('A sessão expirou. Recarregue a página para continuar.');
                editor.querySelector('.dfd-products-linked').replaceChildren(...linked.childNodes);
                editor.querySelectorAll('input[name="version"]').forEach((input) => { input.value = version.value; });
                const manualForm = editor.querySelector('[data-dfd-manual-form]');
                if (manualForm) manualForm.dataset.version = version.value;
                status.textContent = 'Produto lançado na DFD.';
            } catch (error) {
                status.textContent = error.message;
            } finally {
                saving = false;
            }
        });
    });
}
