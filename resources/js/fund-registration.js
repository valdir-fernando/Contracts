import { attachMask, maskCnpj, maskPhone } from './input-masks.js';
import { validSupplierDocument, showSupplierDocumentWarning } from './supplier-registration.js';
import { consultCompany } from './cnpj-lookup.js';

if (typeof document !== 'undefined') document.querySelectorAll('[data-fund-form]').forEach((form) => {
    const cnpj = form.querySelector('[data-fund-cnpj]');
    const error = form.querySelector('[data-fund-cnpj-error]');
    attachMask(cnpj, maskCnpj, /^[\d\s.\/-]*$/);
    const validate = () => {
        const valid = cnpj.value.replace(/\D/g, '').length === 14 && validSupplierDocument(cnpj.value);
        error.textContent = valid ? '' : 'Informe um CNPJ válido.';
        error.hidden = valid;
        cnpj.setAttribute('aria-invalid', String(!valid));
        return valid;
    };
    let warned = null;
    const warn = () => {
        if (warned === cnpj.value) return;
        warned = cnpj.value;
        showSupplierDocumentWarning('CNPJ do Fundo inválido. Verifique os números informados antes de salvar.', form.ownerDocument);
        form.ownerDocument.querySelector('[data-supplier-document-warning]').addEventListener('close', () => cnpj.focus(), { once: true });
    };
    cnpj.addEventListener('input', () => { warned = null; error.hidden = true; cnpj.setAttribute('aria-invalid', 'false'); });
    cnpj.addEventListener('blur', () => { if (cnpj.value && !validate()) warn(); });
    form.addEventListener('submit', (event) => { if (!validate()) { event.preventDefault(); warn(); } });
    initializeFundLookup(form);
    if (form.dataset.cnpjReview === 'true') queueMicrotask(() => cnpj.dispatchEvent(new Event('blur')));
    form.querySelectorAll('[data-fund-phone]').forEach((input) => attachMask(input, maskPhone));
    const name = form.elements.namedItem('name');
    const uppercase = () => {
        const start = name.selectionStart, end = name.selectionEnd;
        name.value = name.value.toLocaleUpperCase('pt-BR');
        if (start !== null && end !== null) name.setSelectionRange(start, end);
    };
    uppercase();
    name.addEventListener('input', (event) => { if (!event.isComposing) uppercase(); });
    name.addEventListener('compositionend', uppercase);
    const address = form.querySelector('[data-fund-address]');
    const resize = () => {
        if (!address.getClientRects().length) return;
        address.style.height = 'auto';
        const style = getComputedStyle(address);
        address.style.height = `${Math.max(parseFloat(style.minHeight), address.scrollHeight + parseFloat(style.borderTopWidth) + parseFloat(style.borderBottomWidth))}px`;
    };
    address.addEventListener('input', resize);
    window.addEventListener('pageshow', resize);
    let width = -1;
    new ResizeObserver(([entry]) => {
        if (entry.contentRect.width === width) return;
        width = entry.contentRect.width;
        resize();
    }).observe(address);
    resize();
});

export function initializeFundLookup(form, fetcher = fetch, navigate = (url) => location.assign(url), notify = (message) => showSupplierDocumentWarning(message, form.ownerDocument)) {
    const input = form.querySelector('[data-fund-cnpj]');
    const status = form.querySelector('[data-fund-lookup-status]');
    if (!input || !status || !form.dataset.fundLookupUrl) return;
    const digits = () => input.value.replace(/\D/g, '');
    let sequence = 0;
    let controller;
    let pending;
    let pendingCnpj;
    let completedCnpj;
    let completedResult;
    const filled = new Map();
    let waitingForSubmit = false;
    const message = (text) => { status.textContent = text; status.hidden = !text; };
    const lookup = () => {
        const cnpj = digits();
        if (!cnpj || cnpj.length !== 14 || !validSupplierDocument(input.value)) return Promise.resolve('blocked');
        if (cnpj === form.dataset.fundOriginalCnpj && !form.dataset.cnpjLookupUrl) return Promise.resolve('new');
        if (pending && pendingCnpj === cnpj) return pending;
        if (cnpj === completedCnpj) return Promise.resolve(completedResult);
        controller?.abort();
        controller = new AbortController();
        const current = ++sequence;
        pendingCnpj = cnpj;
        message('Consultando CNPJ do Fundo…');
        pending = (async () => {
            try {
                if (cnpj === form.dataset.fundOriginalCnpj) {
                    await consultCompany(form, { cnpj, kind: 'fund', existing: true, signal: controller.signal,
                        isCurrent: () => current === sequence && cnpj === digits() }, fetcher);
                    return 'new';
                }
                const response = await fetcher(form.dataset.fundLookupUrl, {
                    method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': form.elements.namedItem('_token')?.value ?? '' },
                    body: JSON.stringify({ cnpj }), signal: controller.signal,
                });
                if (current !== sequence || cnpj !== digits()) return 'blocked';
                if (!response.ok) {
                    message(response.status === 409 ? 'Mais de um Fundo encontrado. Selecione na lista.' : 'Não foi possível consultar o CNPJ. Tente novamente.');
                    notify(status.textContent);
                    return 'blocked';
                }
                const data = await response.json();
                if (current !== sequence || cnpj !== digits()) return 'blocked';
                completedCnpj = cnpj;
                completedResult = data.found ? 'existing' : 'new';
                if (data.found) {
                    message('Fundo encontrado. Abrindo cadastro…');
                    navigate(data.edit_url + (form.dataset.cnpjLookupUrl ? '&consultar_cnpj=1' : ''));
                } else {
                    message('CNPJ não cadastrado. Continue o preenchimento.');
                    const changed = await consultCompany(form, { cnpj, kind: 'fund', existing: false, signal: controller.signal,
                        isCurrent: () => current === sequence && cnpj === digits() }, fetcher);
                    for (const [field, value] of changed ?? []) filled.set(field, value);
                }
                return completedResult;
            } catch (error) {
                if (current === sequence && error.name !== 'AbortError') message('Não foi possível consultar o CNPJ. Tente novamente.');
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
        completedCnpj = null;
        for (const [field, { before, automatic }] of filled) if (field.value === automatic) field.value = before;
        filled.clear();
        message('');
    });
    input.addEventListener('blur', lookup);
    form.addEventListener('submit', (event) => {
        if (event.defaultPrevented || !validSupplierDocument(input.value) || digits().length !== 14) return;
        const cnpj = digits();
        if (cnpj === form.dataset.fundOriginalCnpj || (!pending && cnpj === completedCnpj && completedResult === 'new')) return;
        event.preventDefault();
        if (waitingForSubmit) return;
        waitingForSubmit = true;
        lookup().then((result) => {
            if (result === 'new' && cnpj === digits()) form.requestSubmit(event.submitter ?? undefined);
        }).finally(() => { waitingForSubmit = false; });
    });
}
