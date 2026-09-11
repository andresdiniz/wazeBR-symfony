export function initDashboard(root = document) {
    const dashboard = root.querySelector('[data-dashboard]');
    if (!dashboard || dashboard.dataset.initialized === 'true') return;
    dashboard.dataset.initialized = 'true';

    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const revealItems = dashboard.querySelectorAll('.dashboard-reveal');

    if ('IntersectionObserver' in window && !reducedMotion) {
        const observer = new IntersectionObserver((entries, currentObserver) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    currentObserver.unobserve(entry.target);
                }
            });
        }, { threshold: .08 });
        revealItems.forEach((item, index) => {
            item.style.transitionDelay = `${Math.min(index * 45, 280)}ms`;
            observer.observe(item);
        });
    } else {
        revealItems.forEach((item) => item.classList.add('is-visible'));
    }

    dashboard.querySelectorAll('[data-count-value]').forEach((element) => {
        const target = Number(element.dataset.countValue || 0);
        if (reducedMotion || !target) return;
        const start = performance.now();
        const duration = 850;
        const tick = (now) => {
            const progress = Math.min((now - start) / duration, 1);
            element.textContent = String(Math.round(target * (1 - Math.pow(1 - progress, 3))));
            if (progress < 1) requestAnimationFrame(tick);
        };
        requestAnimationFrame(tick);
    });

    const updateClock = () => {
        const clock = dashboard.querySelector('[data-dashboard-clock]');
        if (clock) clock.textContent = new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit' }).format(new Date());
    };
    updateClock();
    window.setInterval(updateClock, 30000);

    dashboard.querySelectorAll('[data-dashboard-filter]').forEach((button) => {
        button.addEventListener('click', () => {
            dashboard.querySelectorAll('[data-dashboard-filter]').forEach((item) => item.classList.remove('is-active'));
            button.classList.add('is-active');
            const filter = button.dataset.dashboardFilter;
            dashboard.querySelectorAll('[data-dashboard-section]').forEach((section) => {
                section.hidden = filter !== 'all' && section.dataset.dashboardSection !== filter && !(filter === 'traffic' && section.dataset.dashboardSection === 'irregularities');
            });
        });
    });

    dashboard.querySelector('[data-action="refresh-dashboard"]')?.addEventListener('click', (event) => {
        const button = event.currentTarget;
        button.classList.add('is-loading');
        window.setTimeout(() => {
            button.classList.remove('is-loading');
            updateClock();
        }, 650);
    });
}
