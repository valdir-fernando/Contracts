import { showSupplierDocumentWarning } from './system-message.js';

export function initializeAtaAdmission(root, fetcher = fetch, warn = showSupplierDocumentWarning) {
    const select = root.querySelector('[data-ata-admission-url]');
    if (!select) return;
    let previous = select.value;
    let pending = false;
    const notify = (name, detail = {}) => root.dispatchEvent(new CustomEvent(name, { bubbles: true, detail }));
    root.addEventListener('ata-loaded', ({ detail }) => { previous = detail.admitir_adesao ?? ''; });
    root.addEventListener('ata-cleared', () => { previous = ''; });
    select.addEventListener('change', async () => {
        if (!root.dataset.ataLoaded) return;
        if (pending || root.dataset.editorBusy === 'true') { select.value = previous; return; }
        const ataId = root.dataset.ataLoaded;
        const value = select.value || null;
        if (value === (previous || null)) return;
        pending = true;
        select.disabled = true;
        notify('ata-saving');
        let saved;
        let failureMessage;
        try {
            const response = await fetcher(select.dataset.ataAdmissionUrl.replace('/0/', `/${encodeURIComponent(ataId)}/`), {
                method: 'PATCH', credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': root.dataset.csrfToken },
                body: JSON.stringify({ version: Number(root.dataset.ataVersion), admitir_adesao: value }),
            });
            if (root.dataset.ataLoaded !== ataId) return;
            if (response.redirected || !response.ok) {
                const messages = {
                    401: 'Sua sessão terminou. Entre novamente para alterar a opção de adesão.',
                    403: 'Você não tem permissão para alterar esta ata.',
                    404: 'A ata não está disponível. Consulte novamente pelo Nº ATA.',
                    409: 'A ata foi alterada. Consulte novamente pelo Nº ATA antes de mudar a opção de adesão.',
                    419: 'Sua sessão terminou. Entre novamente para alterar a opção de adesão.',
                    422: 'Selecione SIM ou NÃO para a opção de adesão.',
                    429: 'Foram feitas muitas tentativas. Aguarde um momento e tente novamente.',
                };
                failureMessage = messages[response.redirected ? 401 : response.status]
                    || 'O sistema encontrou um problema ao salvar a opção de adesão. Consulte novamente a ata antes de tentar outra vez.';
                throw new Error();
            }
            const data = await response.json();
            if (root.dataset.ataLoaded !== ataId) return;
            previous = data.admitir_adesao ?? '';
            select.value = previous;
            saved = data;
        } catch {
            if (root.dataset.ataLoaded === ataId) {
                select.value = previous;
                warn(failureMessage || 'Não foi possível confirmar a alteração. Consulte novamente a ata antes de tentar outra vez.', root.ownerDocument);
            }
        } finally {
            pending = false;
            select.disabled = false;
            notify('ata-save-finished');
        }
        if (saved) {
            notify('ata-saved', saved);
            warn('Opção de adesão salva com sucesso.', root.ownerDocument);
        }
    });
}

if (typeof document !== 'undefined') document.querySelectorAll('.ata-editor').forEach(root => initializeAtaAdmission(root));
