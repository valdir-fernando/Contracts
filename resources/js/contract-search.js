export function initializeContractSearch(dialog, ownerDocument = document, fetcher = fetch, navigate = (url) => location.assign(url)) {
    const number = ownerDocument.querySelector('[data-contract-number-search]');
    const opener = ownerDocument.querySelector('[data-contract-search-open]');
    const form = dialog.querySelector('[data-contract-search-form]');
    const query = form.elements.q;
    const results = dialog.querySelector('[data-contract-search-results]');
    const status = dialog.querySelector('[data-contract-search-status]');
    const previous = dialog.querySelector('[data-contract-search-prev]');
    const next = dialog.querySelector('[data-contract-search-next]');
    let page = 1;
    let searchTerm = '';
    let request;
    const openDialog = () => {
        if (!dialog.open) dialog.showModal();
        ownerDocument.body.classList.add('modal-open');
        query.focus();
    };
    const search = async (targetPage = 1, direct = false) => {
        request?.abort();
        const current = new AbortController();
        request = current;
        results.replaceChildren();
        previous.disabled = next.disabled = true;
        status.textContent = 'Buscando contratos…';
        const url = new URL(dialog.dataset.contractSearchUrl, ownerDocument.baseURI);
        url.searchParams.set('q', searchTerm);
        url.searchParams.set('page', targetPage);
        if (direct) url.searchParams.set('exact', '1');
        try {
            const response = await fetcher(url, { headers: { Accept: 'application/json' }, signal: current.signal });
            if (!response.ok) throw new Error('search');
            const data = await response.json();
            if (request !== current || current.signal.aborted) return;
            if (direct) {
                if (data.contracts.length === 1 && data.last_page === 1) {
                    navigate(data.contracts[0].url);
                    return;
                }
                openDialog();
                return search(1);
            }
            page = data.page;
            for (const contract of data.contracts) {
                const item = ownerDocument.createElement('li');
                const link = ownerDocument.createElement('a');
                link.href = contract.url;
                link.textContent = `${contract.number || 'Sem número'} · Processo: ${contract.process || 'Não informado'}`;
                item.append(link);
                results.append(item);
            }
            status.textContent = data.contracts.length ? `Página ${page} de ${data.last_page}. Selecione um contrato para abrir.` : 'Nenhum contrato encontrado neste Fundo.';
            previous.disabled = page <= 1;
            next.disabled = page >= data.last_page;
        } catch (error) {
            if (error.name !== 'AbortError' && request === current && !current.signal.aborted) {
                openDialog();
                status.textContent = 'Não foi possível consultar os contratos. Tente novamente.';
            }
        } finally {
            if (request === current) opener.disabled = false;
        }
    };
    const searchNumber = () => {
        if (opener.disabled) return;
        query.value = number.value.trim();
        searchTerm = query.value;
        opener.disabled = true;
        if (searchTerm) return search(1, true);
        openDialog();
        return search();
    };
    opener.addEventListener('click', searchNumber);
    number.addEventListener('blur', () => {
        if (number.value.trim()) return searchNumber();
    });
    number.addEventListener('input', () => { request?.abort(); opener.disabled = false; });
    form.addEventListener('submit', (event) => { event.preventDefault(); searchTerm = query.value; search(); });
    previous.addEventListener('click', () => search(page - 1));
    next.addEventListener('click', () => search(page + 1));
    dialog.addEventListener('close', () => { request?.abort(); opener.disabled = false; opener.focus(); });
}

if (typeof document !== 'undefined') {
    const dialog = document.querySelector('[data-contract-search-url]');
    if (dialog) initializeContractSearch(dialog);
}
