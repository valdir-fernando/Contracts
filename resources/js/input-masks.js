export function maskCpf(value) {
    const digits = value.replace(/\D/g, '').slice(0, 11);
    return [digits.slice(0, 3), digits.slice(3, 6), digits.slice(6, 9)].filter(Boolean).join('.')
        + (digits.length > 9 ? `-${digits.slice(9)}` : '');
}

export function maskCnpj(value) {
    const digits = value.replace(/\D/g, '').slice(0, 14);
    return [digits.slice(0, 2), digits.slice(2, 5), digits.slice(5, 8)].filter(Boolean).join('.')
        + (digits.length > 8 ? `/${digits.slice(8, 12)}` : '')
        + (digits.length > 12 ? `-${digits.slice(12)}` : '');
}

export function maskPhone(value) {
    const digits = value.replace(/\D/g, '').slice(0, 11);
    if (!digits) return '';
    if (digits.length <= 2) return `(${digits}`;
    const local = digits.slice(2);
    const prefix = local.length > 8 ? 5 : 4;
    return `(${digits.slice(0, 2)}) ${local.slice(0, prefix)}${local.length > prefix ? `-${local.slice(prefix)}` : ''}`;
}

export function maskPis(value) {
    const digits = value.replace(/\D/g, '').slice(0, 11);
    return [digits.slice(0, 3), digits.slice(3, 8), digits.slice(8, 10)].filter(Boolean).join('.')
        + (digits.length > 10 ? `-${digits.slice(10)}` : '');
}

export function maskDate(value) {
    if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return value.split('-').reverse().join('/');
    const digits = value.replace(/\D/g, '').slice(0, 8);
    return [digits.slice(0, 2), digits.slice(2, 4), digits.slice(4)].filter(Boolean).join('/');
}

export function isoDate(value) {
    if (/^\d{4}-\d{2}-\d{2}$/.test(value)) value = maskDate(value);
    const match = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(value);
    if (!match) return null;
    const [, day, month, year] = match;
    const iso = `${year}-${month}-${day}`;
    const parsed = new Date(`${iso}T12:00:00Z`);
    return !Number.isNaN(parsed.valueOf()) && parsed.toISOString().slice(0, 10) === iso ? iso : null;
}

export function dateInputAllowed(value) {
    const digits = value.replace(/\D/g, '');
    if (digits.length >= 2 && (+digits.slice(0, 2) < 1 || +digits.slice(0, 2) > 31)) return false;
    if (digits.length < 4) return true;
    const month = +digits.slice(2, 4);
    if (month < 1 || month > 12) return false;
    // A leap year permits February 29 while the user is still entering the year.
    const year = digits.length === 8 ? digits.slice(4) : '2000';
    return isoDate(`${digits.slice(0, 2)}/${digits.slice(2, 4)}/${year}`) !== null;
}

export function attachMask(input, formatter, allowed = /^[\d\s().-]*$/, acceptable = () => true) {
    input.inputMode = 'numeric';
    input.value = formatter(input.value);
    let lastAccepted = acceptable(input.value) ? input.value : '';
    input.value = lastAccepted;
    input.addEventListener('beforeinput', (event) => {
        if (!event.data) return;
        const start = input.selectionStart ?? input.value.length;
        const end = input.selectionEnd ?? start;
        const next = input.value.slice(0, start) + event.data + input.value.slice(end);
        if (!allowed.test(event.data) || !acceptable(formatter(next))) event.preventDefault();
    });
    input.addEventListener('input', () => {
        const start = input.selectionStart;
        const count = input.value.slice(0, start ?? input.value.length).replace(/\D/g, '').length;
        const formatted = formatter(input.value);
        input.value = acceptable(formatted) ? formatted : lastAccepted;
        lastAccepted = input.value;
        if (start === null || start === undefined) return;
        let remaining = count;
        let caret = 0;
        while (caret < input.value.length && remaining > 0) {
            if (/\d/.test(input.value[caret])) remaining--;
            caret++;
        }
        input.setSelectionRange(caret, caret);
    });
}
export function normalizeFormDates(form, formData) {
    Array.from(form.elements).filter((input) => input.matches('[data-brazilian-date][name]:not(:disabled)')).forEach((input) => {
        formData.set(input.name, isoDate(input.value) ?? input.value);
    });
}
