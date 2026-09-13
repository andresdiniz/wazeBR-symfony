export function initNotifications(root = document) {
    const header = root.querySelector('[data-app-header]');
    if (!header || header.dataset.notificationsInitialized === 'true') return;
    header.dataset.notificationsInitialized = 'true';
    header.querySelector('[data-header-notifications-read]')?.addEventListener('click', () => {
        header.querySelectorAll('.app-header__notification-item.is-unread').forEach((item) => item.classList.remove('is-unread'));
        const badge = header.querySelector('.app-header__notification-badge');
        if (badge) badge.hidden = true;
    });
}
