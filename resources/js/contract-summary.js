export function refreshContractSummary(form) {
    const workspace = form.closest?.('[data-contract-editor]');
    if (!workspace) return;
    workspace.querySelectorAll('[data-contract-summary-field]').forEach((output) => {
        const control = form.elements.namedItem(output.dataset.contractSummaryField);
        if (control) output.textContent = control.value.trim() || (output.dataset.contractSummaryField === 'fornecedor_nome_snapshot' ? 'Nome / Razão Social' : 'Não informado');
    });
}

export function initializeContractSummary(workspace) {
    const form = workspace.querySelector('form[data-contract-form]');
    if (!form) return;
    const refresh = () => refreshContractSummary(form);
    ['input', 'change', 'focusout'].forEach((event) => form.addEventListener(event, refresh));
    refresh();
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-contract-editor]').forEach(initializeContractSummary);
}
