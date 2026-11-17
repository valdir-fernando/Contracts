import { confirmSystemMessage, requestAtaSpecialQuantityName, showSupplierDocumentWarning } from './system-message.js';

export function initializeAtaSpecialQuantity(root, fetcher = fetch, warn = showSupplierDocumentWarning, prompt = requestAtaSpecialQuantityName, confirm = confirmSystemMessage) {
    const button = root.querySelector('[data-ata-special-quantity]');
    if (!button) return;
    const group = root.querySelector('[data-ata-quantity-pricing]');
    const field = root.querySelector('[data-ata-special-quantity-field]');
    const label = root.querySelector('[data-ata-special-quantity-label]');
    const heading = root.querySelector('[data-ata-special-quantity-heading]');
    const quantity = root.querySelector('[data-product-special-quantity]');
    const remove = root.querySelector('[data-ata-special-quantity-delete]');
    let pending = false;
    const notify = (name, detail = {}) => root.dispatchEvent(new CustomEvent(name, { bubbles: true, detail }));
    const configure = (name, reset = true) => {
        root.dataset.ataSpecialQuantityName = name || '';
        const visible = Boolean(name);
        button.hidden = visible;
        group.classList.toggle('has-special-quantity', visible);
        field.hidden = heading.hidden = !visible;
        quantity.disabled = !visible;
        if (reset) quantity.value = '0,0000';
        root.querySelectorAll('[data-ata-special-quantity-cell]').forEach(cell => { cell.hidden = !visible; });
        label.textContent = heading.textContent = name ? `QTD de ${name}` : '';
        quantity.setAttribute('aria-label', label.textContent || 'Quantidade especial');
        notify('ata-special-quantity-configured');
    };
    root.addEventListener('ata-loaded', ({ detail }) => configure(detail.special_quantity_name));
    root.addEventListener('ata-cleared', () => configure(''));
    remove?.addEventListener('click', async () => {
        if (pending || !root.dataset.ataLoaded || !root.dataset.ataSpecialQuantityName || root.dataset.editorBusy === 'true') return;
        const ataId = root.dataset.ataLoaded;
        const version = root.dataset.ataVersion;
        const name = root.dataset.ataSpecialQuantityName;
        pending = true;
        notify('ata-product-saving');
        let removed;
        try {
            if (!await confirm(`Confirma a exclusão de QTD de ${name}? O nome e todos os valores dessa quantidade nos produtos desta ata serão apagados.`, root.ownerDocument)) return;
            if (root.dataset.ataLoaded !== ataId || root.dataset.ataVersion !== version) return;
            const response = await fetcher(button.dataset.ataSpecialQuantityUrl.replace('/0/', `/${encodeURIComponent(ataId)}/`), {
                method: 'DELETE', credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': root.dataset.csrfToken },
                body: JSON.stringify({ version: Number(version) }),
            });
            if (root.dataset.ataLoaded !== ataId) return;
            if (response.redirected || !response.ok) {
                const messages = {
                    401: 'Sua sessão terminou. Entre novamente para excluir a QTD Especial.',
                    403: 'Você não tem permissão para alterar esta ata.',
                    404: 'A ata não está disponível. Consulte novamente pelo Nº ATA.',
                    409: 'A ata foi alterada. Consulte novamente pelo Nº ATA antes de excluir a QTD Especial.',
                    419: 'Sua sessão terminou. Entre novamente para excluir a QTD Especial.',
                    422: 'Consulte novamente a ata antes de excluir a QTD Especial.',
                    429: 'Foram feitas muitas tentativas. Aguarde um momento e tente novamente.',
                };
                warn(messages[response.redirected ? 401 : response.status] || 'O sistema encontrou um problema ao excluir a QTD Especial. Consulte novamente a ata antes de tentar outra vez.', root.ownerDocument);
                return;
            }
            const data = await response.json();
            if (root.dataset.ataLoaded !== ataId) return;
            configure('');
            root.querySelectorAll('[data-ata-special-quantity-cell]').forEach(cell => cell.replaceChildren());
            root.dataset.ataVersion = String(data.version);
            notify('ata-special-quantity-updated', data);
            removed = data;
        } catch {
            if (root.dataset.ataLoaded === ataId) warn('Não foi possível confirmar a exclusão. Consulte novamente a ata antes de tentar outra vez.', root.ownerDocument);
        } finally {
            pending = false;
            notify('ata-product-finished');
        }
        if (removed) {
            notify('ata-saved', removed);
            warn('QTD Especial e seus valores excluídos com sucesso.', root.ownerDocument);
        }
    });
    button.addEventListener('click', async () => {
        if (pending || !root.dataset.ataLoaded || root.dataset.editorBusy === 'true') return;
        pending = true;
        const ataId = root.dataset.ataLoaded;
        const version = Number(root.dataset.ataVersion);
        let saving = false;
        try {
            const name = (await prompt(root.ownerDocument)).trim();
            if (!name || root.dataset.ataLoaded !== ataId || root.dataset.editorBusy === 'true') return;
            if (name === root.dataset.ataSpecialQuantityName) return;
            saving = true;
            notify('ata-product-saving');
            const response = await fetcher(button.dataset.ataSpecialQuantityUrl.replace('/0/', `/${encodeURIComponent(ataId)}/`), {
                method: 'PATCH', credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': root.dataset.csrfToken },
                body: JSON.stringify({ version, special_quantity_name: name }),
            });
            if (root.dataset.ataLoaded !== ataId) return;
            if (response.redirected || !response.ok) {
                const messages = {
                    401: 'Sua sessão terminou. Entre novamente para configurar a QTD Especial.',
                    403: 'Você não tem permissão para alterar esta ata.',
                    404: 'A ata não está disponível. Consulte novamente pelo Nº ATA.',
                    409: 'A ata foi alterada. Consulte novamente pelo Nº ATA antes de configurar a QTD Especial.',
                    419: 'Sua sessão terminou. Entre novamente para configurar a QTD Especial.',
                    422: 'Informe um nome com até 60 caracteres para a QTD Especial.',
                    429: 'Foram feitas muitas tentativas. Aguarde um momento e tente novamente.',
                };
                warn(messages[response.redirected ? 401 : response.status] || 'Não foi possível salvar o nome da QTD Especial. Consulte novamente a ata antes de tentar outra vez.', root.ownerDocument);
                return;
            }
            const data = await response.json();
            if (root.dataset.ataLoaded !== ataId) return;
            configure(data.special_quantity_name, false);
            root.dataset.ataVersion = String(data.version);
            notify('ata-special-quantity-updated', data);
            warn('QTD Especial configurada com sucesso.', root.ownerDocument);
        } catch {
            if (root.dataset.ataLoaded === ataId) warn('Não foi possível confirmar a alteração. Consulte novamente a ata antes de tentar outra vez.', root.ownerDocument);
        } finally {
            pending = false;
            if (saving) notify('ata-product-finished');
        }
    });
}

if (typeof document !== 'undefined') document.querySelectorAll('.ata-editor').forEach(root => initializeAtaSpecialQuantity(root));
