export function closeMenus(root = document) {
    root.querySelectorAll('[data-header-notifications], [data-header-user-menu]').forEach((menu) => { menu.hidden = true; });
    root.querySelectorAll('[data-header-notifications-toggle], [data-header-user-toggle]').forEach((button) => { button.setAttribute('aria-expanded', 'false'); });
}

export function initMenus(root = document) {
    const header = root.querySelector('[data-app-header]');
    if (!header || header.dataset.menusInitialized === 'true') return;
    header.dataset.menusInitialized = 'true';
    const bind = (buttonSelector, menuSelector) => {
        const button = header.querySelector(buttonSelector);
        const menu = header.querySelector(menuSelector);
        if (!button || !menu) return;
        button.addEventListener('click', (event) => {
            event.stopPropagation();
            const open = menu.hidden;
            closeMenus(header);
            menu.hidden = !open;
            button.setAttribute('aria-expanded', String(open));
        });
    };
    bind('[data-header-notifications-toggle]', '[data-header-notifications]');
    bind('[data-header-user-toggle]', '[data-header-user-menu]');
}
