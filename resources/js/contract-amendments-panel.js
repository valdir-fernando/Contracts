export function initializeAmendmentsPanel(openers, dialog) {
    let opener;
    const frame = dialog.querySelector('[data-contract-amendments-frame]');
    const close = dialog.querySelector('[data-contract-amendments-close]');
    const loading = dialog.querySelector('[data-contract-amendments-loading]');
    openers.forEach((button) => button.addEventListener('click', (event) => {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        if (dialog.open) return;
        opener = button;
        loading.hidden = false;
        frame.src = opener.href;
        dialog.showModal();
        close.focus();
    }));
    close.addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {
        frame.src = 'about:blank';
        loading.hidden = true;
        opener?.focus({ preventScroll: true });
    });
    frame.addEventListener('load', () => {
        if (!dialog.open || frame.src === 'about:blank') return;
        loading.hidden = true;
        try {
            const doc = frame.contentDocument;
            if (!doc) return;
            const current = new URL(doc.URL);
            const summary = new URL(dialog.dataset.summaryUrl);
            if (current.origin === summary.origin && current.pathname === summary.pathname) {
                dialog.close();
                return;
            }
            doc.addEventListener('keydown', (event) => {
                if (event.key !== 'Escape' || event.defaultPrevented || doc.querySelector('dialog[open], [popover]:popover-open')) return;
                event.preventDefault();
                dialog.close();
            });
        } catch {
            // An expired session or navigation outside the app must still leave the close button usable.
        }
    });
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-contract-amendments-panel]').forEach((dialog) => {
        const openers = document.querySelectorAll(`[data-contract-amendments-open][aria-controls="${dialog.id}"]`);
        initializeAmendmentsPanel(openers, dialog);
    });
}
