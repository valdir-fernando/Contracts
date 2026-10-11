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
    const media = window.matchMedia('(max-width: 1024px)');
    const setOpen = (open) => {
        sidebar.dataset.collapsed = String(!open);
        button.setAttribute('aria-expanded', String(open));
        button.setAttribute('aria-label', open ? 'Fechar menu de navegação' : 'Abrir menu de navegação');
    };
    setOpen(!media.matches);
    button.addEventListener('click', () => setOpen(button.getAttribute('aria-expanded') !== 'true'));
    media.addEventListener('change', () => setOpen(!media.matches));
    sidebar.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && media.matches) {
            setOpen(false);
            button.focus();
        }
    });
});

// Desktop density is a saved preference; mobile menu visibility stays independent.
document.querySelectorAll('[data-sidebar-toggle]').forEach((button) => {
    const root = document.documentElement;
    const sync = () => {
        const collapsed = root.dataset.sidebarCollapsed === 'true';
        const label = collapsed ? 'Expandir barra lateral' : 'Recolher barra lateral';
        button.setAttribute('aria-expanded', String(!collapsed));
        button.setAttribute('aria-label', label);
        button.title = label;
    };
    button.addEventListener('click', () => {
        root.dataset.sidebarCollapsed = String(root.dataset.sidebarCollapsed !== 'true');
        try { localStorage.setItem('contracts.sidebar-collapsed', root.dataset.sidebarCollapsed); } catch {}
        sync();
    });
    window.addEventListener('pageshow', sync);
    window.addEventListener('storage', (event) => {
        if (event.key === 'contracts.sidebar-collapsed' || event.key === null) {
            root.dataset.sidebarCollapsed = String(event.newValue === 'true');
            sync();
        }
    });
    sync();
});

// Make wide registries discoverable and reachable with a keyboard.
let tableHintId = 0;
const tableObserver = new ResizeObserver((entries) => {
    entries.forEach(({ target }) => {
        const scroller = target.matches('.table-scroll') ? target : target.closest('.table-scroll');
        if (!scroller) return;
        const overflow = scroller.scrollWidth > scroller.clientWidth + 1;
        const hint = scroller.previousElementSibling;
        if (hint?.classList.contains('table-scroll-hint')) hint.hidden = !overflow;
        if (overflow) {
            scroller.tabIndex = 0;
            scroller.setAttribute('role', 'region');
            scroller.setAttribute('aria-label', 'Tabela com rolagem horizontal');
            if (hint) scroller.setAttribute('aria-describedby', hint.id);
        } else {
            scroller.removeAttribute('tabindex');
            scroller.removeAttribute('role');
            scroller.removeAttribute('aria-label');
            scroller.removeAttribute('aria-describedby');
        }
    });
});
document.querySelectorAll('.table-scroll').forEach((scroller) => {
    const hint = document.createElement('p');
    hint.className = 'table-scroll-hint';
    hint.id = `table-scroll-hint-${++tableHintId}`;
    hint.textContent = '↔ Deslize a tabela para ver todas as colunas';
    hint.hidden = true;
    scroller.before(hint);
    tableObserver.observe(scroller);
    const table = scroller.querySelector('table');
    if (table) tableObserver.observe(table);
});

// Bring the selected form section into view after Alpine updates visibility.
document.addEventListener('editor-section-change', (event) => {
    const section = document.querySelector(`[data-editor-section="${event.detail.step}"]`);
    if (!section) return;
    section.scrollIntoView({ block: 'start', behavior: 'instant' });
    section.querySelector('h2')?.focus({ preventScroll: true });
});

// Keep filters close at hand without pushing results below the fold on phones.
document.querySelectorAll('[data-mobile-disclosure]').forEach((details) => {
    const media = window.matchMedia('(max-width: 600px)');
    const sync = () => { details.open = !media.matches || details.dataset.activeFilters === 'true'; };
    sync();
    media.addEventListener('change', sync);
});

// Icon-only navigation gets readable labels without clipping in the sidebar.
const navTooltip = document.createElement('div');
navTooltip.className = 'nav-tooltip';
navTooltip.id = 'navigation-tooltip';
navTooltip.setAttribute('role', 'tooltip');
navTooltip.hidden = true;
document.body.append(navTooltip);
const hideNavTooltip = () => {
    document.querySelector('[aria-describedby="navigation-tooltip"]')?.removeAttribute('aria-describedby');
    navTooltip.hidden = true;
};
document.querySelectorAll('[data-nav-label]').forEach((link) => {
    const show = () => {
        hideNavTooltip();
        if (document.documentElement.dataset.sidebarCollapsed !== 'true' || window.matchMedia('(max-width: 1024px)').matches) return;
        const rect = link.getBoundingClientRect();
        navTooltip.textContent = link.dataset.navLabel;
        navTooltip.hidden = false;
        navTooltip.style.left = `${rect.right + 16}px`;
        navTooltip.style.top = `${Math.max(8, Math.min(innerHeight - navTooltip.offsetHeight - 8, rect.top + (rect.height - navTooltip.offsetHeight) / 2))}px`;
        link.setAttribute('aria-describedby', navTooltip.id);
    };
    link.addEventListener('mouseenter', show);
    link.addEventListener('focus', show);
    link.addEventListener('mouseleave', hideNavTooltip);
    link.addEventListener('blur', hideNavTooltip);
    link.addEventListener('click', hideNavTooltip);
});
window.addEventListener('resize', hideNavTooltip);
document.addEventListener('scroll', hideNavTooltip, true);
document.addEventListener('keydown', (event) => { if (event.key === 'Escape') hideNavTooltip(); });
document.querySelector('[data-sidebar-toggle]')?.addEventListener('click', hideNavTooltip);

// Native dialogs provide focus containment and Escape without a custom focus trap.
document.querySelectorAll('.catalog-modal').forEach((dialog) => {
    let opener;
    const open = (button) => {
        if (dialog.open) return;
        opener = button;
        dialog.showModal();
        document.body.classList.add('modal-open');
        const initialField = dialog.querySelector('[aria-invalid="true"]') ?? dialog.querySelector('[autofocus]');
        initialField?.focus();
    };
    document.querySelectorAll(`[data-modal-open="${dialog.id}"]`).forEach((button) => {
        button.addEventListener('click', () => open(button));
    });
    dialog.querySelectorAll('[data-modal-close]').forEach((button) => {
        button.addEventListener('click', () => dialog.close());
    });
    dialog.addEventListener('click', (event) => {
        const bounds = dialog.getBoundingClientRect();
        if (event.target === dialog && (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom)) dialog.close();
    });
    dialog.addEventListener('close', () => {
        document.body.classList.remove('modal-open');
        opener?.focus({ preventScroll: true });
    });
    if (dialog.hasAttribute('data-modal-auto-open')) {
        open(document.querySelector(`[data-modal-open="${dialog.id}"]`));
    }
});
