import { isoDate } from './input-masks.js';

export function ataDateError(value, acceptedDate = null) {
    if (!value) return '';
    const iso = isoDate(value);
    if (!iso) return 'Informe uma data válida no formato dd/mm/aaaa.';
    if (iso === acceptedDate) return '';
    const day = new Date(`${iso}T12:00:00Z`).getUTCDay();
    return day === 0 || day === 6 ? 'Informe um dia de segunda a sexta-feira.' : '';
}

export function confirmAtaDate(value, ownerDocument = document) {
    const dialog = ownerDocument.createElement('dialog');
    dialog.className = 'catalog-modal supplier-document-warning';
    dialog.setAttribute('role', 'alertdialog');
    dialog.setAttribute('aria-labelledby', 'ata-date-warning-title');
    dialog.setAttribute('aria-describedby', 'ata-date-warning-message');
    dialog.innerHTML = '<div class="modal-header"><h2 id="ata-date-warning-title">Gestão Sistem informa:</h2></div>'
        + '<p id="ata-date-warning-message"></p>'
        + '<form method="dialog" class="form-actions modal-actions"><button class="secondary-button" value="cancel" autofocus>Não, corrigir data</button>'
        + '<button class="primary-button" value="keep">Sim, manter data</button></form>';
    dialog.querySelector('p').textContent = `A data ${value} cai em um sábado ou domingo. Deseja manter essa data?`;
    ownerDocument.body.append(dialog);
    return new Promise(resolve => {
        dialog.addEventListener('close', () => {
            const keep = dialog.returnValue === 'keep';
            dialog.remove();
            resolve(keep);
        }, { once: true });
        dialog.showModal();
    });
}
