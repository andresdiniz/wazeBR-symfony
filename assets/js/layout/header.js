/**
 * header.js
 * Controla os dois dropdowns do header (notificações e menu do usuário)
 * e o botão de toggle do sidebar.
 *
 * Sem dependências externas — vanilla JS puro.
 * Coloque este arquivo em assets/js/header.js e inclua no base.html.twig:
 *   <script src="{{ asset('js/header.js') }}" defer></script>
 */

(function () {
    'use strict';

    // ── Referências ──────────────────────────────────────────────────────────

    /** @type {HTMLElement|null} */
    const header = document.querySelector('[data-component="header"]');
    if (!header) return;

    // Notificações
    const notifBtn      = header.querySelector('[data-action="toggle-notifications"]');
    const notifDropdown = header.querySelector('[data-notifications-dropdown]');

    // Menu do usuário
    const userBtn      = header.querySelector('[data-action="toggle-user-menu"]');
    const userDropdown = header.querySelector('[data-user-dropdown]');

    // Sidebar
    const sidebarBtn = header.querySelector('[data-action="toggle-sidebar"]');

    // Marcar como lidas
    const markReadBtn = header.querySelector('[data-action="mark-notifications-read"]');

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Abre um dropdown e atualiza aria-expanded no botão associado.
     *
     * @param {HTMLElement} dropdown
     * @param {HTMLElement} triggerBtn
     */
    function openDropdown(dropdown, triggerBtn) {
        dropdown.hidden = false;
        triggerBtn.setAttribute('aria-expanded', 'true');

        // Foca o primeiro item focável dentro do dropdown para acessibilidade
        const firstFocusable = dropdown.querySelector('a, button, [tabindex]');
        if (firstFocusable) {
            firstFocusable.focus();
        }
    }

    /**
     * Fecha um dropdown e atualiza aria-expanded no botão associado.
     *
     * @param {HTMLElement} dropdown
     * @param {HTMLElement} triggerBtn
     * @param {boolean}     [returnFocus=false] — devolve foco ao botão após fechar
     */
    function closeDropdown(dropdown, triggerBtn, returnFocus = false) {
        dropdown.hidden = true;
        triggerBtn.setAttribute('aria-expanded', 'false');
        if (returnFocus) triggerBtn.focus();
    }

    /**
     * Alterna (toggle) um dropdown.
     * Ao abrir, fecha o outro se estiver aberto.
     *
     * @param {HTMLElement} dropdown
     * @param {HTMLElement} triggerBtn
     * @param {HTMLElement} otherDropdown
     * @param {HTMLElement} otherBtn
     */
    function toggleDropdown(dropdown, triggerBtn, otherDropdown, otherBtn) {
        const isOpen = !dropdown.hidden;

        // Fecha o outro dropdown primeiro
        if (otherDropdown && !otherDropdown.hidden) {
            closeDropdown(otherDropdown, otherBtn);
        }

        if (isOpen) {
            closeDropdown(dropdown, triggerBtn);
        } else {
            openDropdown(dropdown, triggerBtn);
        }
    }

    // ── Event listeners ──────────────────────────────────────────────────────

    // Botão de notificações
    if (notifBtn && notifDropdown) {
        notifBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            toggleDropdown(notifDropdown, notifBtn, userDropdown, userBtn);
        });
    }

    // Botão do menu do usuário
    if (userBtn && userDropdown) {
        userBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            toggleDropdown(userDropdown, userBtn, notifDropdown, notifBtn);
        });
    }

    // Marcar notificações como lidas
    if (markReadBtn && notifDropdown) {
        markReadBtn.addEventListener('click', function () {
            notifDropdown.querySelectorAll('.is-unread').forEach(function (item) {
                item.classList.remove('is-unread');
            });

            // Zera o badge
            const badge = header.querySelector('.header-notification-badge');
            if (badge) badge.hidden = true;

            // Mostra mensagem de vazio
            const emptyMsg = notifDropdown.querySelector('[data-notifications-empty]');
            if (emptyMsg) emptyMsg.hidden = false;
        });
    }

    // Toggle sidebar
    if (sidebarBtn) {
        sidebarBtn.addEventListener('click', function () {
            const sidebarId = sidebarBtn.getAttribute('aria-controls');
            const sidebar   = sidebarId ? document.getElementById(sidebarId) : null;

            const isExpanded = sidebarBtn.getAttribute('aria-expanded') === 'true';
            sidebarBtn.setAttribute('aria-expanded', String(!isExpanded));

            if (sidebar) {
                sidebar.classList.toggle('is-open', !isExpanded);
            } else {
                // Fallback: toggle numa classe no <body>
                document.body.classList.toggle('sidebar-open', !isExpanded);
            }
        });
    }

    // ── Fechar ao clicar fora ────────────────────────────────────────────────

    document.addEventListener('click', function (e) {
        // Notificações
        if (notifDropdown && !notifDropdown.hidden) {
            const notifWrapper = header.querySelector('[data-notifications-menu]');
            if (notifWrapper && !notifWrapper.contains(e.target)) {
                closeDropdown(notifDropdown, notifBtn);
            }
        }

        // Menu do usuário
        if (userDropdown && !userDropdown.hidden) {
            const userWrapper = header.querySelector('[data-user-menu]');
            if (userWrapper && !userWrapper.contains(e.target)) {
                closeDropdown(userDropdown, userBtn);
            }
        }
    });

    // ── Fechar com Escape ────────────────────────────────────────────────────

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;

        if (notifDropdown && !notifDropdown.hidden) {
            closeDropdown(notifDropdown, notifBtn, true);
        }

        if (userDropdown && !userDropdown.hidden) {
            closeDropdown(userDropdown, userBtn, true);
        }
    });

    // ── Navegação por teclado dentro dos dropdowns (↑ ↓ Tab) ────────────────

    [notifDropdown, userDropdown].forEach(function (dropdown) {
        if (!dropdown) return;

        dropdown.addEventListener('keydown', function (e) {
            const focusable = Array.from(
                dropdown.querySelectorAll('a, button, [tabindex]')
            ).filter(function (el) { return !el.hidden && el.tabIndex !== -1; });

            if (focusable.length === 0) return;

            const idx = focusable.indexOf(document.activeElement);

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                focusable[(idx + 1) % focusable.length].focus();
            }

            if (e.key === 'ArrowUp') {
                e.preventDefault();
                focusable[(idx - 1 + focusable.length) % focusable.length].focus();
            }
        });
    });

})();
