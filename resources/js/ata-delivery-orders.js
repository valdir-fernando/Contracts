export function initializeAtaDeliveryOrders(dialog, root, fetcher = fetch) {
    const lookup = dialog.querySelector('[data-order-lookup]');
    const input = lookup.querySelector('input');
    const search = lookup.querySelector('button');
    const close = dialog.querySelector('[data-order-close]');
    const status = dialog.querySelector('[data-order-status]');
    const deliveryProducts = dialog.querySelector('[data-delivery-product-list] table');
    const productsHeading = root.querySelector('#ata-products-dialog .product-contract-list thead');
    let pending = false;

    function syncProductHeading() {
        if (!dialog.open) return;
        const heading = productsHeading.cloneNode(true);
        heading.rows[0].deleteCell(-1);
        heading.querySelector('[data-ata-special-quantity-heading]').removeAttribute('data-ata-special-quantity-heading');
        deliveryProducts.tHead = heading;
    }

    function refresh() {
        const hasAta = Boolean(root.dataset.ataLoaded);
        input.disabled = close.disabled = pending;
        search.disabled = pending || !hasAta;
    }

    function clear() {
        input.value = '';
        status.textContent = root.dataset.ataLoaded ? '' : 'Salve ou consulte uma ATA para consultar seus pedidos.';
        refresh();
    }

    async function findOrder() {
        const ata = root.dataset.ataLoaded;
        if (!dialog.open || !ata || pending || root.dataset.editorBusy === 'true') return;
        const number = input.value.trim();
        if (!/^[0-9]{3,}\/[0-9]{2}$/.test(number)) {
            status.textContent = 'Informe o número no formato 001/26.';
            input.focus();
            return;
        }

        const template = dialog.dataset.orderLookupUrl;
        const url = new URL(template.replace('/0/', `/${encodeURIComponent(ata)}/`), dialog.ownerDocument.baseURI);
        const options = { headers: { Accept: 'application/json' }, cache: 'no-store' };
        url.searchParams.set('number', number);

        pending = true;
        refresh();
        try {
            status.textContent = 'Consultando pedido…';
            const response = await fetcher(url, options);
            const result = await response.json().catch(() => null);
            if (root.dataset.ataLoaded !== ata) return;
            if (response.ok && typeof result?.number === 'string') {
                input.value = result.number;
                status.textContent = 'Pedido localizado nesta ATA.';
            } else {
                status.textContent = {
                    403: 'Você não tem acesso aos pedidos desta ATA.',
                    404: 'Pedido não encontrado nesta ATA.',
                }[response.status] || 'Não foi possível concluir a operação. Tente novamente.';
            }
        } catch {
            if (root.dataset.ataLoaded === ata) status.textContent = 'Não foi possível concluir a operação. Tente novamente.';
        } finally {
            pending = false;
            refresh();
        }
    }

    lookup.addEventListener('submit', event => {
        event.preventDefault();
        findOrder();
    });
    dialog.addEventListener('cancel', event => { if (pending) event.preventDefault(); });
    root.addEventListener('ata-loaded', clear);
    root.addEventListener('ata-cleared', clear);
    root.addEventListener('ata-delivery-opened', syncProductHeading);
    root.addEventListener('ata-special-quantity-configured', syncProductHeading);
    clear();
}

if (typeof document !== 'undefined') {
    const dialog = document.getElementById('ata-delivery-dialog');
    if (dialog) initializeAtaDeliveryOrders(dialog, dialog.closest('.ata-editor'));
}
