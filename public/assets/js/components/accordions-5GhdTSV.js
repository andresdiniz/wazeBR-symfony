export function initAccordions(root = document) {
    root.querySelectorAll('[data-accordion]').forEach((accordion) => {
        if (accordion.dataset.initialized === 'true') return;
        accordion.dataset.initialized = 'true';
        accordion.querySelector('[data-accordion-toggle]')?.addEventListener('click', () => { accordion.classList.toggle('is-open'); });
    });
}
