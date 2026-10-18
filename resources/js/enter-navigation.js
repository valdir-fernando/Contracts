const fields = 'input, select, textarea';
const textTypes = new Set(['text', 'search', 'email', 'url', 'tel', 'password', 'number']);

function available(control) {
    return !control.matches(':disabled, [readonly], [tabindex="-1"]')
        && !control.closest('[hidden], [inert], [aria-hidden="true"]')
        && control.getClientRects().length > 0
        && getComputedStyle(control).visibility === 'visible';
}

export function focusNextEntry(field) {
    const scope = field.closest('[data-enter-scope], form, dialog')
        ?? field.closest('main') ?? field.ownerDocument.body;
    const controls = [...scope.querySelectorAll(`${fields}, button, a[href]`)];
    const index = controls.indexOf(field);
    if (index < 0) return;
    const following = controls.slice(index + 1).filter(available);
    const nextField = following.find((control) => control.matches(fields)
        && !control.matches('input[type="hidden"], input[type="button"], input[type="submit"], input[type="reset"], input[type="image"]'));
    const action = following.find((control) => control.matches('[data-enter-action], button[type="submit"], input[type="submit"], button:not([type])'))
        ?? following.find((control) => control.matches('button, a[href], input[type="button"], input[type="reset"], input[type="image"]'));
    (nextField ?? action)?.focus();
}

export function handleEntryEnter(event) {
    if (event.key !== 'Enter' || event.defaultPrevented || event.isComposing
        || event.keyCode === 229 || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
    const field = event.target;
    if (!field?.matches?.('input, textarea') || field.matches('input[list]')
        || field.closest('[data-enter-native], [role="combobox"], [role="listbox"], [contenteditable="true"]')) return;
    if (field.matches('input[type="checkbox"], input[type="radio"]')) {
        event.preventDefault();
        field.click();
        return;
    }
    if (!(field.tagName === 'INPUT' && textTypes.has(field.type))
        && !(field.tagName === 'TEXTAREA' && field.rows === 1)) return;
    event.preventDefault();
    focusNextEntry(field);
}

// Delegation includes forms and controls inserted by Livewire and dialogs.
if (typeof document !== 'undefined') document.addEventListener('keydown', handleEntryEnter);
