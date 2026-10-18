import { attachMask, maskCpf, maskPhone } from './input-masks.js';
import { validSupplierDocument, showSupplierDocumentWarning } from './supplier-registration.js';

export function initializeContractStewardCpf(form, notify = (message) => {
    showSupplierDocumentWarning(message, form.ownerDocument);
    form.ownerDocument.querySelector('[data-supplier-document-warning]').addEventListener('close', () => {
        form.dispatchEvent(new CustomEvent('contract-steward-cpf-invalid'));
    }, { once: true });
}) {
    const input = form.querySelector('[data-contract-steward-cpf]');
    const message = form.querySelector('[data-contract-steward-cpf-error]');
    if (!input || !message) return;
    const show = (invalid) => {
        message.textContent = invalid ? 'Informe um CPF válido.' : '';
        message.hidden = !invalid;
        input.setAttribute('aria-invalid', String(invalid));
    };
    const validate = () => {
        const invalid = input.value.trim() !== '' && (input.value.replace(/\D/g, '').length !== 11 || !validSupplierDocument(input.value));
        show(invalid);
        return !invalid;
    };
    let warnedValue = null;
    const warn = () => {
        if (warnedValue === input.value) return;
        warnedValue = input.value;
        notify('CPF do gestor inválido. Verifique os números informados antes de salvar.');
    };
    input.addEventListener('blur', () => { if (!validate()) warn(); });
    input.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' || event.isComposing || event.keyCode === 229
            || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
        event.preventDefault();
        if (!validate()) {
            form.dispatchEvent(new CustomEvent('contract-steward-cpf-invalid'));
            warn();
            return;
        }
        form.querySelector('[name="RG_Gestor"]')?.focus();
    });
    input.addEventListener('input', () => {
        warnedValue = null;
        if (input.value.replace(/\D/g, '').length === 11) validate();
        else show(false);
    });
    form.addEventListener('submit', (event) => {
        if (validate()) return;
        event.preventDefault();
        form.dispatchEvent(new CustomEvent('contract-steward-cpf-invalid'));
        warn();
    });
}

export function initializeContractStewardLookup(form, fetcher = fetch, navigate = (url) => location.assign(url), notify = (message) => showSupplierDocumentWarning(message, form.ownerDocument)) {
    const input = form.querySelector('[data-contract-steward-cpf]');
    const status = form.querySelector('[data-contract-steward-lookup-status]');
    if (!input || !status || !form.dataset.contractStewardLookupUrl) return;
    const digits = () => input.value.replace(/\D/g, '');
    let sequence = 0;
    let controller;
    let pending;
    let pendingCpf;
    let completedCpf;
    let completedResult;
    let waitingForSubmit = false;
    const message = (text) => { status.textContent = text; status.hidden = !text; };
    const lookup = () => {
        const cpf = digits();
        if (!cpf || cpf.length !== 11 || !validSupplierDocument(input.value)) return Promise.resolve('blocked');
        if (cpf === form.dataset.contractStewardOriginalCpf) return Promise.resolve('new');
        if (cpf === completedCpf) return Promise.resolve(completedResult);
        if (pending && pendingCpf === cpf) return pending;
        controller?.abort();
        controller = new AbortController();
        const current = ++sequence;
        pendingCpf = cpf;
        message('Consultando CPF do gestor…');
        pending = (async () => {
            try {
                const response = await fetcher(form.dataset.contractStewardLookupUrl, {
                    method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': form.elements.namedItem('_token')?.value ?? '' },
                    body: JSON.stringify({ cpf }), signal: controller.signal,
                });
                if (current !== sequence || cpf !== digits()) return 'blocked';
                if (!response.ok) {
                    message(response.status === 409 ? 'Mais de um gestor encontrado. Selecione na lista.' : 'Não foi possível consultar o CPF. Tente novamente.');
                    notify(status.textContent);
                    return 'blocked';
                }
                const data = await response.json();
                if (current !== sequence || cpf !== digits()) return 'blocked';
                completedCpf = cpf;
                completedResult = data.found ? 'existing' : 'new';
                if (data.found) {
                    message('Gestor encontrado. Abrindo cadastro…');
                    navigate(data.edit_url);
                } else message('CPF não cadastrado. Continue o preenchimento.');
                return completedResult;
            } catch (error) {
                if (current === sequence && error.name !== 'AbortError') message('Não foi possível consultar o CPF. Tente novamente.');
                return 'blocked';
            } finally {
                if (current === sequence) pending = null;
            }
        })();
        return pending;
    };
    input.addEventListener('input', () => {
        sequence++;
        controller?.abort();
        pending = null;
        completedCpf = null;
        message('');
    });
    input.addEventListener('blur', lookup);
    form.addEventListener('submit', (event) => {
        if (event.defaultPrevented || !validSupplierDocument(input.value) || digits().length !== 11) return;
        const cpf = digits();
        if (cpf === form.dataset.contractStewardOriginalCpf || (cpf === completedCpf && completedResult === 'new')) return;
        event.preventDefault();
        if (waitingForSubmit) return;
        waitingForSubmit = true;
        lookup().then((result) => {
            if (result === 'new' && cpf === digits()) form.requestSubmit(event.submitter ?? undefined);
        }).finally(() => { waitingForSubmit = false; });
    });
}

if (typeof document !== 'undefined') document.querySelectorAll('[data-contract-steward-form]').forEach((form) => {
    const name = form.elements.namedItem('Nome_Gestor');
    if (name) {
        const uppercase = () => {
            const start = name.selectionStart;
            const end = name.selectionEnd;
            name.value = name.value.toLocaleUpperCase('pt-BR');
            if (start !== null && end !== null) name.setSelectionRange(start, end);
        };
        uppercase();
        name.addEventListener('input', (event) => { if (!event.isComposing) uppercase(); });
        name.addEventListener('compositionend', uppercase);
    }
    form.querySelectorAll('[data-contract-steward-cpf]').forEach((input) => attachMask(input, maskCpf));
    form.querySelectorAll('[data-contract-steward-phone]').forEach((input) => attachMask(input, maskPhone));
    initializeContractStewardCpf(form);
    initializeContractStewardLookup(form);

    const feedback = form.querySelector('[data-contract-steward-feedback]');
    if (feedback) {
        const messages = JSON.parse(feedback.textContent);
        if (messages.length) showSupplierDocumentWarning(messages.join('\n'), form.ownerDocument);
    }
    const address = form.querySelector('[data-contract-steward-address]');
    if (!address) return;
    const resize = () => {
        if (!address.getClientRects().length) return;
        address.style.height = 'auto';
        const style = getComputedStyle(address);
        const borders = parseFloat(style.borderTopWidth) + parseFloat(style.borderBottomWidth);
        address.style.height = `${Math.max(parseFloat(style.minHeight), address.scrollHeight + borders)}px`;
    };
    address.addEventListener('input', resize);
    window.addEventListener('pageshow', resize);
    let previousWidth = -1;
    new ResizeObserver(([entry]) => {
        if (entry.contentRect.width === previousWidth) return;
        previousWidth = entry.contentRect.width;
        resize();
    }).observe(address);
    resize();
});
