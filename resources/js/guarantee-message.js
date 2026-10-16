export function initializeGuaranteeMessage(field, message) {
    message.hidden = true;
    field.addEventListener('input', () => { message.hidden = true; });
    field.addEventListener('blur', () => { message.hidden = field.value.trim() === ''; });
}

if (typeof document !== 'undefined') {
    const field = document.querySelector('[name="vl_garantia"]');
    const message = document.querySelector('[data-guarantee-message]');
    if (field && message) initializeGuaranteeMessage(field, message);
}
