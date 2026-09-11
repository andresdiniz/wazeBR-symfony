export function initSidebar(root = document) {
    const sidebar = root.querySelector('[data-sidebar]');
    if (!sidebar || sidebar.dataset.initialized === 'true') {
        return;
    }

    sidebar.dataset.initialized = 'true';
    const collapseButton = root.querySelector('[data-action="toggle-sidebar-collapse"]');
    const overlay = root.querySelector('[data-sidebar-overlay]');
    const mobileToggle = root.querySelector('[data-action="toggle-sidebar"]');

    const isMobile = () => window.matchMedia('(max-width: 900px)').matches;

    const setMobileState = (open) => {
        document.body.classList.toggle('sidebar-open', open);
        if (overlay) {
            overlay.hidden = !open;
        }
        if (mobileToggle) {
            mobileToggle.setAttribute('aria-expanded', String(open));
        }
    };

    collapseButton?.addEventListener('click', () => {
        if (isMobile()) {
            setMobileState(false);
            return;
        }

        const collapsed = document.body.classList.toggle('sidebar-collapsed');
        collapseButton.setAttribute('aria-expanded', String(!collapsed));
        collapseButton.setAttribute(
            'aria-label',
            collapsed ? 'Expandir menu lateral' : 'Recolher menu lateral'
        );
    });

    mobileToggle?.addEventListener('click', () => {
        setMobileState(!document.body.classList.contains('sidebar-open'));
    });

    overlay?.addEventListener('click', () => setMobileState(false));

    root.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            setMobileState(false);
        }
    });

    window.addEventListener('resize', () => {
        if (!isMobile()) {
            setMobileState(false);
        }
    });
}
