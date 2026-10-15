import { refreshContractSummary } from './contract-summary.js';

const supplierFields = ['fornecedor_nome_snapshot', 'rg_fornec', 'doc_prof', 'pis_pasep',
    'end_fornec', 'socio', 'cpf_socio', 'rg_socio'];

export function formatSupplierDocument(value) {
    if (!/^[\d\s.\/-]+$/.test(value)) return value;
    const digits = value.replace(/\D/g, '');
    if (digits.length === 11) return digits.replace(/^(\d{3})(\d{3})(\d{3})(\d{2})$/, '$1.$2.$3-$4');
    if (digits.length === 14) return digits.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/, '$1.$2.$3/$4-$5');
    return value;
}

export function initializeSupplierAssistance(form, fetcher = fetch) {
    const documentField = form.elements.namedItem('fornecedor_documento_snapshot');
    if (!documentField) return;
    const supplierId = form.elements.namedItem('supplier_id');
    const fields = supplierFields.map((name) => form.elements.namedItem(name)).filter(Boolean);
    documentField.value = formatSupplierDocument(documentField.value);
    const partnerDocument = form.elements.namedItem('cpf_socio');
    if (partnerDocument) partnerDocument.value = formatSupplierDocument(partnerDocument.value);
    refreshContractSummary(form);
    const status = document.createElement('p');
    status.className = 'hint';
    status.setAttribute('role', 'status');
    status.setAttribute('data-supplier-lookup-status', '');
    documentField.closest('.field').append(status);
    let controller;
    let pending = false;
    const automatic = new Map();
    const prefill = JSON.parse(form.dataset.supplierPrefill || '{}');
    let resolvedDocument = form.dataset.supplierOriginalDocument || (prefill.fornecedor_documento_snapshot ?? '').replace(/\D/g, '');
    if ((prefill.fornecedor_documento_snapshot ?? '').replace(/\D/g, '') === documentField.value.replace(/\D/g, '')) {
        fields.forEach((field) => {
            if (!(field.name in prefill)) return;
            const value = field.name === 'cpf_socio' ? formatSupplierDocument(prefill[field.name] ?? '') : (prefill[field.name] ?? '');
            if (field.value === value) automatic.set(field.name, field.value);
        });
    }
    const clearAutomatic = () => {
        fields.forEach((field) => {
            if (field.readOnly || automatic.has(field.name) && field.value === automatic.get(field.name)) field.value = '';
        });
        automatic.clear();
        refreshContractSummary(form);
    };
    form.addEventListener('supplier-selected', ({ detail: { id, values } }) => {
        controller?.abort();
        controller = null;
        pending = false;
        automatic.clear();
        documentField.value = formatSupplierDocument(values.fornecedor_documento_snapshot ?? '');
        fields.forEach((field) => {
            field.value = field.name === 'cpf_socio' ? formatSupplierDocument(values[field.name] ?? '') : (values[field.name] ?? '');
            automatic.set(field.name, field.value);
        });
        if (supplierId) supplierId.value = String(id);
        resolvedDocument = documentField.value.replace(/\D/g, '');
        status.textContent = 'Dados do fornecedor preenchidos. Confira antes de salvar.';
        refreshContractSummary(form);
    });
    documentField.addEventListener('input', () => {
        controller?.abort();
        controller = null;
        pending = false;
        resolvedDocument = '';
        if (supplierId) supplierId.value = '';
        status.textContent = '';
        clearAutomatic();
    });
    form.addEventListener('submit', (event) => {
        if (!pending) return;
        event.preventDefault();
        status.textContent = 'Aguarde o preenchimento dos dados do fornecedor antes de salvar.';
    });
    documentField.addEventListener('focusout', async () => {
        documentField.value = formatSupplierDocument(documentField.value);
        controller?.abort();
        const attempt = new AbortController();
        controller = attempt;
        const digits = documentField.value.replace(/\D/g, '');
        pending = false;
        if (resolvedDocument === digits) return;
        if (!/^[\d\s.\/-]+$/.test(documentField.value) || ![11, 14].includes(digits.length)) {
            status.textContent = documentField.value ? 'Informe um CPF com 11 dígitos ou CNPJ com 14 dígitos.' : '';
            return;
        }
        const before = new Map(fields.map((field) => [field.name, field.value]));
        pending = true;
        status.textContent = 'Buscando dados do fornecedor…';
        let failureMessage = 'Não foi possível buscar o fornecedor. Tente novamente.';
        try {
            const response = await fetcher(form.dataset.supplierLookupUrl, {
                method: 'POST', credentials: 'same-origin', signal: attempt.signal,
                headers: { Accept: 'application/json', 'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': form.elements.namedItem('_token').value },
                body: JSON.stringify({ document: digits }),
            });
            if (!response.ok) {
                const messages = {
                    404: 'Nenhum fornecedor disponível para este documento. Selecione um fornecedor cadastrado.',
                    409: 'Mais de um cadastro encontrado. Use Selecionar fornecedor cadastrado.',
                    422: 'Informe um CPF ou CNPJ válido.',
                    401: 'Seu acesso foi atualizado. Entre novamente.',
                    403: 'Sua conta não possui acesso a este cadastro.',
                };
                failureMessage = messages[response.status] ?? failureMessage;
                throw new Error('lookup');
            }
            const { values } = await response.json();
            if (controller !== attempt || documentField.value.replace(/\D/g, '') !== digits) return;
            fields.forEach((field) => {
                if (!field.readOnly && field.value !== before.get(field.name)) return;
                field.value = field.name === 'cpf_socio' ? formatSupplierDocument(values[field.name] ?? '') : (values[field.name] ?? '');
                automatic.set(field.name, field.value);
            });
            resolvedDocument = digits;
            status.textContent = 'Dados do fornecedor preenchidos. Confira antes de salvar.';
        } catch (error) {
            if (controller === attempt && error.name !== 'AbortError') status.textContent = failureMessage;
        } finally {
            if (controller === attempt) pending = false;
            refreshContractSummary(form);
        }
    });
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-supplier-lookup-url]').forEach((form) => initializeSupplierAssistance(form));
}
