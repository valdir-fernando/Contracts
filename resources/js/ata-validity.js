import { isoDate } from './input-masks.js';
import { confirmSystemMessage, showSupplierDocumentWarning } from './system-message.js';
import { ataPublicationDates } from './ata-publication-dates.js';

export function initializeAtaValidity(root, confirm = confirmSystemMessage) {
    const panel = root.querySelector('[data-ata-validity]');
    if (!panel) return;
    const field = name => panel.querySelector(`[name="${name}"]`);
    const start = field('start_date'), end = field('end_date'), period = field('validity_period'), mode = field('one_year_inpc');
    const publicationField = panel.querySelector('[data-ata-publication-field]');
    const publication = field('publication_date');
    const inpcNotice = panel.querySelector('[data-ata-inpc-notice]');
    const values = () => ({ start_date: start.value ? isoDate(start.value) : null, end_date: end.value ? isoDate(end.value) : null,
        validity_period: period.value.trim() || null, one_year_inpc: mode.checked });
    let baseline = JSON.stringify(values());
    let savedPeriod = '';
    let generatedDates = false;
    const setGeneratedDates = generated => {
        generatedDates = generated;
        [start, end].forEach(input => { input.dataset.autoCalculated = String(generated); });
    };
    let modeRevision = 0;
    const applyMode = () => {
        if (inpcNotice) inpcNotice.hidden = !mode.checked;
        [start, end].forEach(input => {
            input.dataset.validityDisabled = String(mode.checked);
            input.disabled = mode.checked || root.dataset.editorBusy === 'true';
            input.parentElement?.querySelectorAll('.date-picker-button, .date-native-picker').forEach(control => { control.disabled = input.disabled; });
        });
    };
    const dirty = () => { root.dataset.ataValidityDirty = String(JSON.stringify(values()) !== baseline); };
    const date = (input, value) => {
        input.value = input.type === 'date' ? value ?? '' : (value ?? '').split('-').reverse().join('/');
        input.setCustomValidity?.('');
    };
    const calculateDates = () => {
        if (!mode.checked) return;
        const dates = ataPublicationDates(publication?.value ?? '');
        if (dates) {
            date(start, dates.start_date); date(end, dates.end_date);
            setGeneratedDates(true);
        } else if (generatedDates) {
            date(start, null); date(end, null);
            setGeneratedDates(false);
        }
        dirty();
    };
    publication?.addEventListener('input', calculateDates);
    publication?.addEventListener('change', calculateDates);
    mode.addEventListener('change', async () => {
        const revision = ++modeRevision;
        if (mode.checked && (start.value || end.value)) {
            mode.checked = false;
            const accepted = await confirm('Ao marcar a opção "1 Ano INPC" a "Data_Inicial" e a "Data_Final" serão preenchidas automaticamente após a inclusão da "Data de Publicação no INPC"', root.ownerDocument,
                { emphasize: ['1 Ano INPC', 'Data_Inicial', 'Data_Final', 'Data de Publicação no INPC'] });
            if (revision !== modeRevision) return;
            if (!accepted) { applyMode(); dirty(); return; }
            mode.checked = true;
            start.value = ''; end.value = '';
            setGeneratedDates(false);
        }
        period.value = mode.checked ? '1 Ano INPC' : savedPeriod;
        if (mode.checked) calculateDates();
        applyMode(); dirty();
    });
    period.addEventListener('input', () => {
        modeRevision++;
        if (mode.checked && period.value !== '1 Ano INPC') { mode.checked = false; applyMode(); }
        dirty();
    });
    [start, end].forEach(input => {
        const notice = input.closest?.('.ata-validity-date-control')?.querySelector('[data-ata-locked-date]');
        notice?.addEventListener('mousedown', event => {
            if (input.disabled) event.preventDefault();
        });
        notice?.addEventListener('click', () => {
            if (input.disabled) showSupplierDocumentWarning('Campo bloqueado para edição', root.ownerDocument);
        });
        const edited = () => { generatedDates = false; input.dataset.autoCalculated = 'false'; dirty(); };
        input.addEventListener('input', edited); input.addEventListener('change', edited);
    });
    const load = data => {
        modeRevision++;
        setGeneratedDates(false);
        if (publicationField) field('publication_date').value = '';
        savedPeriod = data.validity_period ?? '';
        date(start, data.start_date); date(end, data.end_date);
        mode.checked = data.one_year_inpc === true;
        period.value = mode.checked ? '1 Ano INPC' : savedPeriod;
        applyMode();
        baseline = JSON.stringify(values()); dirty();
    };
    root.addEventListener('ata-loaded', ({ detail }) => load(detail));
    root.addEventListener('ata-cleared', () => load({}));
    applyMode();
}

if (typeof document !== 'undefined') document.querySelectorAll('.ata-editor').forEach(root => initializeAtaValidity(root));
