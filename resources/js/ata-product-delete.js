import { formatDecimalField } from './decimal-fields.js';
import { confirmSystemMessage, showSupplierDocumentWarning } from './system-message.js';

export function initializeAtaProductDelete(root, panel, fetcher = fetch, warn = showSupplierDocumentWarning, confirm = confirmSystemMessage) {
    const button = panel.querySelector('[data-ata-product-delete-url]');
    if (!button) return;
    let pending = false;
    const notify = (name, detail = {}) => root.dispatchEvent(new CustomEvent(name, { bubbles: true, detail }));
    button.addEventListener('click', async () => {
        if (pending || button.disabled || !root.dataset.ataLoaded || root.dataset.editorBusy === 'true') return;
        const item = panel.querySelector('[data-product-field="item"]').value.trim();
        const quantity = panel.querySelector('[data-product-quantity]').value.trim();
        const selected = [...panel.querySelectorAll('[data-ata-product-select]:checked')].map(checkbox => Number(checkbox.value));
        const formatted = formatDecimalField(quantity);
        if (!selected.length && !item) { warn('Não é possível excluir: nenhum item foi selecionado ou informado no campo Item.', root.ownerDocument); return; }
        if (!selected.length && (!formatted || formatted.startsWith('-') || !/[1-9]/.test(formatted))) {
            warn('Informe uma quantidade maior que zero para excluir.', root.ownerDocument); return;
        }
        const ataId = root.dataset.ataLoaded;
        const version = root.dataset.ataVersion;
        pending = true;
        button.disabled = true;
        notify('ata-product-saving');
        let removed;
        let failureMessage;
        try {
            const message = selected.length
                ? `Confirma a exclusão completa de ${selected.length} ${selected.length === 1 ? 'item selecionado' : 'itens selecionados'} desta ata?`
                : `Confirma a retirada de ${formatted} do Item ${item} desta ata? Se a quantidade for igual à cadastrada, o item será excluído.`;
            if (!await confirm(message, root.ownerDocument)) return;
            if (root.dataset.ataLoaded !== ataId || root.dataset.ataVersion !== version) return;
            const response = await fetcher(button.dataset.ataProductDeleteUrl.replace('/0/', `/${encodeURIComponent(ataId)}/`), {
                method: 'DELETE', credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': root.dataset.csrfToken },
                body: JSON.stringify(selected.length ? { product_ids: selected, version: Number(version) } : { item, quantity, version: Number(version) }),
            });
            if (root.dataset.ataLoaded !== ataId) return;
            if (response.redirected || !response.ok) {
                const messages = {
                    401: 'Sua sessão terminou. Entre novamente e consulte a ata antes de excluir.',
                    403: 'Você não tem permissão para excluir produtos desta ata.',
                    404: 'A ata não está disponível. Consulte novamente pelo Nº ATA.',
                    409: 'A ata foi alterada. Consulte novamente pelo Nº ATA antes de excluir.',
                    419: 'Sua sessão terminou. Entre novamente e consulte a ata antes de excluir.',
                    422: 'Confira o Item e a quantidade que deseja excluir.',
                    429: 'Foram feitas muitas tentativas. Aguarde um momento e tente novamente.',
                };
                failureMessage = messages[response.redirected ? 401 : response.status]
                    || 'O sistema encontrou um problema ao excluir. Consulte novamente a ata antes de tentar outra vez.';
                if (response.status === 422 && !response.redirected) {
                    const data = await response.json();
                    failureMessage = Object.values(data.errors ?? {}).flat().join(' ') || failureMessage;
                }
                throw new Error(failureMessage);
            }
            const data = await response.json();
            if (root.dataset.ataLoaded === ataId) removed = data;
        } catch {
            if (root.dataset.ataLoaded === ataId) warn(failureMessage || 'Não foi possível confirmar a exclusão. Consulte novamente a ata antes de tentar outra vez.', root.ownerDocument);
        } finally {
            pending = false;
            button.disabled = false;
            notify('ata-product-finished');
        }
        if (removed) {
            notify('ata-saved', removed);
            warn(removed.removed_count ? `${removed.removed_count} ${removed.removed_count === 1 ? 'item excluído' : 'itens excluídos'} com sucesso.`
                : removed.deleted ? 'Item excluído com sucesso.' : 'Quantidade retirada com sucesso. O item foi atualizado.', root.ownerDocument);
        }
    });
}
