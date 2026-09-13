/**
 * layout/header.js
 *
 * Header global do WazeBR.
 *
 * Data attributes:
 *
 *   data-app-header
 *   data-header-notifications-toggle
 *   data-header-notifications-read
 *   data-header-notifications
 *   data-header-user-toggle
 *   data-header-user-menu
 *   data-header-mobile-toggle
 *   data-header-navigation
 *   data-header-search-input
 *
 * Responsabilidades:
 *
 *   - Notificações
 *   - Marcar notificações como lidas
 *   - Menu do usuário
 *   - Navegação mobile
 *   - Ctrl/Cmd + K
 *   - Escape
 *   - Clique fora
 *   - Navegação por teclado
 *   - Acessibilidade ARIA
 */

export function initHeader(doc = globalThis.document) {
    if (!doc) return;

    const header = doc.querySelector('[data-app-header]');

    if (!header) return;

    if (header.dataset.wazebrInitialized === 'true') {
        return;
    }

    header.dataset.wazebrInitialized = 'true';

    initNotifications(header, doc);
    initUserMenu(header, doc);
    initMobileNav(header, doc);
    initSearchShortcut(header, doc);
    initTheme(header, doc);
    initGlobalClose(header, doc);
    initResponsiveState(header, doc);
}


// ============================================================================
// NOTIFICAÇÕES
// ============================================================================

function initNotifications(header, doc) {
    const btn = header.querySelector(
        '[data-header-notifications-toggle]'
    );

    const dropdown = header.querySelector(
        '[data-header-notifications]'
    );

    const readBtn = header.querySelector(
        '[data-header-notifications-read]'
    );

    const badge = header.querySelector(
        '.app-header__notification-badge'
    );

    if (!btn || !dropdown) {
        return;
    }

    btn.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();

        const isOpen = !dropdown.hidden;

        closeUserMenu(header);
        closeMobileNav(header);

        if (isOpen) {
            close(dropdown, btn);
            return;
        }

        open(dropdown, btn);
    });

    readBtn?.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();

        const unreadItems = header.querySelectorAll(
            '.app-header__notification-item.is-unread'
        );

        unreadItems.forEach((item) => {
            item.classList.remove('is-unread');
        });

        if (badge) {
            badge.hidden = true;
            badge.textContent = '0';
        }

        btn.setAttribute(
            'aria-label',
            'Notificações'
        );

        readBtn.remove();
    });

    initKeyboard(
        dropdown,
        btn,
        doc
    );
}


// ============================================================================
// MENU DO USUÁRIO
// ============================================================================

function initUserMenu(header, doc) {
    const btn = header.querySelector(
        '[data-header-user-toggle]'
    );

    const dropdown = header.querySelector(
        '[data-header-user-menu]'
    );

    if (!btn || !dropdown) {
        return;
    }

    btn.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();

        const isOpen = !dropdown.hidden;

        closeNotifications(header);
        closeMobileNav(header);

        if (isOpen) {
            close(dropdown, btn);
            return;
        }

        open(dropdown, btn);
    });

    initKeyboard(
        dropdown,
        btn,
        doc
    );
}


// ============================================================================
// NAVEGAÇÃO MOBILE
// ============================================================================

function initMobileNav(header, doc) {
    const btn = header.querySelector(
        '[data-header-mobile-toggle]'
    );

    const nav = header.querySelector(
        '[data-header-navigation]'
    );

    if (!btn || !nav) {
        return;
    }

    btn.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();

        const isOpen =
            btn.getAttribute('aria-expanded') === 'true';

        setMobileNavState(
            header,
            !isOpen
        );
    });

    nav.addEventListener('click', (event) => {
        const link = event.target.closest('a');

        if (!link) {
            return;
        }

        setMobileNavState(
            header,
            false
        );
    });
}


// ============================================================================
// BUSCA — CTRL/CMD + K
// ============================================================================

function initSearchShortcut(header, doc) {
    const input = header.querySelector(
        '[data-header-search-input]'
    );

    if (!input) {
        return;
    }

    doc.addEventListener('keydown', (event) => {
        const isShortcut =
            (event.metaKey || event.ctrlKey) &&
            event.key.toLowerCase() === 'k';

        if (!isShortcut) {
            return;
        }

        event.preventDefault();

        input.focus();

        try {
            input.select();
        } catch {
            // Alguns elementos/input implementations podem não suportar select().
        }
    });
}


// ============================================================================
// TEMA
// ============================================================================

function initTheme(header, doc) {
    const select = header.querySelector(
        '[data-theme-select]'
    );

    if (!select) {
        return;
    }

    const storageKey = 'wazebr-theme';

    let savedTheme = null;

    try {
        savedTheme = localStorage.getItem(storageKey);
    } catch {
        savedTheme = null;
    }

    const validThemes = [
        'system',
        'light',
        'dark'
    ];

    if (validThemes.includes(savedTheme)) {
        select.value = savedTheme;
        applyTheme(savedTheme, doc);
    } else {
        select.value = 'system';
        applyTheme('system', doc);
    }

    select.addEventListener('change', () => {
        const theme = validThemes.includes(select.value)
            ? select.value
            : 'system';

        applyTheme(
            theme,
            doc
        );

        try {
            localStorage.setItem(
                storageKey,
                theme
            );
        } catch {
            // localStorage pode estar indisponível.
        }
    });
}

function applyTheme(theme, doc) {
    const root = doc.documentElement;

    if (theme === 'system') {
        root.removeAttribute(
            'data-theme'
        );

        return;
    }

    root.setAttribute(
        'data-theme',
        theme
    );
}


// ============================================================================
// FECHAMENTO GLOBAL
// ============================================================================

