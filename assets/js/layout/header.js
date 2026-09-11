export function initHeader(root = document) {
    const header = root.querySelector('[data-component="header"]');
    if (!header || header.dataset.initialized === 'true') {
        return;
    }

    header.dataset.initialized = 'true';
    const userMenu = header.querySelector('[data-user-menu]');
    const userButton = header.querySelector('[data-action="toggle-user-menu"]');
    const userDropdown = header.querySelector('[data-user-dropdown]');
    const search = header.querySelector('[data-header-search]');
    const notifications = header.querySelector('[data-action="toggle-notifications"]');

    const setUserMenu = (open) => {
        if (!userDropdown || !userButton) {
            return;
        }
        userDropdown.hidden = !open;
        userButton.setAttribute('aria-expanded', String(open));
    };

    userButton?.addEventListener('click', () => {
        setUserMenu(userDropdown?.hidden ?? true);
    });

    document.addEventListener('click', (event) => {
        if (userMenu && !userMenu.contains(event.target)) {
            setUserMenu(false);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            setUserMenu(false);
        }

        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            search?.focus();
        }
    });

    notifications?.addEventListener('click', () => {
        notifications.classList.toggle('is-active');
    });
}
