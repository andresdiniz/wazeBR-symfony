export function initHeader(root = document) {
    const header = root.querySelector('[data-component="header"]');
    if (!header || header.dataset.initialized === 'true') {
        return;
    }

    header.dataset.initialized = 'true';

    const userMenu = header.querySelector('[data-user-menu]');
    const userButton = header.querySelector('[data-action="toggle-user-menu"]');
    const userDropdown = header.querySelector('[data-user-dropdown]');
    const notificationsMenu = header.querySelector('[data-notifications-menu]');
    const notificationsButton = header.querySelector('[data-action="toggle-notifications"]');
    const notificationsDropdown = header.querySelector('[data-notifications-dropdown]');
    const markReadButton = header.querySelector('[data-action="mark-notifications-read"]');
    const notificationsBadge = header.querySelector('.header-notification-badge');
    const notificationsEmpty = header.querySelector('[data-notifications-empty]');
    const search = header.querySelector('[data-header-search]');

    const setUserMenu = (open) => {
        if (!userDropdown || !userButton) return;
        userDropdown.hidden = !open;
        userButton.setAttribute('aria-expanded', String(open));
    };

    const setNotificationsMenu = (open) => {
        if (!notificationsDropdown || !notificationsButton) return;
        notificationsDropdown.hidden = !open;
        notificationsButton.setAttribute('aria-expanded', String(open));
    };

    userButton?.addEventListener('click', (event) => {
        event.stopPropagation();
        const open = userDropdown?.hidden ?? true;
        setNotificationsMenu(false);
        setUserMenu(open);
    });

    notificationsButton?.addEventListener('click', (event) => {
        event.stopPropagation();
        const open = notificationsDropdown?.hidden ?? true;
        setUserMenu(false);
        setNotificationsMenu(open);
    });

    markReadButton?.addEventListener('click', () => {
        header.querySelectorAll('.header-notification-item.is-unread').forEach((item) => {
            item.classList.remove('is-unread');
        });
        if (notificationsBadge) notificationsBadge.hidden = true;
        if (notificationsEmpty) notificationsEmpty.hidden = false;
    });

    document.addEventListener('click', (event) => {
        if (!userMenu?.contains(event.target)) setUserMenu(false);
        if (!notificationsMenu?.contains(event.target)) setNotificationsMenu(false);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            setUserMenu(false);
            setNotificationsMenu(false);
        }

        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            search?.focus();
        }
    });
}
