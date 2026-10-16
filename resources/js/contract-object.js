export function suggestedContractObject({ type, address, facilities, role, workplace, document, documentType }) {
    if (type === 'Locação de Imóvel' && address.trim() && facilities.trim()) {
        return `Locação do imóvel situado no(a) ${address.trim()} destinado as instalações do(a) ${facilities.trim()}.`;
    }
    if (type === 'Credenciamento' && role.trim() && workplace.trim()) {
        const digits = document.replace(/\D/g, '');
        if (document && !/^[\d\s.\/-]+$/.test(document)) return null;
        const kind = document ? (digits.length === 11 ? 'CPF' : digits.length === 14 ? 'CNPJ' : '') : documentType;
        if (kind === 'CPF') return `Prestação de Serviços como ${role.trim()} para trabalhar no(a) ${workplace.trim()} ...`;
        if (kind === 'CNPJ') return '';
    }
    return null;
}

export function initializeContractObject(form) {
    const field = (name) => form.elements.namedItem(name);
    const type = field('tipo');
    const object = field('objeto');
    if (!type || !object) return;
    const refresh = () => {
        const value = (name) => field(name)?.value ?? '';
        const suggestion = suggestedContractObject({ type: type.value, address: value('end_imovel'), facilities: value('instalacoes'),
            role: value('cargo_credenc'), workplace: value('local_servico'), document: value('fornecedor_documento_snapshot'),
            documentType: !field('fornecedor_documento_snapshot') ? form.querySelector('[data-supplier-document-type]')?.dataset.supplierDocumentType : undefined });
        if (suggestion === null) return;
        if (object.value === suggestion) return;
        object.value = suggestion;
        object.dispatchEvent(new Event('input', { bubbles: true }));
    };
    ['tipo', 'end_imovel', 'instalacoes', 'cargo_credenc', 'local_servico', 'fornecedor_documento_snapshot'].forEach((name) => {
        const control = field(name);
        if (!control) return;
        ['input', 'change', 'focusout'].forEach((event) => control.addEventListener(event, refresh));
    });
    form.addEventListener('supplier-selected', refresh);
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-contract-form]').forEach(initializeContractObject);
}
