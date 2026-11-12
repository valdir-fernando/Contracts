export function formatAtaContractorField(column, value) {
    if (column === 'cpf_gestor' && typeof value === 'string' && /^\d{11}$/.test(value)) {
        return value.replace(/^(\d{3})(\d{3})(\d{3})(\d{2})$/, '$1.$2.$3-$4');
    }
    return value ?? '';
}

export function initializeAtaContractor(root, fetcher = fetch) {
    const panel = root.querySelector('[data-ata-contractor-url]');
    const select = root.querySelector('#ata-managing-fund');
    if (!panel || !select) return;
    const fields = [...panel.querySelectorAll('[data-ata-contractor-field]')];
    const status = panel.querySelector('[data-ata-contractor-status]');
    let sequence = 0;
    let controller;
    const load = async (fundId) => {
        const current = ++sequence;
        controller?.abort();
        fields.forEach(field => { field.textContent = ''; });
        status.textContent = '';
        if (!fundId) return;
        controller = new AbortController();
        status.textContent = 'Consultando dados do Fundo...';
        try {
            const url = panel.dataset.ataContractorUrl.replace('/0/', `/${encodeURIComponent(fundId)}/`);
            const response = await fetcher(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller.signal });
            if (!response.ok) throw new Error('Não foi possível consultar os dados deste Fundo com as permissões atuais.');
            const data = await response.json();
            if (current !== sequence) return;
            fields.forEach(field => {
                const column = field.dataset.ataContractorField;
                field.textContent = formatAtaContractorField(column, data.values[column]);
            });
            status.textContent = '';
        } catch (error) {
            if (current === sequence && error.name !== 'AbortError') status.textContent = error.message;
        }
    };
    select.addEventListener('change', () => load(select.value));
    const draft = JSON.parse(root.dataset.draftState || '{}');
    return load(draft.managing_fund_id || select.value);
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('.ata-editor').forEach(root => initializeAtaContractor(root));
    document.querySelectorAll('[data-ata-supplier-document-label]').forEach(label => {
        const field = label.closest('.field').querySelector('input');
        const resize = () => {
            const width = label.getBoundingClientRect().width;
            if (width > 0) {
                field.style.width = `${width}px`;
                label.closest('[data-ata-supplier-form]').style.setProperty('--ata-supplier-document-width', `${width}px`);
            }
        };
        new ResizeObserver(resize).observe(label);
        // An inline span must have a measurable box even inside a hidden tab.
        label.style.display = 'inline-block';
        document.fonts.ready.then(resize);
    });
}
