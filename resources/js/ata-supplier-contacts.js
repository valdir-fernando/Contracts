export function formatAtaPhone(value) {
    if (value === null || value === undefined) return '';
    const original = String(value).trim();
    if (!/^[\d\s()+.\-]+$/.test(original)) return original;
    let digits = original.replace(/\D/g, '');
    if (digits.length === 13 && digits.startsWith('55')) digits = digits.slice(2);
    if (digits.length === 11) return digits.replace(/^(\d{2})(\d)(\d{4})(\d{4})$/, '($1) $2 $3-$4');
    if (digits.length === 10) return digits.replace(/^(\d{2})(\d{4})(\d{4})$/, '($1) $2-$3');
    return original;
}

export function initializeAtaSupplierContacts(form, fetcher = fetch) {
    const documentField = form.elements.namedItem('fornecedor_documento_snapshot');
    const names = ['supplier_email', 'supplier_mobile', 'supplier_whatsapp'];
    let attempt;
    const clear = () => {
        attempt?.abort();
        attempt = null;
        names.forEach(name => { form.elements.namedItem(name).value = ''; });
    };
    const load = async () => {
        clear();
        const digits = documentField.value.replace(/\D/g, '');
        if (![11, 14].includes(digits.length)) return;
        const current = new AbortController();
        attempt = current;
        try {
            const response = await fetcher(form.dataset.supplierContactsUrl, {
                method: 'POST', credentials: 'same-origin', signal: current.signal,
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': form.elements.namedItem('_token').value },
                body: JSON.stringify({ document: digits }),
            });
            if (!response.ok) return;
            const { values } = await response.json();
            if (attempt !== current || documentField.value.replace(/\D/g, '') !== digits) return;
            names.forEach(name => {
                form.elements.namedItem(name).value = name === 'supplier_email' ? (values[name] ?? '') : formatAtaPhone(values[name]);
            });
        } catch {
            // Lookup errors are reported by the shared supplier assistance.
        }
    };
    documentField.addEventListener('input', clear);
    documentField.addEventListener('focusout', load);
    form.addEventListener('supplier-selected', load);
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-supplier-contacts-url]').forEach(form => initializeAtaSupplierContacts(form));
}
