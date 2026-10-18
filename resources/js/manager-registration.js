import { attachMask, maskCpf, maskPhone } from './input-masks.js';
import { validSupplierDocument } from './supplier-registration.js';
import { showSupplierDocumentWarning } from './system-message.js';

export function initializeManagerRegistration(form, notify = (text, onClose) => {
    showSupplierDocumentWarning(text, form.ownerDocument);
    if (onClose) form.ownerDocument.querySelector('[data-supplier-document-warning]').addEventListener('close', onClose, { once: true });
}) {
    const name = form.elements.namedItem('Nome_Gestor');
    if (name) {
        const uppercase = () => {
            const start = name.selectionStart;
            const end = name.selectionEnd;
            name.value = name.value.toLocaleUpperCase('pt-BR');
            if (start !== null && end !== null) name.setSelectionRange(start, end);
        };
        uppercase();
        name.addEventListener('input', (event) => { if (!event.isComposing) uppercase(); });
        name.addEventListener('compositionend', uppercase);
    }
    for (const key of ['Fone_Celular', 'WhatsApp']) {
        const input = form.elements.namedItem(key);
        if (input) attachMask(input, maskPhone);
    }
    const cpf = form.elements.namedItem('CPF_Gestor');
    const message = form.querySelector('[data-manager-cpf-error]');
    if (!cpf || !message) return;
    attachMask(cpf, maskCpf);
    const show = (text) => {
        message.textContent = text;
        message.hidden = !text;
        cpf.setAttribute('aria-invalid', String(Boolean(text)));
        cpf.setCustomValidity(text);
    };
    const validate = () => {
        const value = cpf.value.trim();
        const text = !value ? 'Informe o CPF do gestor.'
            : (value.replace(/\D/g, '').length !== 11 || !validSupplierDocument(value) ? 'Informe um CPF válido.' : '');
        show(text);
        return !text;
    };
    const reveal = () => form.dispatchEvent(new CustomEvent('manager-cpf-invalid'));
    let warnedValue = null;
    const warn = () => {
        if (warnedValue === cpf.value) return;
        warnedValue = cpf.value;
        notify(message.textContent, () => { warnedValue = null; reveal(); });
    };
    cpf.addEventListener('blur', () => { if (!validate()) warn(); });
    cpf.addEventListener('input', () => {
        warnedValue = null;
        if (cpf.value.replace(/\D/g, '').length === 11) validate();
        else show('');
    });
    cpf.addEventListener('invalid', (event) => { event.preventDefault(); validate(); reveal(); warn(); });
    cpf.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' || event.isComposing || event.keyCode === 229
            || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
        event.preventDefault();
        if (validate()) form.elements.namedItem('RG_Gestor')?.focus();
        else { reveal(); warn(); }
    });
    form.addEventListener('submit', (event) => {
        if (validate()) return;
        event.preventDefault();
        reveal();
        warn();
    });
    let pendingField = null;
    form.addEventListener('invalid', (event) => {
        if (event.target === cpf) return;
        event.preventDefault();
        if (pendingField) return;
        pendingField = event.target;
        queueMicrotask(() => {
            const field = pendingField;
            pendingField = null;
            const text = field.validity.valueMissing ? 'Confira os campos obrigatórios para concluir o cadastro.'
                : field.type === 'email' ? 'Informe um email válido para o gestor.' : 'Confira o valor informado.';
            notify(text, () => form.dispatchEvent(new CustomEvent('manager-field-invalid', { detail: { field } })));
        });
    }, true);
    const feedback = form.querySelector('[data-manager-feedback]');
    if (feedback) {
        const messages = JSON.parse(feedback.textContent);
        if (messages.length) notify(messages.join('\n'));
    }
}

if (typeof document !== 'undefined') document.querySelectorAll('[data-manager-form]').forEach((form) => initializeManagerRegistration(form));
