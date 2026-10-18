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
