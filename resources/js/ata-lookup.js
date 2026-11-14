import { formatDecimalField } from './decimal-fields.js';
import { formatSupplierDocument } from './supplier-assistance.js';
import { formatAtaContractorField } from './ata-contractor.js';
import { formatAtaPhone } from './ata-supplier-contacts.js';
import { refreshContractSummary } from './contract-summary.js';

export function initializeAtaLookup(root, number, opener, status, fetcher = fetch) {
    let controller;
    let sequence = 0;
    const supplierForm = root.querySelector('[data-ata-supplier-form]');
    const fundHeading = root.ownerDocument.querySelector('[data-ata-managing-fund-heading]');
    const productsTitle = root.querySelector('[data-ata-products-number]');
    const productsManagingFund = root.querySelector('[data-ata-products-managing-fund]');
    const field = name => root.querySelector(`[name="${name}"]`);
    const managerSelect = field('managing_fund_id');
    const fundLabels = new Map([...managerSelect.options].map(option => [option.value, option.textContent]));
    const notify = (name, detail = {}) => root.dispatchEvent(new CustomEvent(name, { detail }));
    const clear = () => {
        if (fundHeading) fundHeading.textContent = 'FUNDO GERENCIADOR';
        if (productsTitle) productsTitle.textContent = '';
        if (productsManagingFund) productsManagingFund.textContent = 'Não informado';
        [...managerSelect.options].forEach(option => {
            if (option.dataset.ataLookupOption) option.remove();
            else if (fundLabels.has(option.value)) option.textContent = fundLabels.get(option.value);
        });
        field('fornecedor_documento_snapshot').dispatchEvent(new Event('input', { bubbles: true }));
        supplierForm.querySelectorAll('input:not([name="_token"]), textarea').forEach(input => { input.value = ''; });
        ['Data_ATA', 'bidding', 'bidding_number'].forEach(name => { field(name).value = ''; });
        root.querySelectorAll('[data-ata-contractor-field]').forEach(output => { output.textContent = ''; });
        root.querySelector('#ata-total').value = '0,0000';
        root.querySelectorAll('[data-product-heading-total], [data-product-contract-total], [data-product-line-total]').forEach(input => { input.value = '0,0000'; });
        root.querySelector('[data-product-line-total]').value = '0,00';
        root.querySelector('[data-contract-product-list]').replaceChildren();
        root.dataset.ataLoaded = '';
        supplierForm.dataset.ataSnapshot = '';
        refreshContractSummary(supplierForm);
        notify('ata-cleared');
    };
    const load = async () => {
        if (root.dataset.editorBusy === 'true' && !controller) return;
        controller?.abort();
        const current = ++sequence;
        const requested = number.value.trim();
        clear();
        status.textContent = '';
        if (!requested) return;
        controller = new AbortController();
        const attempt = controller;
        notify('ata-loading');
        status.textContent = 'Consultando ata...';
        try {
            const url = new URL(root.dataset.ataLookupUrl, root.ownerDocument.baseURI);
            url.searchParams.set('number', requested);
            const response = await fetcher(url, { credentials: 'same-origin', cache: 'no-store',
                headers: { Accept: 'application/json' }, signal: attempt.signal });
            const data = await response.json();
            if (current !== sequence || attempt.signal.aborted || number.value.trim() !== requested) return;
            if (!response.ok) throw new Error(Object.values(data.errors ?? {}).flat().join(' ') || data.message || 'Não foi possível consultar a ata.');
            number.value = data.number;
            if (productsTitle) productsTitle.textContent = data.number;
            if (fundHeading) fundHeading.textContent = data.managing_fund_name || 'FUNDO GERENCIADOR';
            if (productsManagingFund) productsManagingFund.textContent = data.managing_fund_name || 'Não informado';
            const date = field('Data_ATA');
            date.value = date.type === 'date' ? (data.date ?? '') : (data.date ?? '').split('-').reverse().join('/');
            field('bidding').value = data.bidding ?? '';
            field('bidding_number').value = data.bidding_number ?? '';
            const manager = field('managing_fund_id');
            if (data.managing_fund_id && ![...manager.options].some(option => option.value === data.managing_fund_id)) {
                const option = root.ownerDocument.createElement('option');
                option.value = data.managing_fund_id;
                option.dataset.ataLookupOption = 'true';
                option.textContent = data.managing_fund_name;
                manager.append(option);
            }
            const managerOption = [...manager.options].find(option => option.value === data.managing_fund_id);
            if (managerOption) managerOption.textContent = data.managing_fund_name;
            root.querySelectorAll('[data-ata-contractor-field]').forEach(output => {
                const column = output.dataset.ataContractorField;
                output.textContent = formatAtaContractorField(column, data.contractor[column]);
            });
            Object.entries(data.supplier).forEach(([name, value]) => {
                const input = supplierForm.elements.namedItem(name);
                if (!input) return;
                input.value = ['fornecedor_documento_snapshot', 'cpf_socio'].includes(name) ? formatSupplierDocument(value ?? '')
                    : ['supplier_mobile', 'supplier_whatsapp'].includes(name) ? formatAtaPhone(value) : (value ?? '');
            });
            const total = formatDecimalField(data.total);
            root.querySelector('#ata-total').value = total;
            root.querySelectorAll('[data-product-heading-total]').forEach(input => { input.value = total; });
            const products = root.querySelector('[data-contract-product-list]');
            for (const product of data.products) {
                const row = root.ownerDocument.createElement('tr');
                [product.item, product.code, product.description, product.unit, formatDecimalField(product.quantity), product.brand,
                    formatDecimalField(product.unit_price), formatDecimalField(product.total), product.ficha, ''].forEach(value => {
                    const cell = root.ownerDocument.createElement('td');
                    const content = root.ownerDocument.createElement('div');
                    content.className = 'ata-product-cell-content';
                    content.textContent = value ?? '';
                    cell.append(content);
                    row.append(cell);
                });
                products.append(row);
            }
            root.dataset.ataLoaded = String(data.id);
            supplierForm.dataset.ataSnapshot = 'true';
            notify('ata-loaded', data);
            refreshContractSummary(supplierForm);
            status.textContent = 'Ata carregada para consulta.';
        } catch (error) {
            if (current === sequence && !attempt.signal.aborted) {
                clear();
                status.textContent = ['TypeError', 'SyntaxError'].includes(error.name) ? 'Não foi possível consultar a ata. Tente novamente.' : error.message;
            }
        } finally {
            if (current === sequence) controller = null;
        }
    };
    number.addEventListener('input', () => {
        if (root.dataset.editorBusy === 'true' && !controller) return;
        sequence++;
        controller?.abort();
        controller = null;
        if (root.dataset.ataLoaded) clear();
        notify('ata-cleared');
        status.textContent = '';
    });
    number.addEventListener('blur', load);
    root.addEventListener('ata-saved', ({ detail }) => {
        number.value = detail.number;
        // Alpine must first apply the released save state to data-editor-busy.
        setTimeout(load, 0);
    });
    opener.addEventListener('mousedown', event => event.preventDefault());
    opener.addEventListener('click', load);
}

if (typeof document !== 'undefined') {
    const root = document.querySelector('[data-ata-lookup-url]');
    if (root) initializeAtaLookup(root, document.getElementById('ata-summary-number'),
        document.querySelector('[aria-label="Buscar atas"]'), document.querySelector('[data-ata-lookup-status]'));
}
