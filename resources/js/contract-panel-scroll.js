export function scrollContractSection(section) {
    const panels = section.closest('[data-contract-panels-scroll]');
    if (panels) {
        panels.scrollTop += section.getBoundingClientRect().top - panels.getBoundingClientRect().top - panels.clientTop;
    } else {
        section.scrollIntoView({ block: 'start', behavior: 'instant' });
    }
    section.querySelector('h2')?.focus({ preventScroll: true });
}
