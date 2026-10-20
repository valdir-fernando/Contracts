const selector = 'textarea, input:not([type]), input[type="text"], input[type="search"], input[type="email"], input[type="tel"], input[type="url"], input[type="password"]';

export function trimField(field) {
    if (!field.matches?.(selector) || field.disabled || field.readOnly) return;
    const value = field.value.trim();
    if (value === field.value) return;
    field.value = value;
    field.dispatchEvent(new Event('input', { bubbles: true }));
}

export function initializeFieldTrimming(ownerDocument) {
    ownerDocument.addEventListener('blur', (event) => trimField(event.target), true);
    ownerDocument.addEventListener('submit', (event) => {
        event.target.querySelectorAll(selector).forEach(trimField);
    }, true);
    // Also covers FormData used by asynchronous saves without a submit event.
    ownerDocument.addEventListener('formdata', (event) => {
        const fields = event.target.querySelectorAll(selector);
        const names = new Set();
        fields.forEach((field) => {
            trimField(field);
            if (field.name && !field.disabled && !field.readOnly) names.add(field.name);
        });
        names.forEach((name) => {
            const values = event.formData.getAll(name);
            event.formData.delete(name);
            values.forEach((value) => event.formData.append(name, typeof value === 'string' ? value.trim() : value));
        });
    }, true);
}

if (typeof document !== 'undefined') initializeFieldTrimming(document);
