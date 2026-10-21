export function initializeProductBudgetPanel(opener, warning, onSaved, onClose, doc = document) {
    let dialog;
    opener?.addEventListener('click', (event) => {
        event.preventDefault();
        if (!dialog) {
            dialog = doc.createElement('dialog');
            dialog.className = 'catalog-modal supplier-catalog-dialog';
            dialog.setAttribute('aria-label', 'Cadastro de Dotação');
            const close = doc.createElement('button');
            close.type = 'button';
            close.className = 'section-tab';
            close.textContent = 'Fechar cadastro de dotação';
            const frame = doc.createElement('iframe');
            frame.title = 'Cadastro de Dotação';
            dialog.append(close, frame);
            doc.body.append(dialog);
            close.addEventListener('click', () => dialog.close());
            dialog.addEventListener('close', () => {
                frame.src = 'about:blank';
                onClose();
            });
            frame.addEventListener('load', () => {
                if (frame.contentDocument?.querySelector('[data-budget-saved]')) {
                    onSaved();
                    dialog.close();
                }
            });
            dialog.budgetFrame = frame;
        }
        warning.close();
        dialog.budgetFrame.src = opener.href;
        dialog.showModal();
    });
}
