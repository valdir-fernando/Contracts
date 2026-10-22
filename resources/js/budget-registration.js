export function composeBudgetCode(fields) {
    return ['Gestao', 'Unidade', 'Funcao', 'SubFunc', 'Programa', 'Projeto', 'Codigo'].map((key) => fields[key] ?? '').join('.')
        + `(${fields.Fonte ?? ''})${fields.Ficha ?? ''}`;
}

export function initializeBudgetDepartment(panel, fetcher = fetch) {
    const fund = panel.querySelector('[data-budget-fund]');
    const department = panel.querySelector('[data-budget-department]');
    const filter = panel.querySelector('[data-budget-department-filter]');
    const status = panel.querySelector('[data-budget-filter-status]');
    const list = panel.querySelector('[data-budget-list]');
    let active = new URL(window.location.href).searchParams.has('filter_department_id');
    let sequence = 0;
    let request;
    const load = async () => {
        const current = ++sequence;
        request?.abort();
        request = new AbortController();
        const url = new URL(panel.dataset.budgetListUrl, window.location.href);
        if (active) {
            url.searchParams.set('filter_fund_id', fund.value);
            url.searchParams.set('filter_department_id', department.value);
        }
        status.textContent = 'Carregando dotações…';
        try {
            const response = await fetcher(url, { headers: { Accept: 'application/json' }, signal: request.signal });
            if (!response.ok) throw new Error('Não foi possível filtrar as dotações. Confira suas permissões e tente novamente.');
            const data = await response.json();
            if (sequence !== current) return;
            list.innerHTML = data.html;
            status.textContent = active ? 'Exibindo somente as dotações da Secretaria selecionada. Clique no filtro para exibir todas.' : '';
        } catch (error) {
            if (error.name !== 'AbortError' && sequence === current) status.textContent = error.message;
        }
    };
    const synchronize = () => {
        department.disabled = fund.selectedOptions[0]?.dataset.municipal !== 'true';
        if (department.disabled) department.value = '';
        filter.disabled = department.disabled || !department.value;
    };
    synchronize();
    filter.setAttribute('aria-pressed', String(active));
    fund.addEventListener('change', () => {
        department.value = '';
        synchronize();
        filter.setAttribute('aria-pressed', 'false');
        if (active) { active = false; load(); }
    });
    department.addEventListener('change', () => {
        synchronize();
        if (active) {
            active = !filter.disabled;
            filter.setAttribute('aria-pressed', String(active));
            load();
        }
    });
    filter.addEventListener('click', () => {
        if (filter.disabled) return;
        active = !active;
        filter.setAttribute('aria-pressed', String(active));
        load();
    });
}

export async function loadBudgetPanel(panel, url, fetcher, signal, isCurrent) {
    const selectedFund = panel.querySelector('[data-budget-fund]')?.value;
    const response = await fetcher(url, { headers: { Accept: 'text/html' }, signal });
    if (!response.ok) throw new Error('Não foi possível carregar os dados da ficha.');
    const html = await response.text();
    if (!isCurrent()) return;
    const page = new DOMParser().parseFromString(html, 'text/html');
    const replacement = page.querySelector('[data-budget-registration]');
    if (!replacement) throw new Error('Não foi possível carregar os dados da ficha. Atualize a página e tente novamente.');
    // Loading an existing record is a read, even if the session still has a save notice.
    replacement.removeAttribute('data-budget-saved');
    panel.replaceWith(replacement);
    initializeBudgetRegistration(replacement);
    const recordFund = replacement.querySelector('[data-budget-fund]').value;
    if (selectedFund && selectedFund !== recordFund) {
        replacement.querySelector('[data-budget-scope-warning]').showModal();
    } else replacement.querySelector('[data-budget-part="Orgao"]')?.focus({ preventScroll: true });
}

export function initializeBudgetLookup(panel, fetcher = fetch, navigate = (url, signal, isCurrent) => loadBudgetPanel(panel, url, fetcher, signal, isCurrent)) {
    const ficha = panel.querySelector('[data-budget-part="Ficha"]');
    const form = panel.querySelector('#budget-registration-form');
    const save = form.querySelector('[type="submit"]');
    const status = panel.querySelector('[data-budget-lookup-status]');
    let sequence = 0;
    let request;
    let pending = false;
    ficha.addEventListener('input', () => {
        sequence++;
        request?.abort();
        pending = false;
        save.disabled = false;
        status.textContent = '';
    });
    form.addEventListener('submit', (event) => { if (pending) event.preventDefault(); });
    ficha.addEventListener('blur', async () => {
        const value = ficha.value.trim();
        if (!/^[0-9]{1,32}$/.test(value) || value === panel.dataset.currentFicha?.trim()) return;
        const current = ++sequence;
        request?.abort();
        request = new AbortController();
        pending = true;
        save.disabled = true;
        status.textContent = 'Consultando ficha…';
        try {
            const url = new URL(panel.dataset.budgetLookupUrl, window.location.href);
            url.searchParams.set('ficha', value);
            const response = await fetcher(url, { headers: { Accept: 'application/json' }, signal: request.signal });
            const data = await response.json();
            if (current !== sequence || ficha.value.trim() !== value) return;
            if (!response.ok) throw new Error(data.message || 'Não foi possível consultar a ficha.');
            if (data.status === 'found') await navigate(data.url, request.signal,
                () => current === sequence && ficha.value.trim() === value);
            status.textContent = '';
        } catch (error) {
            if (error.name !== 'AbortError' && current === sequence) status.textContent = error.message;
        } finally {
            if (current === sequence) { pending = false; save.disabled = false; }
        }
    });
}

export function initializeBudgetRegistration(panel) {
        initializeBudgetDepartment(panel);
        initializeBudgetLookup(panel);
        const scopeWarning = panel.querySelector('[data-budget-scope-warning]');
        panel.querySelector('[data-budget-scope-warning-ok]')?.addEventListener('click', () => scopeWarning.close());
        scopeWarning?.addEventListener('close', () => panel.querySelector('[data-budget-part="Orgao"]')?.focus({ preventScroll: true }));
        const parts = [...panel.querySelectorAll('[data-budget-part]')];
        const update = () => {
            panel.querySelector('[data-budget-code]').value = composeBudgetCode(Object.fromEntries(parts.map((input) => [input.dataset.budgetPart, input.value])));
        };
        parts.filter((input) => input.dataset.budgetPart !== 'Orgao').forEach((input) => input.addEventListener('input', update));
        if (!panel.querySelector('[data-budget-code]').value) update();
        panel.querySelector('[data-budget-delete]')?.addEventListener('click', () => panel.querySelector('[data-budget-delete-dialog]')?.showModal());
        panel.querySelector('[data-budget-delete-cancel]')?.addEventListener('click', () => panel.querySelector('[data-budget-delete-dialog]').close());
        if (panel.hasAttribute('data-budget-saved')) {
            try { localStorage.setItem('contracts-budget-changed', JSON.stringify({ time: Date.now() })); } catch { /* Storage may be disabled. */ }
        }
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-budget-registration]').forEach(initializeBudgetRegistration);
}
