import { showSupplierDocumentWarning } from './system-message.js';

if (typeof document !== 'undefined') document.querySelectorAll('[data-registration-decree]').forEach((button) => {
    button.addEventListener('click', (event) => {
        event.preventDefault();
        showSupplierDocumentWarning(button.dataset.message, button.ownerDocument);
    });
});

export function initializeInspectorDecree(form, request = fetch, warn = showSupplierDocumentWarning) {
    let pending = false;
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (pending) return;
        if (!form.checkValidity()) {
            warn('Selecione um fiscal com o decreto cadastrado antes de emitir.', form.ownerDocument);
            return;
        }
        pending = true;
        const button = event.submitter || form.querySelector('[type="submit"]');
        button.disabled = true;
        try {
            const response = await request(form.action, {
                method: 'POST', body: new FormData(form), credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok) {
                const payload = await response.json().catch(() => ({}));
                const messages = Object.values(payload.errors ?? {}).flat();
                throw new Error(messages.join('\n') || payload.message || 'Não foi possível gerar o decreto. Reabra a tela e tente novamente.');
            }
            if (!response.headers.get('Content-Type')?.includes('application/pdf')) {
                throw new Error('Sua sessão pode ter expirado. Entre novamente antes de gerar o decreto.');
            }
            const url = URL.createObjectURL(await response.blob());
            const link = form.ownerDocument.createElement('a');
            link.href = url;
            link.download = 'decreto-fiscais.pdf';
            form.ownerDocument.body.append(link);
            link.click();
            link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 60000);
        } catch (error) {
            warn(error.message || 'Não foi possível gerar o decreto.', form.ownerDocument);
        } finally {
            pending = false;
            button.disabled = false;
        }
    });
}

if (typeof document !== 'undefined') document.querySelectorAll('[data-inspector-decree]').forEach((form) => initializeInspectorDecree(form));
