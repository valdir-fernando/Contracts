const numberFormat = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 0 });
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
const counters = new WeakMap();
const activeCounters = new Set();
const initializedPanels = new WeakSet();

function updateCounter(element) {
    const target = Number(element.dataset.countUp);
    const display = element.querySelector('[data-count-up-value]');
    if (!display || !Number.isSafeInteger(target) || target < 0) return;
    let state = counters.get(element);
    if (state?.target === target) return;
    if (!state) {
        state = { element, display, value: 0, frame: null };
        counters.set(element, state);
    }
    cancelAnimationFrame(state.frame);
    state.target = target;
    const finish = () => {
        state.value = target;
        display.textContent = numberFormat.format(target);
        activeCounters.delete(state);
    };
    if (reducedMotion.matches || state.value === target) {
        finish();
        return;
    }
    const start = state.value;
    const duration = 1100;
    const startedAt = performance.now();
    activeCounters.add(state);
    const tick = (now) => {
        if (!element.isConnected) {
            activeCounters.delete(state);
            return;
        }
        const progress = Math.min(1, (now - startedAt) / duration);
        const eased = 1 - (1 - progress) ** 3;
        state.value = Math.round(start + (target - start) * eased);
        display.textContent = numberFormat.format(state.value);
        if (progress < 1) state.frame = requestAnimationFrame(tick);
        else finish();
    };
    display.textContent = numberFormat.format(start);
    state.frame = requestAnimationFrame(tick);
}

function initializeCounters() {
    document.querySelectorAll('.overview-kpis').forEach((panel) => {
        if (initializedPanels.has(panel)) return;
        initializedPanels.add(panel);
        panel.querySelectorAll('[data-count-up]').forEach(updateCounter);
        // Livewire updates the target attribute while preserving the animated text.
        new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                if (mutation.type === 'attributes') updateCounter(mutation.target);
                else mutation.addedNodes.forEach((node) => {
                    if (!(node instanceof Element)) return;
                    if (node.matches('[data-count-up]')) updateCounter(node);
                    node.querySelectorAll('[data-count-up]').forEach(updateCounter);
                });
            });
        }).observe(panel, { attributes: true, attributeFilter: ['data-count-up'], childList: true, subtree: true });
    });
}

reducedMotion.addEventListener('change', () => {
    if (!reducedMotion.matches) return;
    activeCounters.forEach((state) => {
        cancelAnimationFrame(state.frame);
        state.value = state.target;
        state.display.textContent = numberFormat.format(state.target);
    });
    activeCounters.clear();
});
initializeCounters();
document.addEventListener('livewire:navigated', initializeCounters);
