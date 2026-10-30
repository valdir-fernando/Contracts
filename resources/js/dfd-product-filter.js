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
    initializeDfdProductLaunch(editor);
    const catalogList = editor.querySelector('.dfd-products-catalog');
    const linkedList = editor.querySelector('.dfd-products-linked');
    if (catalogList && linkedList && typeof ResizeObserver !== 'undefined') {
        const observer = new ResizeObserver(() => {
            linkedList.style.height = `${catalogList.getBoundingClientRect().height}px`;
        });
        observer.observe(catalogList);
    }
    editor.querySelectorAll('[data-dfd-product-quantity]').forEach((input) => {
        input.addEventListener('input', () => input.setCustomValidity(''));
        input.addEventListener('blur', () => {
            const formatted = formatDfdQuantity(input.value);
            input.setCustomValidity(formatted === null ? 'Informe uma quantidade com até três casas decimais.' : '');
            if (formatted !== null) input.value = formatted;
            else input.reportValidity();
        });
    });
    const rows = [...editor.querySelectorAll('[data-dfd-product-row]')];
    const empty = editor.querySelector('[data-dfd-product-empty]');
    const total = editor.querySelector('[data-dfd-product-total]');
    let appliedQuery = normalize(query.value);
    const render = () => {
        let visible = 0;
        let selected = 0;
        for (const row of rows) {
            const checked = row.querySelector('input[type="checkbox"]').checked;
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
            row.hidden = !checked
                && !normalize(row.dataset.productName).includes(appliedQuery);
            if (!row.hidden) visible++;
        }
        if (empty) empty.hidden = visible > 0 || rows.length === 0;
        if (total) {
            total.textContent = `Total de Itens Selecionados: ${selected}`;
            total.hidden = selected === 0;
        }
    };
    query.addEventListener('blur', () => {
        appliedQuery = normalize(query.value);
        render();
    });
    query.addEventListener('input', () => {
        if (!query.value.trim()) {
            appliedQuery = '';
            render();
        }
    });
    rows.forEach((row) => row.querySelector('input[type="checkbox"]').addEventListener('change', render));
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
                status.textContent = 'Produto lançado na DFD.';
            } catch (error) {
                status.textContent = error.message;
            } finally {
                saving = false;
            }
        });
    });
}
