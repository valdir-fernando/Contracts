export function formatGuaranteeAmount(value) {
    const input = value.trim();
    if (!input) return '';
    const localized = input.includes(',');
    if (!(localized ? /^-?(?:\d{1,3}(?:\.\d{3})+|\d+),\d{1,4}$/ : /^-?\d+(?:\.\d{1,4})?$/).test(input)) return value;
    const normalized = localized ? input.replaceAll('.', '').replace(',', '.') : input;
    const negative = normalized.startsWith('-');
    const [whole, decimal = ''] = normalized.replace(/^-/, '').split('.');
    const integer = whole.replace(/^0+(?=\d)/, '');
    const fraction = decimal.replace(/0+$/, '').padEnd(2, '0');
    return `${negative ? '-' : ''}${integer.replace(/\B(?=(\d{3})+(?!\d))/g, '.')},${fraction}`;
}

const units = ['zero', 'um', 'dois', 'três', 'quatro', 'cinco', 'seis', 'sete', 'oito', 'nove', 'dez', 'onze', 'doze', 'treze', 'catorze', 'quinze', 'dezesseis', 'dezessete', 'dezoito', 'dezenove'];
const tens = ['', '', 'vinte', 'trinta', 'quarenta', 'cinquenta', 'sessenta', 'setenta', 'oitenta', 'noventa'];
const hundreds = ['', 'cento', 'duzentos', 'trezentos', 'quatrocentos', 'quinhentos', 'seiscentos', 'setecentos', 'oitocentos', 'novecentos'];

function groupWords(number) {
    if (number < 20) return units[number];
    if (number < 100) return tens[Math.floor(number / 10)] + (number % 10 ? ` e ${units[number % 10]}` : '');
    if (number === 100) return 'cem';
    return hundreds[Math.floor(number / 100)] + (number % 100 ? ` e ${groupWords(number % 100)}` : '');
}

function integerWords(number) {
    if (number === 0n) return 'zero';
    const scales = [['', ''], ['mil', 'mil'], ['milhão', 'milhões'], ['bilhão', 'bilhões'], ['trilhão', 'trilhões']];
    const groups = [];
    for (let index = 0; number > 0n; index++, number /= 1000n) {
        const value = Number(number % 1000n);
        if (!value) continue;
        const text = index === 1 && value === 1 ? 'mil' : groupWords(value) + (index ? ` ${scales[index][value === 1 ? 0 : 1]}` : '');
        groups.unshift({ value, text });
    }
    return groups.map((group, index) => (index ? (index === groups.length - 1 && (group.value < 100 || group.value % 100 === 0) ? ' e ' : ', ') : '') + group.text).join('');
}

export function guaranteeAmountWords(value) {
    const formatted = formatGuaranteeAmount(value);
    if (!/^-?\d{1,3}(?:\.\d{3})*,\d{2,4}$/.test(formatted)) return '';
    const [whole, fraction] = formatted.replaceAll('.', '').replace(/^-/, '').split(',');
    if (whole.length > 14) return '';
    // Monetary words use cents, rounded from the exact decimal string.
    const centsTotal = BigInt(whole) * 100n + BigInt(fraction.slice(0, 2)) + (Number(fraction[2] || 0) >= 5 ? 1n : 0n);
    const reais = centsTotal / 100n;
    const cents = Number(centsTotal % 100n);
    let text = reais ? `${integerWords(reais)}${reais % 1000000n === 0n ? ' de' : ''} ${reais === 1n ? 'real' : 'reais'}` : '';
    if (cents) text += `${text ? ' e ' : ''}${groupWords(cents)} ${cents === 1 ? 'centavo' : 'centavos'}`;
    return (formatted.startsWith('-') && centsTotal ? 'menos ' : '') + (text || 'zero reais');
}

export function initializeGuaranteeMessage(field, message, notice = null) {
    field.value = formatGuaranteeAmount(field.value);
    const original = field.value;
    const saved = message.dataset?.contractSaved === 'true';
    const updateNotice = () => {
        if (notice) notice.hidden = formatGuaranteeAmount(field.value) === original;
    };
    message.hidden = saved ? !message.textContent : true;
    field.addEventListener('input', () => {
        if (!saved) message.hidden = true;
        updateNotice();
    });
    field.addEventListener('blur', () => {
        field.value = formatGuaranteeAmount(field.value);
        if (!saved) message.textContent = guaranteeAmountWords(field.value);
        message.hidden = !message.textContent;
        updateNotice();
    });
}

if (typeof document !== 'undefined') {
    const field = document.querySelector('[name="vl_garantia"]');
    const message = document.querySelector('[data-guarantee-message]');
    if (field && message) initializeGuaranteeMessage(field, message, document.querySelector('[data-guarantee-save-notice]'));
}
