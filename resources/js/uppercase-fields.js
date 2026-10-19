export function uppercaseField(field) {
    const start = field.selectionStart;
    const end = field.selectionEnd;
    field.value = field.value.toLocaleUpperCase('pt-BR');
    if (start !== null && start !== undefined) field.setSelectionRange(start, end);
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-uppercase]').forEach((field) => {
        uppercaseField(field);
        field.addEventListener('input', (event) => {
            if (!event.isComposing) uppercaseField(field);
        });
        field.addEventListener('compositionend', () => uppercaseField(field));
    });
}
