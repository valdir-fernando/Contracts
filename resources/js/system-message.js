export function showSupplierDocumentWarning(message, ownerDocument = document) {
    let dialog = ownerDocument.querySelector('[data-supplier-document-warning]');
    if (!dialog) {
        dialog = ownerDocument.createElement('dialog');
        dialog.className = 'catalog-modal supplier-document-warning';
        dialog.setAttribute('data-supplier-document-warning', '');
        dialog.setAttribute('role', 'alertdialog');
        dialog.setAttribute('aria-labelledby', 'supplier-document-warning-title');
        dialog.setAttribute('aria-describedby', 'supplier-document-warning-message');
        dialog.innerHTML = '<div class="modal-header"><h2 id="supplier-document-warning-title">Gestão Sistem informa:</h2></div>'
            + '<p id="supplier-document-warning-message"></p>'
            + '<form method="dialog" class="form-actions modal-actions"><button type="submit" class="primary-button" autofocus>OK</button></form>';
        ownerDocument.body.append(dialog);
    }
    dialog.querySelector('p').textContent = message;
    if (!dialog.open) dialog.showModal();
}

export function confirmSystemMessage(message, ownerDocument = document, { emphasize = [] } = {}) {
    let dialog = ownerDocument.querySelector('[data-system-confirmation]');
    if (!dialog) {
        dialog = ownerDocument.createElement('dialog');
        dialog.className = 'catalog-modal supplier-document-warning';
        dialog.setAttribute('data-system-confirmation', '');
        dialog.setAttribute('role', 'alertdialog');
        dialog.setAttribute('aria-labelledby', 'system-confirmation-title');
        dialog.setAttribute('aria-describedby', 'system-confirmation-message');
        dialog.innerHTML = '<div class="modal-header"><h2 id="system-confirmation-title">Gestão Sistem informa:</h2></div>'
            + '<p id="system-confirmation-message"></p>'
            + '<form method="dialog" class="form-actions modal-actions">'
            + '<button type="submit" class="primary-button" value="ok">OK</button>'
            + '<button type="submit" class="section-tab" value="cancel" autofocus>Cancelar</button></form>';
        ownerDocument.body.append(dialog);
    }
    if (dialog.open) return Promise.resolve(false);
    const paragraph = dialog.querySelector('p');
    paragraph.textContent = message;
    if (emphasize.length) {
        const terms = new RegExp(`(${emphasize.map(term => term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|')})`, 'g');
        paragraph.replaceChildren(...message.split(terms).map(part => {
            if (!emphasize.includes(part)) return part;
            const strong = ownerDocument.createElement('strong');
            strong.textContent = part;
            return strong;
        }));
    }
    dialog.returnValue = '';
    return new Promise(resolve => {
        dialog.addEventListener('close', () => resolve(dialog.returnValue === 'ok'), { once: true });
        dialog.showModal();
    });
}

export function requestAtaSpecialQuantityName(ownerDocument = document) {
    let dialog = ownerDocument.querySelector('[data-ata-special-quantity-dialog]');
    if (!dialog) {
        dialog = ownerDocument.createElement('dialog');
        dialog.className = 'catalog-modal supplier-document-warning';
        dialog.setAttribute('data-ata-special-quantity-dialog', '');
        dialog.setAttribute('aria-labelledby', 'ata-special-quantity-title');
        dialog.innerHTML = '<div class="modal-header"><h2 id="ata-special-quantity-title">Gestão Sistem informa:</h2></div>'
            + '<form method="dialog"><label for="ata-special-quantity-name">QTD de ...</label>'
            + '<input id="ata-special-quantity-name" type="text" maxlength="60" autocomplete="off" autofocus>'
            + '<div class="form-actions modal-actions"><button type="submit" class="primary-button" value="ok">OK</button></div></form>';
        ownerDocument.body.append(dialog);
    }
    if (dialog.open) return Promise.resolve('');
    const input = dialog.querySelector('input');
    input.value = '';
    dialog.returnValue = '';
    return new Promise(resolve => {
        dialog.addEventListener('close', () => resolve(dialog.returnValue === 'ok' ? input.value.trim() : ''), { once: true });
        dialog.showModal();
        input.focus();
    });
}
