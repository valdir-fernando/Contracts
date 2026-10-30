import { initializeDfdProductFilter } from './dfd-product-filter.js';

export function initializeDfdPanel(editor, fetcher = fetch, navigate = (url) => location.assign(url)) {
    initializeDfdProductFilter(editor);
    const dialog = editor.querySelector('[data-dfd-search-dialog]');
    const form = dialog.querySelector('[data-dfd-search-form]');
    const status = dialog.querySelector('[data-dfd-search-status]');
    const results = dialog.querySelector('[data-dfd-search-results]');
    const opener = editor.querySelector('[data-dfd-search-open]');
    const number = editor.querySelector('[data-dfd-number-query]');
    const newButton = editor.querySelector('[data-dfd-new-open]');
    const newDialog = editor.querySelector('[data-dfd-new-dialog]');
    const productSearch = editor.querySelector('.dfd-product-search');
    let request;
    let restoringFocus = false;
    const search = async () => {
        request?.abort();
        const current = new AbortController();
        request = current;
        results.replaceChildren();
        status.textContent = 'Pesquisando DFDs…';
        results.setAttribute('aria-busy', 'true');
        const url = new URL(dialog.dataset.searchUrl, editor.ownerDocument.baseURI);
        url.searchParams.set('q', form.elements.q.value.trim());
        try {
            const response = await fetcher(url.href, { headers: { Accept: 'application/json' }, cache: 'no-store', signal: current.signal });
            if (!response.ok || response.redirected) throw new Error('search');
            const data = await response.json();
            if (!Array.isArray(data.records)) throw new Error('search');
            if (request !== current || current.signal.aborted || !dialog.open) return;
            for (const record of data.records) {
                const target = new URL(record.url, url);
                if (target.origin !== url.origin || !/\/dfd-compra\/\d+$/.test(target.pathname)) throw new Error('url');
                if (url.searchParams.get('q') && data.records.length === 1) {
                    status.textContent = 'Carregando os dados da DFD…';
                    navigate(target.href);
                    return;
                }
                const row = results.insertRow();
                const link = editor.ownerDocument.createElement('a');
                link.className = 'text-link';
                link.href = target.href;
                link.textContent = record.number;
                row.insertCell().append(link);
                const date = /^\d{4}-\d{2}-\d{2}$/.test(record.date ?? '') ? record.date.split('-').reverse().join('/') : '—';
                for (const text of [date, record.process || '—', record.category]) row.insertCell().textContent = text;
            }
            status.textContent = data.records.length ? `${data.records.length} DFDs encontradas. Clique no número para abrir.` : 'Nenhuma DFD encontrada.';
        } catch {
            if (request !== current || current.signal.aborted || !dialog.open) return;
            results.replaceChildren();
            status.textContent = 'Não foi possível pesquisar. Tente novamente.';
        } finally {
            if (request === current) results.setAttribute('aria-busy', 'false');
        }
    };
    const openSearch = () => {
        if (dialog.open) return;
        form.elements.q.value = number.value.trim();
        dialog.showModal();
        form.elements.q.focus();
        search();
    };
    // Keep the number focused until the click, avoiding a duplicate blur lookup.
    opener.addEventListener('mousedown', (event) => event.preventDefault());
    opener.addEventListener('click', openSearch);
    number.addEventListener('blur', () => {
        if (!restoringFocus && number.value.trim()) openSearch();
    });
    form.addEventListener('submit', (event) => { event.preventDefault(); search(); });
    dialog.querySelector('[data-dfd-search-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {
        request?.abort();
        restoringFocus = true;
        opener.focus();
        restoringFocus = false;
    });

    const budget = editor.querySelector('[data-dfd-budget-select]');
    if (budget) {
        const update = () => {
            const data = JSON.parse(budget.selectedOptions[0]?.dataset.budget || '{}');
            editor.querySelectorAll('[data-dfd-budget-field]').forEach((input) => { input.value = data[input.dataset.dfdBudgetField] ?? ''; });
        };
        budget.addEventListener('change', update);
        update();
    }

    if (newButton && newDialog) {
        const cancelButtons = newDialog.querySelectorAll('[data-dfd-new-cancel]');
        newButton.addEventListener('click', () => newDialog.showModal());
        cancelButtons.forEach((button) => button.addEventListener('click', () => newDialog.close()));
        newDialog.querySelector('[data-dfd-new-same]').addEventListener('click', () => navigate(newDialog.dataset.sameClassUrl));
        newDialog.querySelector('[data-dfd-new-change]').addEventListener('click', () => {
            newDialog.close();
            editor.ownerDocument.querySelector('[data-dfd-class-open]')?.click();
        });
        newDialog.addEventListener('close', () => newButton.focus());
    }

    if (productSearch) {
        const query = productSearch.querySelector('input[name="q"]');
        let searchTimer;
        query.addEventListener('input', () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => productSearch.requestSubmit(), 350);
        });
        query.addEventListener('blur', () => {
            clearTimeout(searchTimer);
            productSearch.requestSubmit();
        });
        query.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') clearTimeout(searchTimer);
        });
        productSearch.addEventListener('submit', () => clearTimeout(searchTimer));
    }
}

if (typeof document !== 'undefined') document.querySelectorAll('[data-dfd-editor]').forEach((editor) => initializeDfdPanel(editor));
