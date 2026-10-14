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
        const available = heading.parentElement.clientWidth;
        if (width > available && available > 0) heading.style.fontSize = `${size * available / width}px`;
    };
    new ResizeObserver(fit).observe(heading.parentElement);
    document.fonts.ready.then(fit);
    fit();
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-fit-fund-name]').forEach(initializeFundHeading);
}
