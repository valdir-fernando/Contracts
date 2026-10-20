import { showSupplierDocumentWarning } from './system-message.js';

export function formatGuaranteePercentage(value) {
    const text = value.trim().replace(/\s*%$/, '').trim();
    if (!text) return '';
    const normalized = text.includes(',') ? text.replace(/\./g, '').replace(',', '.') : text;
    if (!/^\d+(?:\.\d+)?$/.test(normalized)) return value;
    const [whole, fraction = ''] = normalized.split('.');
    let hundredths = BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0').slice(0, 2));
    if (fraction.length > 2 && fraction[2] >= '5') hundredths++;
    return `${hundredths / 100n},${(hundredths % 100n).toString().padStart(2, '0')}%`;
}

export function initializeGuaranteePercentage(input, notify = (message) => showSupplierDocumentWarning(message, input.ownerDocument)) {
    const validate = () => {
        const text = input.value.trim().replace(/%$/, '').trim().replace(',', '.');
        const valid = !text || /^\d+(?:\.\d{1,2})?$/.test(text) && Number(text) >= 0.01 && Number(text) <= 100;
        input.setCustomValidity?.(valid ? '' : 'Informe uma garantia entre 0,01% e 100,00%.');
        return valid;
    };
    const clearInvalid = () => {
        input.value = '';
        input.setCustomValidity?.('');
        input.dispatchEvent?.(new Event('input', { bubbles: true }));
        notify('O percentual de garantia deve estar entre 0,01% e 100,00%. O campo foi limpo. Informe um valor válido.');
    };
    const format = (event) => {
        if (!validate()) {
            clearInvalid();
            event?.preventDefault?.();
            return;
        }
        input.value = formatGuaranteePercentage(input.value);
    };
    input.value = formatGuaranteePercentage(input.value);
    validate();
    input.addEventListener('focus', () => {
        input.value = input.value.replace(/%$/, '');
    });
    input.addEventListener('beforeinput', (event) => {
        if (event.isComposing || !event.inputType?.startsWith('insert') || event.data === null) return;
        const start = input.selectionStart ?? input.value.length;
        const end = input.selectionEnd ?? start;
        const next = input.value.slice(0, start) + event.data + input.value.slice(end);
        if (!/^\d*(?:,\d{0,2})?$/.test(next)) event.preventDefault();
    });
    input.addEventListener('input', () => {
        const position = input.selectionStart;
        const clean = input.value.replace(/[^\d,]/g, '');
        const [whole, ...fractions] = clean.split(',');
        const value = whole + (fractions.length ? ',' + fractions.join('').slice(0, 2) : '');
        const numeric = value.trim().replace(/%$/, '').trim().replace(',', '.');
        if (/^\d+(?:\.\d*)?$/.test(numeric) && Number(numeric) > 100) {
            clearInvalid();
            return;
        }
        if (value !== input.value) {
            input.value = value;
            if (position !== null && position !== undefined) {
                const caret = Math.min(position, value.length);
                input.setSelectionRange?.(caret, caret);
            }
        }
        validate();
    });
    input.addEventListener('blur', format);
    input.form?.addEventListener('submit', format, true);
    input.form?.addEventListener('formdata', (event) => {
        if (input.disabled) return;
        format();
        event.formData.set(input.name, input.value);
    });
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('input[name="garantia"]').forEach(input => initializeGuaranteePercentage(input));
}
