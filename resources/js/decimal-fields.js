// String-only formatting: no binary floating point or rounding of stored values.
export function formatDecimalField(value) {
    let text = String(value ?? '').trim();
    if (!text || text === 'Não informado' || text === '—') return '0,0000';
    if (text.includes(',')) {
        if (!/^-?(?:\d+|\d{1,3}(?:\.\d{3})+)(?:,\d{0,4})?$/.test(text)) return null;
        text = text.replaceAll('.', '').replace(',', '.');
    }
    const match = /^(-?)(\d+)(?:\.(\d{0,4}))?$/.exec(text);
    if (!match) return null;
    const [, sign, whole, fraction = ''] = match;
    const integer = whole.replace(/^0+(?=\d)/, '').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return `${sign}${integer},${fraction.padEnd(4, '0')}`;
}

const selector = 'input[inputmode="decimal"], input[data-money], input[data-quantity]';
const states = new WeakMap();

export function initializeDecimalInput(input) {
    if (states.has(input)) return states.get(input);
    const state = { blank: !input.value.trim(), displayed: null };
    input.dataset.decimalKind = input.hasAttribute('data-quantity') ? 'quantity' : 'money';
    const format = () => {
        if (input.value !== state.displayed) state.blank = !input.value.trim();
        const formatted = formatDecimalField(input.value);
        if (formatted === null) return;
        input.value = formatted;
        state.displayed = formatted;
    };
    state.format = format;
    states.set(input, state);
    input.addEventListener('input', () => { state.blank = !input.value.trim(); state.displayed = null; });
    input.addEventListener('focus', () => { if (!input.readOnly && input.value === '0,0000') input.select(); });
    format();
    return state;
}

export function decimalSubmissionValue(input) {
    const state = initializeDecimalInput(input);
    state.format();
    return state.blank ? '' : input.value;
}

export function initializeDecimalFields(doc) {
    const scan = () => {
        doc.querySelectorAll(selector).forEach((input) => {
            const state = initializeDecimalInput(input);
            if (doc.activeElement !== input) state.format();
        });
        doc.querySelectorAll('[data-money]:not(input)').forEach((field) => {
            const formatted = formatDecimalField(field.textContent);
            if (formatted !== null && field.textContent !== formatted) field.textContent = formatted;
        });
    };
    scan();
    doc.addEventListener('focusout', (event) => {
        if (event.target.matches(selector)) initializeDecimalInput(event.target).format();
    });
    doc.addEventListener('submit', (event) => {
        event.target.querySelectorAll(selector).forEach(input => initializeDecimalInput(input).format());
    }, true);
    doc.addEventListener('formdata', (event) => {
        event.target.querySelectorAll(selector).forEach((input) => {
            if (!input.name || input.disabled || !event.formData.has(input.name)) return;
            // An empty optional value remains empty on the server; zero is only its display.
            event.formData.set(input.name, decimalSubmissionValue(input));
        });
    });
    doc.addEventListener('reset', (event) => queueMicrotask(() => {
        event.target.querySelectorAll(selector).forEach(input => initializeDecimalInput(input).format());
    }));
    new MutationObserver(scan).observe(doc.body, { childList: true, subtree: true, characterData: true });
}
