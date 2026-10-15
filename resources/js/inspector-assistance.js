export function initializeInspectorAssistance(form, fetcher = fetch) {
    const settings = form.querySelector('[data-inspector-assistance]');
    if (!settings) return;
    const department = form.elements.namedItem('department_id');
    const status = form.querySelector('[data-inspector-status]');
    const fields = ['nome_fiscal', 'decreto_fiscal', 'cpf_fiscal', 'rg_fiscal'];
    let request;
    let pending = false;
    const display = (values = {}) => {
        for (const field of fields) {
            const output = form.querySelector(`[data-contractor-field="${field}"]`);
            if (output) output.textContent = values[field] || 'Não informado';
            const protectedOutput = form.querySelector(`[data-contractor-private-field="${field}"]`);
            if (protectedOutput) protectedOutput.textContent = 'Acesso restrito';
        }
    };
    const refresh = async () => {
        request?.abort();
        const current = new AbortController();
        request = current;
        pending = true;
        display();
        status.textContent = 'Buscando fiscal…';
        const url = new URL(settings.dataset.url, location.href);
        if (settings.dataset.contractId) url.searchParams.set('contract_id', settings.dataset.contractId);
        if (department && !department.disabled && department.value) url.searchParams.set('department_id', department.value);
        try {
            const response = await fetcher(url, { headers: { Accept: 'application/json' }, signal: current.signal });
            if (!response.ok) throw new Error('lookup');
            const { values } = await response.json();
            if (request !== current || current.signal.aborted) return;
            display(values);
            status.textContent = values.nome_fiscal ? 'Fiscal preenchido automaticamente.' : 'Nenhum fiscal único encontrado para este Fundo ou Secretaria.';
        } catch (error) {
            if (request === current && error.name !== 'AbortError') status.textContent = 'Não foi possível consultar o fiscal. Confira a Secretaria e tente novamente.';
        } finally {
            if (request === current) pending = false;
        }
    };
    department?.addEventListener('change', refresh);
    form.addEventListener('submit', (event) => {
        if (pending) { event.preventDefault(); status.textContent = 'Aguarde a consulta do fiscal antes de salvar.'; }
    });
    if (settings.dataset.preserveFiscal !== 'true') refresh();
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-contract-form]').forEach((form) => initializeInspectorAssistance(form));
}
