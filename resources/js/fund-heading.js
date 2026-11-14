export function initializeFundHeading(heading) {
    const canvas = document.createElement('canvas');
    const context = canvas.getContext('2d');
    if (!context) return;
    const fit = () => {
        heading.style.fontSize = '';
        const style = getComputedStyle(heading);
        const size = parseFloat(style.fontSize);
        context.font = `${style.fontWeight} ${style.fontSize} ${style.fontFamily}`;
        const width = context.measureText(heading.textContent.trim()).width;
        const parentStyle = getComputedStyle(heading.parentElement);
        const available = heading.parentElement.clientWidth - parseFloat(parentStyle.paddingLeft || '0') - parseFloat(parentStyle.paddingRight || '0');
        if (width > available && available > 0) heading.style.fontSize = `${size * available / width}px`;
    };
    new ResizeObserver(fit).observe(heading.parentElement);
    new MutationObserver(fit).observe(heading, { childList: true, characterData: true, subtree: true });
    document.fonts.ready.then(fit);
    fit();
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-fit-fund-name], [data-fit-supplier-name]').forEach(initializeFundHeading);
}
