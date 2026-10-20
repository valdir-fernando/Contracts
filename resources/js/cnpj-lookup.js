import { maskPhone } from './input-masks.js';
import { showSupplierDocumentWarning } from './system-message.js';

const labels = { name: 'Razão social', Nome_Fantasia: 'Nome fantasia', Endereco: 'Endereço completo',
    End_Fundo: 'Endereço completo', Fone_Fixo: 'Telefone fixo', Fone_Celular: 'Celular',
    Telefone: 'Telefone', Email_Fornecedor: 'E-mail' };

export function showCompanyPartners(form, partners, isCurrent) {
    const container = form.querySelector('[data-company-partners]');
    const select = form.querySelector('[data-company-partner-selection]');
    if (!container || !select) return;
    select.replaceChildren();
    const option = (value, label) => {
        const element = form.ownerDocument.createElement('option');
        element.value = value;
        element.textContent = label;
        select.append(element);
    };
    const entries = Array.isArray(partners) ? partners.filter(partner => typeof partner.name === 'string' && partner.name.trim()) : [];
    option('', entries.length ? 'Selecione quem assinará' : 'Nenhum sócio informado pela consulta; preencha manualmente');
    entries.forEach((partner, index) => option(String(index), `${partner.name} — ${partner.qualification || 'Qualificação não informada'}`));
    container.hidden = false;
    select.onchange = () => {
        if (!isCurrent() || select.value === '') return;
        const partner = entries[Number(select.value)];
        if (!partner) return;
        applyCompanyValues(form, { Nome_Socio: partner.name }, false);
        // Documents from another selected person must not follow the new name.
        for (const name of ['CPF_Socio', 'RG_Socio']) {
            const field = form.elements.namedItem(name);
            if (field && !field.disabled && !field.readOnly) {
                field.value = '';
                field.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }
    };
}

export function applyCompanyValues(form, values, onlyEmpty = true) {
    const changed = [];
    for (const [name, value] of Object.entries(values)) {
        const field = form.elements.namedItem(name);
        if (!field || field.disabled || field.readOnly || !value || (onlyEmpty && field.value.trim())) continue;
        const phone = ['Fone_Fixo', 'Fone_Celular', 'Telefone'].includes(name);
        const before = field.value;
        field.value = phone ? maskPhone(value) : value;
        field.dispatchEvent(new Event('input', { bubbles: true }));
        changed.push([field, { before, automatic: field.value }]);
    }
    return changed;
}

export function showCompanyComparison(form, data, kind, isCurrent) {
    const doc = form.ownerDocument;
    doc.querySelector('[data-cnpj-comparison]')?.remove();
    const dialog = doc.createElement('dialog');
    dialog.className = 'catalog-modal cnpj-comparison';
    dialog.dataset.cnpjComparison = '';
    dialog.setAttribute('aria-labelledby', 'cnpj-comparison-title');
    dialog.innerHTML = '<div class="modal-header"><h2 id="cnpj-comparison-title">Conferência do CNPJ</h2></div>'
        + '<p data-company-source></p><p>Confira os dados cadastrados com os dados da consulta. As alterações só serão gravadas ao salvar.</p>'
        + '<div class="table-scroll"><table class="data-table"><thead><tr><th>Campo</th><th>Cadastro atual</th><th>Dados consultados</th></tr></thead><tbody></tbody></table></div>'
        + '<div class="form-actions"><button type="button" class="secondary-button" data-keep>Manter cadastro</button>'
        + '<button type="button" class="primary-button" data-apply>Usar dados consultados</button></div>';
    dialog.querySelector('[data-company-source]').textContent = `${data.source} · CNPJ ${data.cnpj} · Situação: ${data.registration_status || 'Não informada'}`;
    const values = data[kind];
    for (const [name, value] of Object.entries(values)) {
        const field = form.elements.namedItem(name);
        if (!field) continue;
        const row = doc.createElement('tr');
        const current = field.value;
        for (const text of [labels[name] ?? name, current || 'Não informado', value || 'Não informado']) {
            const cell = doc.createElement('td');
            cell.textContent = text;
            row.append(cell);
        }
        if (field.readOnly) row.firstChild.textContent += ' (preservado)';
        dialog.querySelector('tbody').append(row);
    }
    dialog.querySelector('[data-keep]').addEventListener('click', () => dialog.close());
    dialog.querySelector('[data-apply]').addEventListener('click', () => {
        if (isCurrent()) applyCompanyValues(form, values, false);
        dialog.close();
    });
    dialog.addEventListener('close', () => dialog.remove(), { once: true });
    doc.body.append(dialog);
    dialog.showModal();
}

export async function consultCompany(form, { cnpj, kind, existing, signal, isCurrent }, fetcher = fetch,
    notify = (text) => showSupplierDocumentWarning(text, form.ownerDocument)) {
    if (!form.dataset.cnpjLookupUrl || cnpj.length !== 14) return;
    const status = form.querySelector('[data-registration-lookup-status], [data-fund-lookup-status]');
    const message = (value) => { if (status) { status.textContent = value; status.hidden = !value; } };
    message('Consultando dados públicos do CNPJ…');
    try {
        const response = await fetcher(form.dataset.cnpjLookupUrl, {
            method: 'POST', credentials: 'same-origin', signal,
            headers: { Accept: 'application/json', 'Content-Type': 'application/json',
                'X-CSRF-TOKEN': form.elements.namedItem('_token')?.value ?? '' },
            body: JSON.stringify({ cnpj }),
        });
        if (!isCurrent()) return;
        if (!response.ok) throw new Error('lookup');
        const data = await response.json();
        if (!isCurrent()) return;
        if (data.status !== 'found') {
            message('CNPJ não encontrado na consulta externa. Você pode preencher os dados manualmente.');
            return;
        }
        if (kind === 'supplier' && data.partners) showCompanyPartners(form, data.partners, isCurrent);
        if (existing) showCompanyComparison(form, data, kind, isCurrent);
        const changed = existing ? [] : applyCompanyValues(form, data[kind]);
        if (existing) message('Consulta concluída. Confira os dados na janela.');
        else {
            message('');
            notify('Dados públicos preenchidos. Confira antes de salvar.');
        }
        return changed;
    } catch (error) {
        if (isCurrent() && error.name !== 'AbortError') message('Consulta externa indisponível. Os dados do cadastro foram preservados; você pode continuar manualmente.');
    }
}