function initGlobalClose(header, doc) {

    doc.addEventListener('click', () => {
        closeNotifications(header);
        closeUserMenu(header);

        setMobileNavState(
            header,
            false
        );
    });

    doc.addEventListener('keydown', (event) => {

        if (event.key !== 'Escape') {
            return;
        }

        const notif = header.querySelector(
            '[data-header-notifications]'
        );

        const notifBtn = header.querySelector(
            '[data-header-notifications-toggle]'
        );

        if (notif && !notif.hidden) {
            close(
                notif,
                notifBtn,
                true
            );

            return;
        }

        const user = header.querySelector(
            '[data-header-user-menu]'
        );

        const userBtn = header.querySelector(
            '[data-header-user-toggle]'
        );

        if (user && !user.hidden) {
            close(
                user,
                userBtn,
                true
            );

            return;
        }

        setMobileNavState(
            header,
            false,
            true
        );
    });
}


// ============================================================================
// RESPONSIVIDADE
// ============================================================================

function initResponsiveState(header, doc) {
    const mediaQuery = doc.defaultView?.matchMedia(
        '(max-width: 1180px)'
    );

    if (!mediaQuery) {
        return;
    }

    const handleChange = (event) => {
        if (!event.matches) {
            setMobileNavState(
                header,
                false
            );
        }
    };

    if (typeof mediaQuery.addEventListener === 'function') {
        mediaQuery.addEventListener(
            'change',
            handleChange
        );
    } else if (typeof mediaQuery.addListener === 'function') {
        mediaQuery.addListener(
            handleChange
        );
    }
}


// ============================================================================
// KEYBOARD
// ============================================================================

function initKeyboard(dropdown, triggerBtn, doc) {

    dropdown.addEventListener('keydown', (event) => {

        const items = [
            ...dropdown.querySelectorAll(
                'a[href], button:not([hidden])'
            )
        ].filter((element) => {
            return (
                !element.hidden &&
                element.tabIndex !== -1 &&
                isVisible(element)
            );
        });

        if (!items.length) {
            return;
        }

        const currentIndex =
            items.indexOf(doc.activeElement);

        switch (event.key) {

            case 'ArrowDown': {
                event.preventDefault();

                const nextIndex =
                    currentIndex < 0
                        ? 0
                        : (currentIndex + 1) % items.length;

                items[nextIndex].focus();

                break;
            }

            case 'ArrowUp': {
                event.preventDefault();

                const previousIndex =
                    currentIndex <= 0
                        ? items.length - 1
                        : currentIndex - 1;

                items[previousIndex].focus();

                break;
            }

            case 'Home': {
                event.preventDefault();

                items[0].focus();

                break;
            }

            case 'End': {
                event.preventDefault();

                items[items.length - 1].focus();

                break;
            }

            case 'Escape': {
                event.preventDefault();

                close(
                    dropdown,
                    triggerBtn,
                    true
                );

                break;
            }
        }
    });
}


// ============================================================================
// HELPERS — OPEN/CLOSE
// ============================================================================

function open(dropdown, btn) {
    dropdown.hidden = false;

    btn?.setAttribute(
        'aria-expanded',
        'true'
    );

    const firstFocusable = dropdown.querySelector(
        'a[href], button:not([hidden])'
    );

    if (firstFocusable) {
        requestAnimationFrame(() => {
            firstFocusable.focus();
        });
    }
}

function close(
    dropdown,
    btn,
    returnFocus = false
) {
    dropdown.hidden = true;

    btn?.setAttribute(
        'aria-expanded',
        'false'
    );

    if (
        returnFocus &&
        btn &&
        typeof btn.focus === 'function'
    ) {
        requestAnimationFrame(() => {
            btn.focus();
        });
    }
}


// ============================================================================
// HELPERS — NOTIFICAÇÕES
// ============================================================================

function closeNotifications(header) {
    const dropdown = header.querySelector(
        '[data-header-notifications]'
    );

    const btn = header.querySelector(
        '[data-header-notifications-toggle]'
    );

    if (dropdown && !dropdown.hidden) {
        close(
            dropdown,
            btn
        );
    }
}


// ============================================================================
// HELPERS — USUÁRIO
// ============================================================================

function closeUserMenu(header) {
    const dropdown = header.querySelector(
        '[data-header-user-menu]'
    );

    const btn = header.querySelector(
        '[data-header-user-toggle]'
    );

    if (dropdown && !dropdown.hidden) {
        close(
            dropdown,
            btn
        );
    }
}


// ============================================================================
// HELPERS — MOBILE
// ============================================================================

function setMobileNavState(
    header,
    openState,
    returnFocus = false
) {
    const btn = header.querySelector(
        '[data-header-mobile-toggle]'
    );

    const nav = header.querySelector(
        '[data-header-navigation]'
    );

    if (!btn || !nav) {
        return;
    }

    btn.setAttribute(
        'aria-expanded',
        String(openState)
    );

    btn.setAttribute(
        'aria-label',
        openState
            ? 'Fechar navegação'
            : 'Abrir navegação'
    );

    nav.classList.toggle(
        'is-open',
        openState
    );

    if (
        returnFocus &&
        !openState
    ) {
        btn.focus();
    }
}

function closeMobileNav(header) {
    setMobileNavState(
        header,
        false
    );
}


// ============================================================================
// HELPERS — VISIBILIDADE
// ============================================================================

function isVisible(element) {
    if (!element) {
        return false;
    }

    const style = globalThis.getComputedStyle?.(element);

    if (!style) {
        return true;
    }

    return (
        style.display !== 'none' &&
        style.visibility !== 'hidden'
    );
}
