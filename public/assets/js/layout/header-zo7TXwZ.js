/**
 * layout/header.js
 *
 * Inicializa todos os comportamentos do header:
 *   - Dropdowns de notificações e menu do usuário
 *   - Toggle do sidebar (mobile)
 *   - Marcar notificações como lidas
 *   - Atalho de teclado ⌘K / Ctrl+K para o search
 *   - Navegação por teclado (↑ ↓ Escape) dentro dos dropdowns
 *
 * Exporta initHeader(doc) — chamado pelo app-init.js.
 * Todas as referências usam os data-* e classes do header.html.twig real.
 */

export function initHeader(doc = globalThis.document) {
    const header = doc.querySelector('[data-component="header"]');
    if (!header || header.dataset.wazebrInitialized === 'true') return;
    header.dataset.wazebrInitialized = 'true';

    initNotificationsDropdown(header, doc);
    initUserMenuDropdown(header, doc);
    initSidebarToggle(header, doc);
    initSearchShortcut(header, doc);
    initGlobalClose(header, doc);
}

// ── Notificações ──────────────────────────────────────────────────────────────

function initNotificationsDropdown(header, doc) {
    const btn      = header.querySelector('[data-action="toggle-notifications"]');
    const dropdown = header.querySelector('[data-notifications-dropdown]');
    const readBtn  = header.querySelector('[data-action="mark-notifications-read"]');
    const badge    = header.querySelector('.header-notification-badge');

    if (!btn || !dropdown) return;

    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const isOpen = !dropdown.hidden;

        // Fecha o menu do usuário se estiver aberto
        closeUserMenu(header);

        isOpen ? closeDropdown(dropdown, btn) : openDropdown(dropdown, btn);
    });

    // Marcar como lidas
    readBtn?.addEventListener('click', () => {
        header.querySelectorAll('.header-notification-item.is-unread').forEach((item) => {
            item.classList.remove('is-unread');
        });

        if (badge) {
            badge.hidden = true;
            badge.textContent = '0';
        }

        const emptyMsg = dropdown.querySelector('[data-notifications-empty]');
        if (emptyMsg) emptyMsg.hidden = false;
    });

    initDropdownKeyboard(dropdown, btn);
}

// ── Menu do usuário ───────────────────────────────────────────────────────────

function initUserMenuDropdown(header, doc) {
    const btn      = header.querySelector('[data-action="toggle-user-menu"]');
    const dropdown = header.querySelector('[data-user-dropdown]');

    if (!btn || !dropdown) return;

    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const isOpen = !dropdown.hidden;

        // Fecha notificações se estiver aberto
        closeNotifications(header);

        isOpen ? closeDropdown(dropdown, btn) : openDropdown(dropdown, btn);
    });

    initDropdownKeyboard(dropdown, btn);
}

// ── Sidebar toggle ────────────────────────────────────────────────────────────

function initSidebarToggle(header, doc) {
    const btn = header.querySelector('[data-action="toggle-sidebar"]');
    if (!btn) return;

    btn.addEventListener('click', () => {
        const sidebarId = btn.getAttribute('aria-controls');
        const sidebar   = sidebarId ? doc.getElementById(sidebarId) : null;
        const isOpen    = btn.getAttribute('aria-expanded') === 'true';

        btn.setAttribute('aria-expanded', String(!isOpen));

        if (sidebar) {
            sidebar.classList.toggle('is-open', !isOpen);
        }

        // Overlay
        const overlay = doc.querySelector('[data-sidebar-overlay]');
        if (overlay) {
            overlay.hidden = isOpen;
        }

        doc.body.classList.toggle('sidebar-open', !isOpen);
    });

    // Fechar ao clicar no overlay
    const overlay = doc.querySelector('[data-sidebar-overlay]');
    overlay?.addEventListener('click', () => {
        const sidebar = doc.querySelector('[data-sidebar]');
        sidebar?.classList.remove('is-open');
        doc.body.classList.remove('sidebar-open');
        btn.setAttribute('aria-expanded', 'false');
        overlay.hidden = true;
    });
}

