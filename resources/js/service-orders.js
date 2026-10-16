import { isoDate } from './input-masks.js';

export function serviceOrderPayload(form, version) {
    const value = (name) => form.elements.namedItem(name).value;
    return {
        contract_version: Number(version), number: value('number'),
        issued_on: isoDate(value('issued_on')) ?? value('issued_on'), deadline: value('deadline'),
        amendment_id: value('amendment_id') || null, notes: value('notes') || null,
    };
}

export function advanceServiceOrderVersion(field, expected, current) {
    if (String(field.value) === String(expected) && Number(current) === Number(expected) + 1) field.value = String(current);
}

export function initializeServiceOrders(dialog, pageDocument = document, fetcher = fetch) {
    const form = dialog.querySelector('[data-service-order-form]');
    const status = form.querySelector('[data-service-order-status]');
    const save = form.querySelector('[data-service-order-save]');
    const previous = dialog.querySelector('[data-service-orders-previous]');
    const next = dialog.querySelector('[data-service-orders-next]');
    const select = form.elements.namedItem('amendment_id');
    let sourceVersion;
    let page = 1;
    let loading = false;
    let saving = false;
    let loaded = false;
    let opener;
    const headers = { Accept: 'application/json' };
    const feedback = (message, error = false) => {
        status.textContent = message;
        status.className = error ? 'field-error' : 'hint';
    };
    const load = async (requestedPage = 1) => {
        loading = true;
        save.disabled = true;
        previous.disabled = next.disabled = true;
        try {
            const response = await fetcher(`${dialog.dataset.url}?page=${requestedPage}`, { headers, credentials: 'same-origin' });
            if (!response.ok) throw new Error('Não foi possível consultar as ordens. Feche e abra a janela para tentar novamente.');
            const data = await response.json();
            const selected = loaded ? select.value : dialog.dataset.amendment;
            select.replaceChildren(new Option('Contrato original', ''));
            data.amendments.forEach((amendment) => select.add(new Option(`Aditivo ${amendment.number}`, String(amendment.id))));
            select.value = selected || '';
            const rows = dialog.querySelector('[data-service-order-rows]');
            rows.replaceChildren();
            data.orders.forEach((order) => {
                const row = pageDocument.createElement('tr');
                [order.number, order.issued_on, order.deadline, order.amendment ? `Aditivo ${order.amendment}` : 'Contrato original', order.notes || '—'].forEach((value) => {
                    const cell = pageDocument.createElement('td');
                    cell.textContent = value;
                    row.append(cell);
                });
                rows.append(row);
            });
            if (!data.orders.length) {
                const row = pageDocument.createElement('tr');
                const cell = pageDocument.createElement('td');
                cell.colSpan = 5;
                cell.textContent = 'Nenhuma ordem de serviço cadastrada.';
                row.append(cell);
                rows.append(row);
            }
            page = data.page;
            dialog.querySelector('[data-service-orders-page]').textContent = `Página ${page} de ${data.last_page}`;
            previous.disabled = page <= 1;
            next.disabled = page >= data.last_page;
            loaded = true;
        } catch (error) {
            loaded = false;
            dialog.querySelector('[data-service-order-rows]').replaceChildren();
            feedback(error.message, true);
        } finally {
            loading = false;
            save.disabled = !loaded || saving;
        }
    };
    pageDocument.querySelectorAll('[data-service-orders-open]').forEach((button) => button.addEventListener('click', () => {
        if (dialog.open) return;
        opener = button;
        const source = button.closest('form');
        sourceVersion = source.elements.namedItem('contract_version') ?? source.elements.namedItem('version');
        if (!sourceVersion) return;
        feedback('');
        dialog.showModal();
        load();
        form.elements.namedItem('number').focus();
    }));
    dialog.querySelector('[data-service-orders-close]').addEventListener('click', () => { if (!saving) dialog.close(); });
    dialog.addEventListener('cancel', (event) => { if (saving) event.preventDefault(); });
    dialog.addEventListener('close', () => opener?.focus());
    previous.addEventListener('click', () => { if (!loading && !saving) load(page - 1); });
    next.addEventListener('click', () => { if (!loading && !saving) load(page + 1); });
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (saving || loading || !loaded || !sourceVersion) return;
        saving = true;
        save.disabled = true;
        const expected = sourceVersion.value;
        feedback('Salvando ordem de serviço…');
        try {
            const response = await fetcher(form.action, {
                method: 'POST', credentials: 'same-origin',
                headers: { ...headers, 'Content-Type': 'application/json', 'X-CSRF-TOKEN': form.elements.namedItem('_token').value },
                body: JSON.stringify(serviceOrderPayload(form, expected)),
            });
            const data = await response.json();
            if (!response.ok) {
                feedback(response.status === 422 ? Object.values(data.errors ?? {}).flat().join(' ') || data.message
                    : response.status === 409 ? 'O contrato mudou. Recarregue a página e confira seus dados antes de cadastrar a ordem.'
                        : 'Não foi possível salvar. Confira seu acesso e tente novamente.', true);
                return;
            }
            advanceServiceOrderVersion(sourceVersion, expected, data.contract_version);
            ['number', 'issued_on', 'deadline', 'notes'].forEach((name) => {
                const field = form.elements.namedItem(name);
                field.value = '';
                field.dispatchEvent(new Event('input', { bubbles: true }));
            });
            feedback('Ordem de serviço cadastrada.');
            await load(1);
        } catch {
            loaded = false;
            feedback('Não foi possível confirmar a gravação. Feche e abra a janela para consultar as ordens antes de tentar novamente.', true);
        } finally {
            saving = false;
            save.disabled = !loaded;
        }
    });
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-service-orders-dialog]').forEach((dialog) => initializeServiceOrders(dialog));
}
