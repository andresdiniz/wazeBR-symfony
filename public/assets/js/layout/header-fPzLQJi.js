/**
 * layout/header.js
 * data-* alinhados ao header.html.twig real:
 *   data-header-notifications-toggle  → abre notificações
 *   data-header-notifications-read    → marca como lidas
 *   data-header-notifications         → dropdown de notificações
 *   data-header-user-toggle           → abre menu do usuário
 *   data-header-user-menu             → dropdown do usuário
 *   data-header-mobile-toggle         → abre nav mobile
 *   data-header-navigation            → nav mobile
 *   data-header-search-input          → input de busca
 */

export function initHeader(doc = globalThis.document) {
    const header = doc.querySelector('[data-app-header]');
    if (!header || header.dataset.wazebrInitialized === 'true') return;
    header.dataset.wazebrInitialized = 'true';

    initNotifications(header, doc);
    initUserMenu(header, doc);
    initMobileNav(header, doc);
    initSearchShortcut(header, doc);
    initGlobalClose(header, doc);
}

// ── Notificações ──────────────────────────────────────────────────────────────

function initNotifications(header, doc) {
    const btn      = header.querySelector('[data-header-notifications-toggle]');
    const dropdown = header.querySelector('[data-header-notifications]');
    const readBtn  = header.querySelector('[data-header-notifications-read]');
    const badge    = header.querySelector('.app-header__notification-badge');

    if (!btn || !dropdown) return;

    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const isOpen = !dropdown.hidden;
        closeUserMenu(header);
        isOpen ? close(dropdown, btn) : open(dropdown, btn);
    });

    readBtn?.addEventListener('click', () => {
        header.querySelectorAll('.app-header__notification-item.is-unread')
              .forEach((el) => el.classList.remove('is-unread'));
        if (badge) { badge.hidden = true; badge.textContent = '0'; }
    });

    initKeyboard(dropdown, btn, doc);
}

// ── Menu do usuário ───────────────────────────────────────────────────────────

function initUserMenu(header, doc) {
    const btn      = header.querySelector('[data-header-user-toggle]');
    const dropdown = header.querySelector('[data-header-user-menu]');

    if (!btn || !dropdown) return;

    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const isOpen = !dropdown.hidden;
        closeNotifications(header);
        isOpen ? close(dropdown, btn) : open(dropdown, btn);
    });

    initKeyboard(dropdown, btn, doc);
}

// ── Nav mobile ────────────────────────────────────────────────────────────────

function initMobileNav(header, doc) {
    const btn = header.querySelector('[data-header-mobile-toggle]');
    const nav = header.querySelector('[data-header-navigation]');
    if (!btn || !nav) return;

    btn.addEventListener('click', () => {
        const isOpen = btn.getAttribute('aria-expanded') === 'true';
        btn.setAttribute('aria-expanded', String(!isOpen));
        nav.classList.toggle('is-open', !isOpen);
    });
}

// ── Atalho ⌘K / Ctrl+K ────────────────────────────────────────────────────────

function initSearchShortcut(header, doc) {
    const input = header.querySelector('[data-header-search-input]');
    if (!input) return;

    doc.addEventListener('keydown', (e) => {
        if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
            e.preventDefault();
            input.focus();
            input.select();
        }
    });
}

// ── Fechar ao clicar fora / Escape ────────────────────────────────────────────

function initGlobalClose(header, doc) {
    doc.addEventListener('click', () => {
        closeNotifications(header);
        closeUserMenu(header);
    });

    doc.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        const notif = header.querySelector('[data-header-notifications]');
        const notifBtn = header.querySelector('[data-header-notifications-toggle]');
        if (notif && !notif.hidden) { close(notif, notifBtn, true); return; }

        const user = header.querySelector('[data-header-user-menu]');
        const userBtn = header.querySelector('[data-header-user-toggle]');
        if (user && !user.hidden) close(user, userBtn, true);
    });
}

// ── Navegação por teclado ─────────────────────────────────────────────────────

function initKeyboard(dropdown, triggerBtn, doc) {
    dropdown.addEventListener('keydown', (e) => {
        const items = [...dropdown.querySelectorAll('a, button:not([hidden])')].filter(
            (el) => !el.hidden && el.tabIndex !== -1
        );
        if (!items.length) return;
        const idx = items.indexOf(doc.activeElement);

        if (e.key === 'ArrowDown') { e.preventDefault(); items[(idx + 1) % items.length].focus(); }
        if (e.key === 'ArrowUp')   { e.preventDefault(); items[(idx - 1 + items.length) % items.length].focus(); }
    });
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function open(dropdown, btn) {
    dropdown.hidden = false;
    btn?.setAttribute('aria-expanded', 'true');
    const first = dropdown.querySelector('a, button:not([hidden])');
    if (first) requestAnimationFrame(() => first.focus());
}

function close(dropdown, btn, returnFocus = false) {
    dropdown.hidden = true;
    btn?.setAttribute('aria-expanded', 'false');
    if (returnFocus && btn) btn.focus();
}

function closeNotifications(header) {
    const d = header.querySelector('[data-header-notifications]');
    const b = header.querySelector('[data-header-notifications-toggle]');
    if (d && !d.hidden) close(d, b);
}

function closeUserMenu(header) {
    const d = header.querySelector('[data-header-user-menu]');
    const b = header.querySelector('[data-header-user-toggle]');
    if (d && !d.hidden) close(d, b);
}
