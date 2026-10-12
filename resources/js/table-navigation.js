// Delegation also covers rows replaced by Livewire or pagination.
const controls = 'a, button, input, select, textarea, label, form, summary, details, [role="button"], [role="link"], [contenteditable]:not([contenteditable="false"])';
function navigateRow(event) {
    if (event.defaultPrevented || !(event.target instanceof Element)) return;
    const row = event.target.closest('tr[data-row-href]');
    if (!row || event.target.closest(controls) || window.getSelection()?.toString().trim() || event.altKey) return;
    if (event.type === 'auxclick' && event.button !== 1) return;
    if (event.type === 'click' && event.button !== 0) return;
    const url = new URL(row.dataset.rowHref, location.href);
    if (url.origin !== location.origin || !['http:', 'https:'].includes(url.protocol)) return;
    event.preventDefault();
    if (event.button === 1 || event.ctrlKey || event.metaKey || event.shiftKey) {
        window.open(url.href, '_blank', 'noopener');
    } else {
        window.location.assign(url.href);
    }
}
document.addEventListener('click', navigateRow);
document.addEventListener('auxclick', navigateRow);
