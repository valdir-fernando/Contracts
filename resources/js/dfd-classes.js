export function initializeDfdClasses(dialog, ownerDocument = document, fetcher = fetch, navigate = (url) => location.assign(url)) {
    const filter = dialog.querySelector('[data-dfd-class-filter]');
    const list = dialog.querySelector('[data-dfd-class-list]');
    const status = dialog.querySelector('[data-dfd-class-status]');
    const selectedLabel = dialog.querySelector('[data-dfd-class-selected]');
    const retry = dialog.querySelector('[data-dfd-class-retry]');
    let classes = [];
    let selected = '';
    let request;
    let opener;
    const normalize = (value) => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('pt-BR');
    const select = (value) => {
        selected = value;
        selectedLabel.textContent = value;
        list.querySelectorAll('button').forEach((row) => row.setAttribute('aria-pressed', String(row.textContent === value)));
    };
    const render = () => {
        const term = normalize(filter.value.trim());
        const matches = classes.filter((value) => normalize(value).includes(term));
        list.replaceChildren();
        for (const value of matches) {
            const row = ownerDocument.createElement('button');
            row.type = 'button';
            row.className = 'dfd-class-row';
            row.textContent = value;
            row.setAttribute('aria-pressed', String(value === selected));
            row.addEventListener('click', () => select(value));
            row.addEventListener('keydown', (event) => {
                const rows = [...list.querySelectorAll('button')];
                let next;
                if (event.key === 'ArrowDown') next = rows[Math.min(rows.indexOf(row) + 1, rows.length - 1)];
                if (event.key === 'ArrowUp') next = rows[Math.max(rows.indexOf(row) - 1, 0)];
                if (event.key === 'Home') next = rows[0];
                if (event.key === 'End') next = rows.at(-1);
                if (next) { event.preventDefault(); next.focus(); select(next.textContent); }
            });
            list.append(row);
        }
        status.textContent = matches.length ? `${matches.length} classes disponíveis. Clique em uma classe para selecionar.` : 'Nenhuma classe encontrada.';
    };
    const load = async () => {
        request?.abort();
        const current = new AbortController();
        request = current;
        classes = [];
        selected = '';
        selectedLabel.textContent = 'Selecione uma classe / categoria';
        retry.hidden = true;
        list.replaceChildren();
        list.setAttribute('aria-busy', 'true');
        status.textContent = 'Carregando classes…';
        try {
            const response = await fetcher(dialog.dataset.classesUrl, { headers: { Accept: 'application/json' }, signal: current.signal, cache: 'no-store' });
            if (!response.ok || response.redirected) throw new Error('classes');
            const data = await response.json();
            if (!Array.isArray(data.classes) || data.classes.some((value) => typeof value !== 'string')) throw new Error('classes');
            if (request !== current || current.signal.aborted || !dialog.open) return;
            classes = data.classes;
            render();
        } catch (error) {
            if (request !== current || current.signal.aborted || !dialog.open) return;
            status.textContent = 'Não foi possível carregar as classes. Tente novamente.';
            retry.hidden = false;
        } finally {
            if (request === current) list.setAttribute('aria-busy', 'false');
        }
    };
    ownerDocument.querySelectorAll('[data-dfd-class-open]').forEach((link) => link.addEventListener('click', (event) => {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button > 0) return;
        event.preventDefault();
        if (dialog.open) return;
        opener = link;
        filter.value = '';
        dialog.showModal();
        ownerDocument.body.classList.add('modal-open');
        filter.focus();
        return load();
    }));
    filter.addEventListener('input', () => { if (!request?.signal.aborted) render(); });
    filter.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') { event.preventDefault(); dialog.close(); return; }
        if (event.key === 'ArrowDown' || event.key === 'Enter') {
            const first = list.querySelector('button');
            if (first) { event.preventDefault(); first.focus(); select(first.textContent); }
        }
    });
    retry.addEventListener('click', load);
    dialog.querySelector('[data-dfd-class-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {
        request?.abort();
        ownerDocument.body.classList.remove('modal-open');
        if (selected) {
            const url = new URL(dialog.dataset.createUrl, ownerDocument.baseURI);
            url.searchParams.set('classe', selected);
            navigate(url.href);
            return;
        }
        opener?.focus();
    });
}

if (typeof document !== 'undefined') document.querySelectorAll('[data-dfd-class-dialog]').forEach((dialog) => initializeDfdClasses(dialog));
