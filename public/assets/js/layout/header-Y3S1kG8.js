export function initHeader(document = globalThis.document) {
    if (!document) {
        return;
    }

    const header = document.querySelector('[data-app-header]');

    if (!header || header.dataset.headerInitialized === 'true') {
        return;
    }

    header.dataset.headerInitialized = 'true';

    initMobileMenu(header);
    initNotifications(header);
    initUserMenu(header);
    initTheme(header);
    initSearch(header);
    initOutsideClick(document, header);
}

function initMobileMenu(header) {
    const toggle = header.querySelector('[data-header-mobile-toggle]');
    const navigation = header.querySelector('[data-header-navigation]');

    if (!toggle || !navigation) {
        return;
    }

    toggle.addEventListener('click', () => {
        const isOpen = toggle.getAttribute('aria-expanded') === 'true';

        toggle.setAttribute('aria-expanded', String(!isOpen));
        navigation.classList.toggle('is-open', !isOpen);
    });
}

function initNotifications(header) {
    const toggle = header.querySelector('[data-header-notifications-toggle]');
    const menu = header.querySelector('[data-header-notifications]');

    if (!toggle || !menu) {
        return;
    }

    toggle.addEventListener('click', () => {
        const isOpen = toggle.getAttribute('aria-expanded') === 'true';

        closeDropdowns(header);

        toggle.setAttribute('aria-expanded', String(!isOpen));
        menu.hidden = isOpen;
    });

    const markRead = header.querySelector('[data-header-notifications-read]');

    markRead?.addEventListener('click', () => {
        header
            .querySelectorAll('.app-header__notification-item.is-unread')
            .forEach((item) => item.classList.remove('is-unread'));

        header
            .querySelector('.app-header__notification-badge')
            ?.remove();
    });
}

function initUserMenu(header) {
    const toggle = header.querySelector('[data-header-user-toggle]');
    const menu = header.querySelector('[data-header-user-menu]');

    if (!toggle || !menu) {
        return;
    }

    toggle.addEventListener('click', () => {
        const isOpen = toggle.getAttribute('aria-expanded') === 'true';

        closeDropdowns(header);

        toggle.setAttribute('aria-expanded', String(!isOpen));
        menu.hidden = isOpen;
    });
}

function initTheme(header) {
    const select = header.querySelector('[data-theme-select]');

    if (!select) {
        return;
    }

    const currentTheme = document.documentElement.dataset.theme || 'system';

    select.value = currentTheme;

    select.addEventListener('change', () => {
        const theme = ['system', 'light', 'dark'].includes(select.value)
            ? select.value
            : 'system';

        document.documentElement.dataset.theme = theme;

        try {
            localStorage.setItem('wazebr-theme', theme);
        } catch (error) {
            // Storage pode estar bloqueado pelo navegador.
        }
    });
}

function initSearch(header) {
    const form = header.querySelector('[data-header-search]');

    if (!form) {
        return;
    }

    form.addEventListener('submit', () => {
        const input = form.querySelector('[data-header-search-input]');

        if (input) {
            input.value = input.value.trim();
        }
    });
}

function initOutsideClick(document, header) {
    document.addEventListener('click', (event) => {
        if (!header.contains(event.target)) {
            closeDropdowns(header);
        }
    });
}

function closeDropdowns(header) {
    header
        .querySelectorAll('[data-header-notifications-toggle], [data-header-user-toggle]')
        .forEach((toggle) => toggle.setAttribute('aria-expanded', 'false'));

    header
        .querySelectorAll('[data-header-notifications], [data-header-user-menu]')
        .forEach((menu) => {
            menu.hidden = true;
        });
}
