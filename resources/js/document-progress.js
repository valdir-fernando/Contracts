const active = new WeakMap();

export function startDocumentProgress(ownerDocument) {
    const dialog = ownerDocument?.querySelector?.('[data-document-progress]');
    if (!dialog?.showModal) return () => {};

    const count = active.get(dialog) ?? 0;
    if (count === 0) {
        dialog.oncancel = event => event.preventDefault();
        dialog.showModal();
    }
    active.set(dialog, count + 1);

    let finished = false;
    return () => {
        if (finished) return;
        finished = true;
        const remaining = active.get(dialog) - 1;
        if (remaining > 0) active.set(dialog, remaining);
        else {
            active.delete(dialog);
            dialog.close();
        }
    };
}
