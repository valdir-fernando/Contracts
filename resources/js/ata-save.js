import { showSupplierDocumentWarning } from './system-message.js';
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

export function initializeAtaSave(root, warn = showSupplierDocumentWarning, fetcher = fetch) {
    const button = root.querySelector('[data-ata-save]');
    let saving = false;
    const notify = (name, detail = {}) => root.dispatchEvent(new CustomEvent(name, { bubbles: true, detail }));
    button.addEventListener('click', async () => {
        if (button.disabled || saving || root.dataset.ataLoaded) return;
        const values = Object.fromEntries([...root.querySelectorAll('input[name], select[name], textarea[name]')]
            .map(input => [input.name, input.value]));
        const managerFields = [...root.querySelectorAll('[data-ata-contractor-field]')].map(field => ({
            value: field.textContent,
            label: root.querySelector(`label[for="${field.id}"]`).textContent.trim(),
        }));
        const missing = ataMissingFields(values, managerFields);
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
            const payload = {
                draft_token: root.dataset.draftToken,
                version: Number(button.dataset.version ?? JSON.parse(root.dataset.draftState).version),
                managing_fund_id: values.managing_fund_id, Data_ATA: isoDate(values.Data_ATA),
                ata_process: values.ata_process, bidding: values.bidding, bidding_number: values.bidding_number,
                fornecedor_documento_snapshot: values.fornecedor_documento_snapshot,
            };
            const response = await fetcher(button.dataset.saveUrl, {
                method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json',
                    'Content-Type': 'application/json', 'X-CSRF-TOKEN': root.dataset.csrfToken },
                body: JSON.stringify(payload),
            });
            const data = await response.json();
            if (!response.ok) {
                const message = Object.values(data.errors ?? {}).flat().join('; ')
                    || data.message || 'Não foi possível salvar a ata. Confira seu acesso e tente novamente.';
                warn(message, root.ownerDocument);
                return;
            }
            saved = data;
        } catch {
            warn('Não foi possível confirmar a gravação. Tente salvar novamente para confirmar a mesma ata, sem gerar outra numeração.', root.ownerDocument);
        } finally {
            saving = false;
            notify('ata-save-finished');
        }
        if (saved) {
            notify('ata-saved', saved);
            warn(`Ata ${saved.number} salva com sucesso.`, root.ownerDocument);
        }
    });
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('.ata-editor').forEach(root => initializeAtaSave(root));
}
