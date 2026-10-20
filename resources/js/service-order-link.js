export function serviceOrderTypeAllowed(type) {
    return ['Serviço', 'Serviços', 'Serviços Diversos', 'Obras'].includes(type);
}

export function initializeServiceOrderLink(form) {
    const type = form.querySelector('[name="tipo"]');
    const link = form.querySelector('[data-service-order-type-link]');
    if (!type || !link) return;
    const update = () => { link.hidden = !serviceOrderTypeAllowed(type.value); };
    type.addEventListener('change', update);
    update();
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-contract-form]').forEach(initializeServiceOrderLink);
}
