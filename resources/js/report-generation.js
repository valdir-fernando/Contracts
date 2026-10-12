const messages = {
    pending: 'Preparando relatório… Você pode continuar nesta página.',
    processing: 'Gerando relatório… O arquivo aparecerá aqui quando estiver pronto.',
    ready: 'Relatório pronto para baixar.',
    failed: 'Não foi possível concluir a geração. Solicite o relatório novamente.',
    revoked: 'Seu acesso ou os dados mudaram. Solicite um novo relatório.',
    expired: 'Este relatório expirou. Solicite um novo arquivo.',
};

async function requestJson(url) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 20000);
    try {
        const response = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store', signal: controller.signal });
        if (!response.ok) {
            const error = new Error('Não foi possível consultar o relatório.');
            error.status = response.status;
            throw error;
        }
        return await response.json();
    } finally { clearTimeout(timeout); }
}

function paint(card, state) {
    const busy = ['pending', 'processing'].includes(state.status);
    card.setAttribute('aria-busy', String(busy));
    card.dataset.state = state.status;
    card.querySelector('[data-report-message]').textContent = messages[state.status] ?? messages.failed;
    card.querySelector('[data-report-spinner]').hidden = !busy;
    const link = card.querySelector('[data-report-download]');
    link.hidden = !state.download_url || state.status !== 'ready';
    if (!link.hidden) link.href = state.download_url;
}

function follow(card, url, initial = 'pending') {
    const autoDownload = ['pending', 'processing'].includes(initial);
    paint(card, { status: initial });
    if (!['pending', 'processing', 'ready'].includes(initial)) return;
    let stopped = false;
    let finished = false;
    let timer;
    window.addEventListener('pagehide', () => { stopped = true; clearTimeout(timer); });
    const poll = async () => {
        if (stopped || !card.isConnected) return;
        if (document.hidden) { timer = setTimeout(poll, 5000); return; }
        let delay = 5000;
        try {
            const state = await requestJson(url);
            if (stopped || !card.isConnected) return;
            if (document.hidden) { timer = setTimeout(poll, 5000); return; }
            paint(card, state);
            if (autoDownload && state.status === 'ready' && state.download_url && card.dataset.autoDownloaded !== 'true') {
                card.dataset.autoDownloaded = 'true';
                card.querySelector('[data-report-download]').click();
            }
            if (!['pending', 'processing'].includes(state.status)) { finished = true; return; }
        } catch (error) {
            if ([401, 403, 404].includes(error.status)) {
                paint(card, { status: 'revoked' });
                finished = true;
                return;
            }
            card.querySelector('[data-report-message]').textContent = 'Reconectando para acompanhar a geração…';
            delay = error.status === 429 ? 30000 : 8000;
        }
        timer = setTimeout(poll, delay);
    };
    window.addEventListener('pageshow', (event) => {
        if (event.persisted && !finished) { stopped = false; poll(); }
    });
    poll();
}

document.querySelectorAll('[data-report-card][data-status-url]').forEach((card) => {
    follow(card, card.dataset.statusUrl, card.dataset.state);
});

document.addEventListener('click', async (event) => {
    const button = event.target.closest('a[data-report-export]');
    if (!button || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
    const list = document.querySelector('[data-report-cards]');
    const template = document.querySelector('[data-report-card-template]');
    if (!list || !template) return;
    event.preventDefault();
    if (button.dataset.submitting === 'true') return;
    const existing = [...list.querySelectorAll('[data-report-card]')].find(card => card.dataset.sourceUrl === button.href && card.getAttribute('aria-busy') === 'true');
    if (existing) { existing.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); return; }
    button.dataset.submitting = 'true';
    button.setAttribute('aria-disabled', 'true');
    const card = template.content.firstElementChild.cloneNode(true);
    card.dataset.sourceUrl = button.href;
    card.querySelector('[data-report-title]').textContent = button.textContent.trim().replace(/^Exportar/, 'Relatório');
    list.prepend(card);
    card.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    const url = new URL(button.href);
    url.searchParams.set('background', '1');
    try {
        const result = await requestJson(url);
        card.dataset.exportId = result.id;
        follow(card, result.status_url);
    } catch (error) {
        paint(card, { status: [401, 403].includes(error.status) ? 'revoked' : 'failed' });
        if (error.status === 422) card.querySelector('[data-report-message]').textContent = 'Confira os filtros e a justificativa antes de gerar o relatório.';
    } finally {
        delete button.dataset.submitting;
        button.removeAttribute('aria-disabled');
    }
});
