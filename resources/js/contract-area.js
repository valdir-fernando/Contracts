export function formatArea(value) {
    const text = value.trim().replace(/\s*m[²2]\s*$/i, '').trim();
    if (!text) return '';
    const normalized = text.includes(',') ? text.replace(/\./g, '').replace(',', '.') : text;
    if (!/^\d+(?:\.\d+)?$/.test(normalized)) return value;
    const [whole, fraction = ''] = normalized.split('.');
    let hundredths = BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0').slice(0, 2));
    if (fraction.length > 2 && fraction[2] >= '5') hundredths++;
    const integer = (hundredths / 100n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return `${integer},${(hundredths % 100n).toString().padStart(2, '0')}M²`;
}

export function initializeArea(input) {
    const format = () => { input.value = formatArea(input.value); };
    format();
    input.addEventListener('blur', format);
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('input[name="areaterreno"], input[name="areaconstruida"]').forEach(initializeArea);
}
