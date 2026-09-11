export function initLanding(root = document) {
    const page = root.querySelector('.landing-page');
    if (!page || page.dataset.initialized === 'true') return;
    page.dataset.initialized = 'true';

    const header = page.querySelector('.landing-header');
    const menuButton = page.querySelector('[data-landing-menu]');
    menuButton?.addEventListener('click', () => {
        const open = header.classList.toggle('is-open');
        menuButton.setAttribute('aria-expanded', String(open));
    });

    const revealItems = page.querySelectorAll('[data-landing-reveal]');
    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries, currentObserver) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    currentObserver.unobserve(entry.target);
                }
            });
        }, { threshold: .12 });
        revealItems.forEach((item) => observer.observe(item));
    } else {
        revealItems.forEach((item) => item.classList.add('is-visible'));
    }
}