// ── Atalho ⌘K / Ctrl+K ────────────────────────────────────────────────────────

function initSearchShortcut(header, doc) {
    const input = header.querySelector('[data-header-search]');
    if (!input) return;

    doc.addEventListener('keydown', (e) => {
        if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
            e.preventDefault();
            input.focus();
            input.select();
        }
    });
}

// ── Fechar ao clicar fora ─────────────────────────────────────────────────────

function initGlobalClose(header, doc) {
    doc.addEventListener('click', (e) => {
        // Notificações
        const notifWrapper = header.querySelector('[data-notifications-menu]');
        const notifDrop    = header.querySelector('[data-notifications-dropdown]');
        const notifBtn     = header.querySelector('[data-action="toggle-notifications"]');

        if (notifDrop && !notifDrop.hidden && notifWrapper && !notifWrapper.contains(e.target)) {
            closeDropdown(notifDrop, notifBtn);
        }

        // Menu do usuário
        const userWrapper = header.querySelector('[data-user-menu]');
        const userDrop    = header.querySelector('[data-user-dropdown]');
        const userBtn     = header.querySelector('[data-action="toggle-user-menu"]');

        if (userDrop && !userDrop.hidden && userWrapper && !userWrapper.contains(e.target)) {
            closeDropdown(userDrop, userBtn);
        }
    });

    // Escape fecha qualquer dropdown aberto
    doc.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;

        const notifDrop = header.querySelector('[data-notifications-dropdown]');
        const notifBtn  = header.querySelector('[data-action="toggle-notifications"]');
        if (notifDrop && !notifDrop.hidden) {
            closeDropdown(notifDrop, notifBtn, true);
            return;
        }

        const userDrop = header.querySelector('[data-user-dropdown]');
        const userBtn  = header.querySelector('[data-action="toggle-user-menu"]');
        if (userDrop && !userDrop.hidden) {
            closeDropdown(userDrop, userBtn, true);
        }
    });
}

// ── Navegação por teclado dentro de dropdown ──────────────────────────────────

function initDropdownKeyboard(dropdown, triggerBtn) {
    dropdown.addEventListener('keydown', (e) => {
        const items = Array.from(
            dropdown.querySelectorAll('a[role="menuitem"], button:not([hidden])')
        ).filter((el) => !el.hidden && el.tabIndex !== -1);

        if (items.length === 0) return;

        const idx = items.indexOf(doc.activeElement);

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            items[(idx + 1) % items.length].focus();
        }

        if (e.key === 'ArrowUp') {
            e.preventDefault();
            items[(idx - 1 + items.length) % items.length].focus();
        }
    });
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function openDropdown(dropdown, triggerBtn) {
    dropdown.hidden = false;
    triggerBtn?.setAttribute('aria-expanded', 'true');

    // Foca o primeiro item interativo
    const first = dropdown.querySelector('a, button:not([hidden])');
    if (first) requestAnimationFrame(() => first.focus());
}

function closeDropdown(dropdown, triggerBtn, returnFocus = false) {
    dropdown.hidden = true;
    triggerBtn?.setAttribute('aria-expanded', 'false');
    if (returnFocus && triggerBtn) triggerBtn.focus();
}

function closeNotifications(header) {
    const drop = header.querySelector('[data-notifications-dropdown]');
    const btn  = header.querySelector('[data-action="toggle-notifications"]');
    if (drop && !drop.hidden) closeDropdown(drop, btn);
}

function closeUserMenu(header) {
    const drop = header.querySelector('[data-user-dropdown]');
    const btn  = header.querySelector('[data-action="toggle-user-menu"]');
    if (drop && !drop.hidden) closeDropdown(drop, btn);
}
