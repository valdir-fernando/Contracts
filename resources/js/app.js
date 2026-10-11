document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const input = document.getElementById(button.getAttribute('aria-controls'));
        const visible = input.type === 'password';
        input.type = visible ? 'text' : 'password';
        button.setAttribute('aria-pressed', String(visible));
        button.setAttribute('aria-label', visible ? 'Ocultar senha' : 'Mostrar senha');
    });
});
document.querySelectorAll('[data-login-form]').forEach((form) => {
    form.addEventListener('submit', () => {
        form.querySelector('[data-submit-button]').disabled = true;
        form.querySelector('[data-submit-label]').textContent = 'Entrando…';
    });
});
window.addEventListener('pageshow', () => {
    document.querySelectorAll('[data-submit-button]').forEach((button) => {
        button.disabled = false;
        button.querySelector('[data-submit-label]').textContent = 'Entrar';
    });
});
document.addEventListener('focusout', (event) => {
    const input = event.target;
    if (!input.matches('[data-document-format]') || !/^[\d\s.\/-]+$/.test(input.value)) return;
    const digits = input.value.replace(/\D/g, '');
    if (digits.length === 11) {
        input.value = digits.replace(/^(\d{3})(\d{3})(\d{3})(\d{2})$/, '$1.$2.$3-$4');
    } else if (digits.length === 14) {
        input.value = digits.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/, '$1.$2.$3/$4-$5');
    }
});

document.querySelectorAll('[data-menu-toggle]').forEach((button) => {
    const sidebar = button.closest('.workspace-nav');
    const media = window.matchMedia('(max-width: 800px)');
    const setOpen = (open) => {
        sidebar.dataset.collapsed = String(!open);
        button.setAttribute('aria-expanded', String(open));
    };
    setOpen(!media.matches);
    button.addEventListener('click', () => setOpen(button.getAttribute('aria-expanded') !== 'true'));
    media.addEventListener('change', () => setOpen(!media.matches));
});
