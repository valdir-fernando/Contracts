const dialog = document.querySelector('[data-contract-search-url]');
if (dialog) {
    const number = document.querySelector('[data-contract-number-search]');
    const opener = document.querySelector('[data-modal-open="contract-search-dialog"]');
    const form = dialog.querySelector('[data-contract-search-form]');
    const query = form.elements.q;
    const results = dialog.querySelector('[data-contract-search-results]');
    const status = dialog.querySelector('[data-contract-search-status]');
    const previous = dialog.querySelector('[data-contract-search-prev]');
    const next = dialog.querySelector('[data-contract-search-next]');
    let page = 1;
    let searchTerm = '';
    let request;
    const search = async (targetPage = 1) => {
        request?.abort();
        const current = new AbortController();
        request = current;
        results.replaceChildren();
        previous.disabled = next.disabled = true;
        status.textContent = 'Buscando contratos…';
        const url = new URL(dialog.dataset.contractSearchUrl, location.href);
        url.searchParams.set('q', searchTerm);
        url.searchParams.set('page', targetPage);
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: current.signal });
            if (!response.ok) throw new Error('search');
            const data = await response.json();
            if (request !== current || current.signal.aborted) return;
            page = data.page;
            for (const contract of data.contracts) {
                const item = document.createElement('li');
                const link = document.createElement('a');
                link.href = contract.url;
                link.textContent = `${contract.number || 'Sem número'} · Processo: ${contract.process || 'Não informado'}`;
                item.append(link);
                results.append(item);
            }
            status.textContent = data.contracts.length ? `Página ${page} de ${data.last_page}. Selecione um contrato para abrir.` : 'Nenhum contrato encontrado neste Fundo.';
            previous.disabled = page <= 1;
            next.disabled = page >= data.last_page;
        } catch (error) {
            if (error.name !== 'AbortError' && request === current) status.textContent = 'Não foi possível consultar os contratos. Tente novamente.';
        }
    };
    opener.addEventListener('click', () => {
        query.value = number.value;
        searchTerm = query.value;
        search();
    });
    number.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') { event.preventDefault(); opener.click(); }
    });
    form.addEventListener('submit', (event) => { event.preventDefault(); searchTerm = query.value; search(); });
    previous.addEventListener('click', () => search(page - 1));
    next.addEventListener('click', () => search(page + 1));
    dialog.addEventListener('close', () => request?.abort());
}
