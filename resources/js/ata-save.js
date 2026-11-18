import { showSupplierDocumentWarning, confirmSystemMessage } from './system-message.js';
import { isoDate } from './input-masks.js';

const general = {
    managing_fund_id: 'Fundo Gerenciador', Data_ATA: 'Data_ATA', ata_process: 'Processo ATA',
    bidding: 'Licitação', bidding_number: 'Nº Licit',
};
const supplierCommon = {
    fornecedor_documento_snapshot: 'CPF/CNPJ do fornecedor', fornecedor_nome_snapshot: 'Nome / Razão Social',
    end_fornec: 'Endereço do fornecedor', supplier_email: 'e-mail',
};
const partner = { socio: 'Sócio / representante', cpf_socio: 'CPF do sócio', rg_socio: 'RG do sócio' };
const blank = value => !String(value ?? '').trim();

export function ataMissingFields(values, managerFields) {
    const missing = [];
    const check = (fields, group, step) => Object.entries(fields).forEach(([name, label]) => {
        if (blank(values[name])) missing.push({ group, step, label });
    });
    check(general, 'Dados Processuais', 0);
    for (const { value, label } of managerFields) {
        if (blank(value)) missing.push({ group: 'Dados Gerenciador', step: 1, label });
    }
    check(supplierCommon, 'Dados do Fornecedor', 2);
    const document = String(values.fornecedor_documento_snapshot ?? '').replace(/\D/g, '');
    if (document.length === 14) check(partner, 'Dados do Fornecedor', 2);
    else if (document.length === 11) check({ rg_fornec: 'RG do fornecedor' }, 'Dados do Fornecedor', 2);
    else if (document) missing.push({ group: 'Dados do Fornecedor', step: 2, label: 'CPF/CNPJ do fornecedor válido (11 ou 14 dígitos)' });
    if (blank(values.supplier_mobile) && blank(values.supplier_whatsapp)) {
        missing.push({ group: 'Dados do Fornecedor', step: 2, label: 'Celular ou WhatsApp (preencha pelo menos um)' });
    }
    return missing;
}

