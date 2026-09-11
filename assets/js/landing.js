export function initLanding(root = document) {
    const page = root.querySelector('.landing-page');
    if (!page || page.dataset.initialized === 'true') return;
    page.dataset.initialized = 'true';

    const header = page.querySelector('.landing-header');
    const menuButton = page.querySelector('[data-landing-menu]');
    const visual = page.querySelector('.landing-hero-visual');

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
        revealItems.forEach((item, index) => {
            item.style.transitionDelay = `${Math.min(index * 70, 420)}ms`;
            observer.observe(item);
        });
    } else {
        revealItems.forEach((item) => item.classList.add('is-visible'));
    }

    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!prefersReducedMotion && visual && window.matchMedia('(min-width: 901px)').matches) {
        let frame;
        visual.addEventListener('pointermove', (event) => {
            const rect = visual.getBoundingClientRect();
            const x = (event.clientX - rect.left) / rect.width - .5;
            const y = (event.clientY - rect.top) / rect.height - .5;
            cancelAnimationFrame(frame);
            frame = requestAnimationFrame(() => {
                visual.style.transform = `translate3d(${x * 10}px, ${y * 8}px, 0)`;
            });
        });
        visual.addEventListener('pointerleave', () => {
            cancelAnimationFrame(frame);
            visual.style.transform = '';
        });
    }

    const counters = page.querySelectorAll('[data-count]');
    const animateCounter = (element) => {
        const target = Number(element.dataset.count);
        const duration = 1100;
        const start = performance.now();
        const step = (now) => {
            const progress = Math.min((now - start) / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            element.textContent = String(Math.round(target * eased));
            if (progress < 1) requestAnimationFrame(step);
        };
        requestAnimationFrame(step);
    };

    if ('IntersectionObserver' in window && !prefersReducedMotion) {
        const counterObserver = new IntersectionObserver((entries, currentObserver) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    animateCounter(entry.target);
                    currentObserver.unobserve(entry.target);
                }
            });
        }, { threshold: .7 });
        counters.forEach((counter) => counterObserver.observe(counter));
    }
}
