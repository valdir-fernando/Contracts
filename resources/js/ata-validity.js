import { isoDate } from './input-masks.js';
import { showSupplierDocumentWarning } from './system-message.js';

export function initializeAtaValidity(root, fetcher = fetch, warn = showSupplierDocumentWarning) {
    const panel = root.querySelector('[data-ata-validity]');
    if (!panel) return;
    const field = name => panel.querySelector(`[name="${name}"]`);
    const start = field('start_date'), end = field('end_date'), period = field('validity_period'), mode = field('one_year_inpc');
    const save = panel.querySelector('[data-ata-validity-save-url]');
    const values = () => ({ start_date: start.value ? isoDate(start.value) : null, end_date: end.value ? isoDate(end.value) : null,
        validity_period: period.value.trim() || null, one_year_inpc: mode.checked });
    let baseline = JSON.stringify(values());
    let pending = false;
    const notify = (name, detail = {}) => root.dispatchEvent(new CustomEvent(name, { bubbles: true, detail }));
    const applyMode = () => {
        [start, end].forEach(input => { input.dataset.validityDisabled = String(mode.checked); input.disabled = mode.checked || root.dataset.editorBusy === 'true'; });
    };
    const dirty = () => { root.dataset.ataValidityDirty = String(JSON.stringify(values()) !== baseline); };
    mode.addEventListener('change', () => { if (mode.checked) period.value = '1 Ano INPC'; applyMode(); dirty(); });
    period.addEventListener('input', () => {
        if (mode.checked && period.value !== '1 Ano INPC') { mode.checked = false; applyMode(); }
        dirty();
    });
    [start, end].forEach(input => { input.addEventListener('input', dirty); input.addEventListener('change', dirty); });
    const load = data => {
        const date = (input, value) => { input.value = input.type === 'date' ? value ?? '' : (value ?? '').split('-').reverse().join('/'); };
        date(start, data.start_date); date(end, data.end_date);
        mode.checked = data.one_year_inpc === true;
        period.value = mode.checked ? '1 Ano INPC' : data.validity_period ?? '';
        applyMode();
        baseline = JSON.stringify(values()); dirty();
    };
    root.addEventListener('ata-loaded', ({ detail }) => load(detail));
    root.addEventListener('ata-cleared', () => load({}));
    save.addEventListener('click', async () => {
        if (pending || !root.dataset.ataLoaded || root.dataset.editorBusy === 'true') return;
        const ataId = root.dataset.ataLoaded;
        const payload = { version: Number(root.dataset.ataVersion), ...values() };
        pending = true;
        notify('ata-product-saving');
        let saved;
        try {
            const response = await fetcher(save.dataset.ataValiditySaveUrl.replace('/0/', `/${encodeURIComponent(ataId)}/`), {
                method: 'PATCH', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': root.dataset.csrfToken },
                body: JSON.stringify(payload),
            });
            if (root.dataset.ataLoaded !== ataId) return;
            if (response.redirected || !response.ok) {
                const messages = { 401: 'Sua sessão terminou. Entre novamente para salvar a vigência.', 419: 'Sua sessão terminou. Entre novamente para salvar a vigência.',
                    403: 'Você não tem permissão para alterar esta ata.', 404: 'A ata não está disponível. Consulte novamente pelo Nº ATA.',
                    409: 'A ata foi alterada. Consulte novamente antes de salvar a vigência.', 422: 'Confira as datas e o período da vigência. A Data_Final deve ser igual ou posterior à Data_Inicial.',
                    429: 'Aguarde um momento antes de tentar salvar novamente.' };
                warn(messages[response.redirected ? 401 : response.status] || 'Não foi possível salvar a vigência. Consulte novamente a ata antes de tentar outra vez.', root.ownerDocument);
                return;
            }
            const data = await response.json();
            if (root.dataset.ataLoaded === ataId) saved = data;
        } catch {
            if (root.dataset.ataLoaded === ataId) warn('Não foi possível confirmar a gravação da vigência. Consulte novamente a ata antes de tentar outra vez.', root.ownerDocument);
        } finally {
            pending = false;
            notify('ata-product-finished');
        }
        if (saved) { notify('ata-saved', saved); warn('Vigência salva com sucesso.', root.ownerDocument); }
    });
    applyMode();
}

if (typeof document !== 'undefined') document.querySelectorAll('.ata-editor').forEach(root => initializeAtaValidity(root));