export function initializeAtaSave(root, warn = showSupplierDocumentWarning, fetcher = fetch, confirm = confirmSystemMessage) {
    const button = root.querySelector('[data-ata-save]');
    let saving = false;
    const notify = (name, detail = {}) => root.dispatchEvent(new CustomEvent(name, { bubbles: true, detail }));
    button.addEventListener('click', async () => {
        if (button.disabled || saving || root.dataset.editorBusy === 'true') return;
        const ataId = root.dataset.ataLoaded || '';
        const version = root.dataset.ataVersion;
        if (!ataId && !blank(root.ownerDocument.querySelector?.('#ata-summary-number')?.value)) {
            warn('Consulte a ATA pelo número antes de editar ou clique em Novo para cadastrar outra ATA.', root.ownerDocument);
            return;
        }
        const values = Object.fromEntries([...root.querySelectorAll('input[name], select[name], textarea[name]')]
            .map(input => [input.name, input.value]));
        const managerFields = [...root.querySelectorAll('[data-ata-contractor-field]')].map(field => ({
            value: field.textContent,
            label: root.querySelector(`label[for="${field.id}"]`).textContent.trim(),
        }));
        const missing = ataId ? Object.entries(general).filter(([name]) => name !== 'managing_fund_id' && blank(values[name]))
            .map(([, label]) => ({ step: 0, group: 'Dados Processuais', label })) : ataMissingFields(values, managerFields);
        if (missing.length) {
            notify('ata-validation-failed', { step: missing[0].step });
            warn('Não é possível salvar a ata. Preencha os campos obrigatórios: '
                + missing.map(item => `${item.group}: ${item.label}`).join('; ') + '.', root.ownerDocument);
            return;
        }
        saving = true;
        notify('ata-saving');
        let saved;
        try {
            if (ataId) {
                if (!await confirm('Confirma a alteração dos dados desta ATA?', root.ownerDocument)) return;
                if (root.dataset.ataLoaded !== ataId || root.dataset.ataVersion !== version) {
                    warn('A ATA ou sua versão mudou durante a confirmação. Consulte novamente antes de salvar.', root.ownerDocument);
                    return;
                }
            }
            const payload = {
                draft_token: root.dataset.draftToken,
                version: Number(button.dataset.version ?? JSON.parse(root.dataset.draftState).version),
                managing_fund_id: values.managing_fund_id, Data_ATA: isoDate(values.Data_ATA),
                admitir_adesao: values.admitir_adesao || null,
                start_date: values.start_date ? isoDate(values.start_date) : null,
                end_date: values.end_date ? isoDate(values.end_date) : null,
                validity_period: values.validity_period || null,
                one_year_inpc: root.querySelector('[name="one_year_inpc"]')?.checked ?? false,
                ata_process: values.ata_process, bidding: values.bidding, bidding_number: values.bidding_number,
                fornecedor_documento_snapshot: values.fornecedor_documento_snapshot,
            };
            if (ataId) {
                payload.version = Number(version);
                delete payload.draft_token;
                delete payload.managing_fund_id;
                delete payload.fornecedor_documento_snapshot;
            }
            const url = ataId ? button.dataset.updateUrl.replace(/\/0$/, `/${encodeURIComponent(ataId)}`) : button.dataset.saveUrl;
            const response = await fetcher(url, {
                method: ataId ? 'PATCH' : 'POST', credentials: 'same-origin', headers: { Accept: 'application/json',
                    'Content-Type': 'application/json', 'X-CSRF-TOKEN': root.dataset.csrfToken },
                body: JSON.stringify(payload),
            });
            const data = response.ok && !response.redirected ? await response.json() : await response.json().catch(() => ({}));
            if ((root.dataset.ataLoaded || '') !== ataId) return;
            if (response.redirected || !response.ok) {
                const reasons = {
                    401: 'Sua sessão terminou. Entre novamente para salvar a ATA.',
                    403: 'Você não tem permissão para alterar esta ATA.',
                    404: 'A ATA não está disponível. Consulte novamente pelo Nº ATA.',
                    409: 'A ATA foi alterada por outra operação. Consulte novamente antes de salvar.',
                    419: 'Sua sessão terminou. Entre novamente para salvar a ATA.',
                    429: 'Foram feitas muitas tentativas. Aguarde um momento e tente novamente.',
                };
                const message = Object.values(data.errors ?? {}).flat().join('; ')
                    || reasons[response.redirected ? 401 : response.status]
                    || (response.status === 422 ? data.message : null)
                    || 'Não foi possível salvar a ATA por uma falha no sistema. Tente novamente.';
                warn(message, root.ownerDocument);
                return;
            }
            saved = data;
        } catch {
            warn(ataId ? 'Não foi possível confirmar a alteração. Confira sua conexão e consulte novamente a ATA antes de tentar outra vez.'
                : 'Não foi possível confirmar a gravação. Tente salvar novamente para confirmar a mesma ata, sem gerar outra numeração.', root.ownerDocument);
        } finally {
            saving = false;
            notify('ata-save-finished');
        }
        if (saved) {
            notify('ata-saved', saved);
            warn(`Ata ${saved.number} ${ataId ? 'alterada' : 'salva'} com sucesso.`, root.ownerDocument);
        }
    });
}

export function initializeAtaDelete(root, confirm = confirmSystemMessage, warn = showSupplierDocumentWarning, fetcher = fetch) {
    const button = root.querySelector('[data-ata-delete]');
    if (!button) return;
    let pending = false;
    button.addEventListener('click', async () => {
        const id = root.dataset.ataLoaded;
        const version = root.dataset.ataVersion;
        if (!id || pending || root.dataset.editorBusy === 'true') return;
        pending = true;
        let started = false;
        try {
            if (!await confirm('Confirma a exclusão desta ATA e de seus produtos, participantes e aditivos? O histórico será preservado.', root.ownerDocument)) return;
            if (root.dataset.ataLoaded !== id || root.dataset.ataVersion !== version || root.dataset.editorBusy === 'true') return;
            started = true;
            root.dispatchEvent(new CustomEvent('ata-saving', { bubbles: true }));
            const response = await fetcher(button.dataset.deleteUrl.replace(/\/0$/, `/${encodeURIComponent(id)}`), {
                method: 'DELETE', credentials: 'same-origin', headers: { Accept: 'application/json',
                    'Content-Type': 'application/json', 'X-CSRF-TOKEN': root.dataset.csrfToken },
                body: JSON.stringify({ version: Number(version) }),
            });
            const data = await response.json();
            if (root.dataset.ataLoaded !== id) return;
            if (!response.ok) {
                warn(data.message || 'Não foi possível excluir a ATA. Consulte novamente antes de tentar outra vez.', root.ownerDocument);
                return;
            }
            root.dispatchEvent(new CustomEvent('ata-deleted', { bubbles: true }));
        } catch {
            warn('Não foi possível confirmar a exclusão. Consulte novamente a ATA.', root.ownerDocument);
        } finally {
            pending = false;
            if (started) root.dispatchEvent(new CustomEvent('ata-save-finished', { bubbles: true }));
        }
    });
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('.ata-editor').forEach(root => {
        initializeAtaSave(root);
        initializeAtaDelete(root);
    });
}
