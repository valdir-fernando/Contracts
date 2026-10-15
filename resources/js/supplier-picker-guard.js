export function guardSupplierCatalogClose(dialog, close, frame, Observer = MutationObserver) {
    let observer;
    const registrationOpen = () => Boolean(frame.contentDocument?.querySelector('#catalog-supplier[open]'));
    const sync = () => { close.disabled = registrationOpen(); };
    close.addEventListener('click', (event) => {
        sync();
        if (close.disabled) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    });
    dialog.addEventListener('cancel', (event) => {
        if (registrationOpen()) event.preventDefault();
    });
    frame.addEventListener('load', () => {
        observer?.disconnect();
        sync();
        if (!frame.contentDocument) return;
        observer = new Observer(sync);
        observer.observe(frame.contentDocument, { subtree: true, attributes: true, attributeFilter: ['open'] });
    });
    dialog.addEventListener('close', () => {
        observer?.disconnect();
        close.disabled = false;
    });
}
