import { formatSupplierDocument } from './supplier-assistance.js';
import { attachMask, maskPhone, maskPis } from './input-masks.js';

export function initializeSupplierRegistration(form, fetcher = fetch) {
    const documentField = form.elements.namedItem('document');
    const status = form.querySelector('[data-registration-lookup-status]');
    const fields = [form.elements.namedItem('name'), ...form.querySelectorAll('.supplier-registration-group [name]')];
    const maskFor = (field) => field.dataset.phoneMask !== undefined ? maskPhone : (field.dataset.pisMask !== undefined ? maskPis : null);
    fields.forEach((field) => {
        const formatter = maskFor(field);
        if (formatter) attachMask(field, formatter);
    });
    let request;
    const filled = new Map();
    const digits = () => documentField.value.replace(/\D/g, '');
    const reset = () => {
        request?.abort();
        request = null;
        status.textContent = '';
        for (const [field, { before, automatic }] of filled) {
            if (field.value === automatic) field.value = before;
        }
        filled.clear();
    };
    const lookup = async () => {
        request?.abort();
        const attempt = new AbortController();
        request = attempt;
        documentField.value = formatSupplierDocument(documentField.value);
        const document = digits();
        if (!/^[\d\s.\/-]+$/.test(documentField.value) || ![11, 14].includes(document.length)) {
            status.textContent = 'Informe um CPF ou CNPJ válido para consultar o cadastro.';
            return;
        }
        status.textContent = 'Verificando se o fornecedor já está cadastrado…';
        try {
            const response = await fetcher(form.dataset.registrationLookupUrl, {
                method: 'POST', credentials: 'same-origin', signal: attempt.signal,
                headers: { Accept: 'application/json', 'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': form.elements.namedItem('_token').value },
                body: JSON.stringify({ document }),
            });
            if (request !== attempt || digits() !== document) return;
            if (!response.ok) {
                const messages = { 409: 'Há mais de um fornecedor com este documento. Consulte o catálogo.',
                    422: 'Informe um CPF ou CNPJ válido.', 401: 'Entre novamente para consultar o cadastro.',
                    403: 'Sua conta não possui acesso a este cadastro.' };
                status.textContent = messages[response.status] ?? 'Não foi possível verificar o cadastro. Saia do campo para tentar novamente.';
                return;
            }
            const data = await response.json();
            if (request !== attempt || digits() !== document) return;
            if (data.found) {
                fields.forEach((field) => {
                    const before = filled.get(field)?.before ?? field.value;
                    let value = data.values[field.name] ?? '';
                    if (field.name === 'CPF_Socio') value = formatSupplierDocument(value);
                    if (maskFor(field)) value = maskFor(field)(value);
                    if (field.dataset.brazilianDate) value = value.replace(/^(\d{4})-(\d{2})-(\d{2})$/, '$3/$2/$1');
                    field.value = value;
                    if (field.dataset.brazilianDate) field.dispatchEvent?.(new Event('input', { bubbles: true }));
                    filled.set(field, { before, automatic: field.value });
                });
                status.textContent = '';
            } else {
                status.textContent = 'Fornecedor não cadastrado. Preencha os dados e salve.';
            }
        } catch (error) {
            if (request === attempt && error.name !== 'AbortError') status.textContent = 'Não foi possível verificar o cadastro. Saia do campo para tentar novamente.';
        }
    };
    documentField.addEventListener('input', reset);
    documentField.addEventListener('focusout', lookup);
    form.addEventListener('reset', (event) => {
        event.preventDefault();
        reset();
        [documentField, ...fields].forEach((field) => {
            field.value = '';
            field.setCustomValidity?.('');
            field.removeAttribute?.('aria-invalid');
            field.removeAttribute?.('aria-describedby');
        });
        form.querySelectorAll('.field-error, .notice-error').forEach((message) => { message.hidden = true; });
        form.scrollTop = 0;
        form.elements.namedItem('name').focus?.({ preventScroll: true });
    });
    if (documentField.value) lookup();
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-registration-lookup-url]').forEach((form) => initializeSupplierRegistration(form));
}
