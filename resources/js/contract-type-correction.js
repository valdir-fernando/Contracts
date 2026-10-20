export function attachContractTypeCorrection(form) {
    const select = form.querySelector('#tipo');
    const accept = form.querySelector('[data-contract-type-correction]');
    const keep = form.querySelector('[data-contract-type-keep]');
    const status = form.querySelector('[data-contract-type-status]');
    if (!select || !accept || !keep || !status) return;
    const choose = (value, message) => {
        if (![...select.options].some((option) => option.value === value)) return;
        select.value = value;
        select.dispatchEvent(new Event('change', { bubbles: true }));
        status.textContent = message;
    };
    accept.addEventListener('click', () => choose(accept.dataset.contractTypeCorrection, 'Correção selecionada. Salve o contrato para confirmar.'));
    keep.addEventListener('click', () => choose(accept.dataset.originalType, 'Valor histórico mantido.'));
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-contract-form]').forEach(attachContractTypeCorrection);
}
