document.querySelectorAll('[data-field-help]').forEach((button) => {
    const popup = document.getElementById(button.dataset.fieldHelp);
    if (!popup || !popup.showPopover) return;
    let timer;
    const position = () => {
        const anchor = button.getBoundingClientRect();
        const bounds = popup.getBoundingClientRect();
        popup.style.left = `${Math.max(8, Math.min(anchor.left, innerWidth - bounds.width - 8))}px`;
        popup.style.top = `${Math.max(8, Math.min(anchor.bottom + 8, innerHeight - bounds.height - 8))}px`;
    };
    const show = () => {
        clearTimeout(timer);
        popup.showPopover();
        position();
    };
    const close = () => {
        clearTimeout(timer);
        timer = setTimeout(() => {
            if (!button.matches(':hover, :focus') && !popup.matches(':hover, :focus-within')) popup.hidePopover();
        }, 150);
    };
    button.addEventListener('mouseenter', show);
    button.addEventListener('focus', show);
    button.addEventListener('click', (event) => { event.preventDefault(); show(); });
    button.addEventListener('mouseleave', close);
    button.addEventListener('blur', close);
    popup.addEventListener('mouseenter', () => clearTimeout(timer));
    popup.addEventListener('mouseleave', close);
    popup.addEventListener('toggle', (event) => { if (event.newState === 'open') position(); });
    popup.querySelectorAll('img').forEach((image) => {
        image.addEventListener('load', () => { if (popup.matches(':popover-open')) position(); });
    });
    window.addEventListener('resize', () => { if (popup.matches(':popover-open')) position(); });
    document.addEventListener('scroll', () => { if (popup.matches(':popover-open')) position(); }, true);
});
