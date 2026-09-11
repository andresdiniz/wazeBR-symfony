export function initFooter(root = document) {
    const footer = root.querySelector('[data-component="footer"]');
    if (!footer || footer.dataset.initialized === 'true') return;
    footer.dataset.initialized = 'true';

    const topButton = footer.querySelector('[data-action="scroll-to-top"]');
    const year = footer.querySelector('[data-footer-year]');
    const updateTopButton = () => {
        if (topButton) topButton.hidden = window.scrollY < 360;
    };

    year && (year.textContent = String(new Date().getFullYear()));
    updateTopButton();
    window.addEventListener('scroll', updateTopButton, { passive: true });
    topButton?.addEventListener('click', () => {
        window.scrollTo({ top: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    });
}
