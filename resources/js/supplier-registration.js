import { formatSupplierDocument } from './supplier-assistance.js';
import { attachMask, maskPhone, maskPis } from './input-masks.js';

const initializedForms = new WeakSet();

export function navigateSupplierFields(event, form) {
    if (event.key !== 'Tab' || event.altKey || event.ctrlKey || event.metaKey) return;
    const fields = [...form.querySelectorAll('input[name]:not([type="hidden"]), select[name], textarea[name]')]
        .filter((field) => !field.disabled && field.getClientRects().length > 0);
    const current = fields.indexOf(event.target);
    if (current === -1) return;
    const next = fields[current + (event.shiftKey ? -1 : 1)];
    if (!next) return;
    event.preventDefault();
    next.focus();
}

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

export function validSupplierDocument(value) {
    if (!/^[\d\s.\/-]+$/.test(value)) return false;
    const digits = value.replace(/\D/g, '');
    if (![11, 14].includes(digits.length) || /^(\d)\1+$/.test(digits)) return false;
    const base = digits.length === 11 ? 9 : 12;
    for (let round = 0; round < 2; round++) {
        let sum = 0;
        for (let i = 0; i < base + round; i++) {
            const weight = base === 9 ? base + round + 1 - i : ((base + round - 1 - i) % 8) + 2;
            sum += Number(digits[i]) * weight;
        }
        const check = sum % 11 < 2 ? 0 : 11 - sum % 11;
        if (Number(digits[base + round]) !== check) return false;
    }
    return true;
}

export function initializeSupplierRegistration(form, fetcher = fetch, notify = (message) => {
    if (typeof document !== 'undefined') showSupplierDocumentWarning(message);
}) {
    if (initializedForms.has(form)) return;
    initializedForms.add(form);
    form.addEventListener('keydown', (event) => navigateSupplierFields(event, form));
    let requiredWarningPending = false;
    form.addEventListener('invalid', (event) => {
        if (!event.target.validity.valueMissing) return;
        event.preventDefault();
        event.target.setAttribute?.('aria-invalid', 'true');
        if (requiredWarningPending) return;
        requiredWarningPending = true;
        queueMicrotask(() => {
            requiredWarningPending = false;
            notify('Confira os campos indicados para concluir o cadastro.');
        });
    }, true);
    form.addEventListener('input', (event) => {
        if (event.target.required && !event.target.validity.valueMissing) event.target.removeAttribute('aria-invalid');
    });
    const documentField = form.elements.namedItem('document');
    const updateDocumentFields = () => {
        const document = documentField.value;
        const length = document.replace(/\D/g, '').length;
        const type = validSupplierDocument(document) ? (length === 11 ? 'cpf' : 'cnpj') : null;
        const tradeNameInput = form.elements.namedItem('Nome_Fantasia');
        const tradeName = tradeNameInput?.closest('.field');
        if (tradeName) tradeName.hidden = type === 'cpf';
        if (tradeNameInput) tradeNameInput.disabled = type === 'cpf';
        const partner = form.elements.namedItem('Nome_Socio')?.closest('fieldset');
        if (partner) {
            partner.hidden = type === 'cpf';
            partner.disabled = type === 'cpf';
        }
        for (const name of ['Data_Nasc', 'Sexo', 'PIS_PASEP', 'Doc_Prof']) {
            const input = form.elements.namedItem(name);
            const field = input?.closest?.('.field');
            if (field) field.hidden = type === 'cnpj';
            if (input) input.disabled = type === 'cnpj';
        }
    };
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
        status.className = 'hint';
        documentField.setCustomValidity?.('');
        documentField.removeAttribute?.('aria-invalid');
        for (const [field, { before, automatic }] of filled) {
            if (field.value === automatic) field.value = before;
        }
        filled.clear();
    };
    const lookup = async (showWarning = true) => {
        request?.abort();
        const attempt = new AbortController();
        request = attempt;
        documentField.value = formatSupplierDocument(documentField.value);
        updateDocumentFields();
        const document = digits();
        if (!validSupplierDocument(documentField.value)) {
            documentField.setAttribute?.('aria-invalid', 'true');
            const type = document.length === 11 ? 'CPF' : document.length === 14 ? 'CNPJ' : 'CPF/CNPJ';
            const message = `${type} ${documentField.value} é um ${type} inválido. Verifique antes de efetuar o registro.`;
            documentField.setCustomValidity?.(message);
            status.textContent = '';
            if (showWarning && documentField.value.trim()) notify(message);
            return;
        }
        documentField.setCustomValidity?.('');
        documentField.removeAttribute?.('aria-invalid');
        status.className = 'hint';
        if (form.dataset.supplierEditing === 'true') {
            status.textContent = '';
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
    documentField.addEventListener('input', () => {
        reset();
        updateDocumentFields();
    });
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
        updateDocumentFields();
        form.querySelectorAll('.field-error, .notice-error').forEach((message) => { message.hidden = true; });
        form.scrollTop = 0;
        documentField.focus?.({ preventScroll: true });
    });
    updateDocumentFields();
    if (documentField.value) lookup(false);
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-registration-lookup-url]').forEach((form) => initializeSupplierRegistration(form));
    document.addEventListener('focusin', (event) => {
        const form = event.target.closest?.('[data-registration-lookup-url]');
        if (form) initializeSupplierRegistration(form);
    });
}
