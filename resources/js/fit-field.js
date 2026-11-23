export function initializeFittedField(field) {
    const document = field.ownerDocument;
    const context = document.createElement('canvas').getContext('2d');
    if (!context) return;

    function fit() {
        field.style.fontSize = '';
        if (!field.clientWidth) return;
        const style = document.defaultView.getComputedStyle(field);
        const text = field.selectedOptions ? field.selectedOptions[0]?.label ?? '' : field.value;
        const available = field.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight) - 1;
        context.font = `${style.fontStyle} ${style.fontWeight} ${style.fontSize} ${style.fontFamily}`;
        const width = context.measureText(text).width;
        if (available > 0 && width > available) {
            const fittedSize = Math.floor(parseFloat(style.fontSize) * available / width * 10) / 10;
            field.style.fontSize = `${field.selectedOptions ? fittedSize : Math.max(8, fittedSize)}px`;
        }
    }

    let width;
    new ResizeObserver(() => {
        if (field.clientWidth === width) return;
        width = field.clientWidth;
        fit();
    }).observe(field);
    field.addEventListener(field.selectedOptions ? 'change' : 'input', fit);
    field.addEventListener('fit-field', fit);
    document.fonts?.ready.then(fit);
    fit();
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-fit-field]').forEach(initializeFittedField);
}
