// Preserve the draft while displaying the original supplier catalog.
import { guardSupplierCatalogClose } from './supplier-picker-guard.js';

document.querySelectorAll('[data-supplier-picker-open]').forEach((opener) => {
    const contractForm = opener.closest('form');
    const dialog = document.createElement('dialog');
    dialog.className = 'catalog-modal supplier-catalog-dialog';
    dialog.setAttribute('aria-label', 'Selecionar fornecedor cadastrado');
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'secondary-button';
    close.textContent = 'Fechar catálogo';
    const frame = document.createElement('iframe');
    frame.name = 'contracts-supplier-catalog';
    frame.title = 'Catálogo de fornecedores';
    dialog.append(close, frame);
    document.body.append(dialog);
    frame.addEventListener('load', () => {
        const selectedForm = frame.contentDocument?.querySelector('[data-contract-form][data-supplier-prefill]');
        const id = selectedForm?.elements.namedItem('supplier_id')?.value;
        if (!id) return;
        const values = JSON.parse(selectedForm.dataset.supplierPrefill);
        contractForm.dispatchEvent(new CustomEvent('supplier-selected', { detail: { id, values } }));
        dialog.close();
    });
    opener.addEventListener('click', () => {
        frame.src = opener.dataset.supplierListUrl;
        dialog.showModal();
    });
    guardSupplierCatalogClose(dialog, close, frame);
    close.addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {
        frame.src = 'about:blank';
        opener.focus();
    });
});
