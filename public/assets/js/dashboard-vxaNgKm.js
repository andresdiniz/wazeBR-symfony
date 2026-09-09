/**
 * ==========================================================================
 * wazeBR — Dashboard autenticado
 * Interações exclusivas da área administrativa.
 * Não depende nem altera o layout público.
 * ==========================================================================
 */

(function () {
    'use strict';

    const SELECTORS = {
        sidebar: '#dashboard-sidebar',
        sidebarOpen: '[data-dashboard-sidebar-toggle]',
        sidebarClose: '[data-dashboard-sidebar-close]',
        notificationToggle: '[data-dashboard-notifications-toggle]',
        notificationPanel: '[data-dashboard-notifications-panel]',
        notificationRead: '[data-dashboard-notifications-read]',
        accountToggle: '[data-dashboard-account-toggle]',
        accountPanel: '[data-dashboard-account-panel]',
        searchInput: '.dashboard-search-input',
        periodSelect: '#dashboard-period'
    };

    document.addEventListener('DOMContentLoaded', initDashboard);

    function initDashboard() {
        const sidebar = document.querySelector(SELECTORS.sidebar);
        const sidebarOpenButton = document.querySelector(SELECTORS.sidebarOpen);
        const sidebarCloseButton = document.querySelector(SELECTORS.sidebarClose);

        const notificationToggle = document.querySelector(SELECTORS.notificationToggle);
        const notificationPanel = document.querySelector(SELECTORS.notificationPanel);
        const notificationReadButton = document.querySelector(SELECTORS.notificationRead);

        const accountToggle = document.querySelector(SELECTORS.accountToggle);
        const accountPanel = document.querySelector(SELECTORS.accountPanel);

        const searchInput = document.querySelector(SELECTORS.searchInput);
        const periodSelect = document.querySelector(SELECTORS.periodSelect);

        initSidebar(sidebar, sidebarOpenButton, sidebarCloseButton);
        initDropdowns({
            notificationToggle,
            notificationPanel,
            notificationReadButton,
            accountToggle,
            accountPanel
        });
        initSearchShortcut(searchInput);
        initPeriodFilter(periodSelect);
        initTaskCheckboxes();
        initEntranceAnimation();
    }

    /**
     * Sidebar: drawer em telas menores e fechamento por Escape/clique externo.
     */
    function initSidebar(sidebar, openButton, closeButton) {
        if (!sidebar) {
            return;
        }

        const openSidebar = function () {
            sidebar.classList.add('is-open');

            if (openButton) {
                openButton.setAttribute('aria-expanded', 'true');
            }

            document.body.classList.add('dashboard-menu-open');
        };

        const closeSidebar = function () {
            sidebar.classList.remove('is-open');

            if (openButton) {
                openButton.setAttribute('aria-expanded', 'false');
            }

            document.body.classList.remove('dashboard-menu-open');
        };

        if (openButton) {
            openButton.addEventListener('click', function () {
                if (sidebar.classList.contains('is-open')) {
                    closeSidebar();
                    return;
                }

                openSidebar();
            });
        }

        if (closeButton) {
            closeButton.addEventListener('click', closeSidebar);
        }

        document.addEventListener('click', function (event) {
            if (window.innerWidth > 960 || !sidebar.classList.contains('is-open')) {
                return;
            }

            const clickedInSidebar = sidebar.contains(event.target);
            const clickedOpenButton = openButton && openButton.contains(event.target);

            if (!clickedInSidebar && !clickedOpenButton) {
                closeSidebar();
            }
        });

        window.addEventListener(
            'resize',
            debounce(function () {
                if (window.innerWidth > 960) {
                    closeSidebar();
                }
            }, 150)
        );

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && sidebar.classList.contains('is-open')) {
                closeSidebar();

                if (openButton) {
                    openButton.focus();
                }
            }
        });
    }

    /**
     * Painéis da conta e de notificações.
     */
    function initDropdowns(elements) {
        const {
            notificationToggle,
            notificationPanel,
            notificationReadButton,
            accountToggle,
            accountPanel
        } = elements;

        const closePanel = function (toggle, panel) {
            if (!panel) {
                return;
            }

            panel.hidden = true;

            if (toggle) {
                toggle.setAttribute('aria-expanded', 'false');
            }
        };

        const openPanel = function (toggle, panel) {
            if (!panel) {
                return;
            }

            panel.hidden = false;

            if (toggle) {
                toggle.setAttribute('aria-expanded', 'true');
            }
        };

        const closeAllPanels = function () {
            closePanel(notificationToggle, notificationPanel);
            closePanel(accountToggle, accountPanel);
        };

        if (notificationToggle && notificationPanel) {
            notificationToggle.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();

                const isOpen = !notificationPanel.hidden;
                closeAllPanels();

                if (!isOpen) {
                    openPanel(notificationToggle, notificationPanel);
                }
            });
        }

        if (accountToggle && accountPanel) {
            accountToggle.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();

                const isOpen = !accountPanel.hidden;
                closeAllPanels();

                if (!isOpen) {
                    openPanel(accountToggle, accountPanel);
                }
            });
        }

        if (notificationReadButton) {
            notificationReadButton.addEventListener('click', function () {
                const badge = document.querySelector('.dashboard-icon-badge');
                const count = notificationReadButton
                    .closest('.dashboard-notification-panel')
                    ?.querySelector('.dashboard-notification-panel-header span');

                if (badge) {
                    badge.remove();
                }

                if (count) {
                    count.textContent = 'Tudo em dia';
                }

                notificationReadButton.disabled = true;
                notificationReadButton.textContent = 'Tudo lido';

                notify('Notificações marcadas como lidas.', 'success');
            });
        }

        document.addEventListener('click', function (event) {
            const clickedNotificationArea =
                notificationToggle &&
                notificationPanel &&
                (notificationToggle.contains(event.target) || notificationPanel.contains(event.target));

            const clickedAccountArea =
                accountToggle &&
                accountPanel &&
                (accountToggle.contains(event.target) || accountPanel.contains(event.target));

            if (!clickedNotificationArea && !clickedAccountArea) {
                closeAllPanels();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') {
                return;
            }

            const notificationWasOpen = notificationPanel && !notificationPanel.hidden;
            const accountWasOpen = accountPanel && !accountPanel.hidden;

            closeAllPanels();

            if (notificationWasOpen && notificationToggle) {
                notificationToggle.focus();
            }

            if (accountWasOpen && accountToggle) {
                accountToggle.focus();
            }
        });
    }

    /**
     * Atalho Ctrl+K / Cmd+K para a busca do painel.
     */
    function initSearchShortcut(searchInput) {
        if (!searchInput) {
            return;
        }

        document.addEventListener('keydown', function (event) {
            const isShortcut = (event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k';

            if (!isShortcut) {
                return;
            }

            event.preventDefault();
            searchInput.focus();
            searchInput.select();
        });

        searchInput.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter') {
                return;
            }

            const query = searchInput.value.trim();

            if (!query) {
                return;
            }

            event.preventDefault();

            /*
             * Ponto de integração para pesquisa real:
             * window.location.assign('/dashboard/search?q=' + encodeURIComponent(query));
             */
            notify(`Busca por "${query}" ainda não está integrada.`, 'info');
        });
    }

    /**
     * Mantém o período selecionado ao recarregar a página.
     * A integração com o controller pode usar ?period=...
     */
    function initPeriodFilter(periodSelect) {
        if (!periodSelect) {
            return;
        }

        periodSelect.addEventListener('change', function () {
            const value = periodSelect.value;

            if (!value) {
                return;
            }

            const url = new URL(window.location.href);
            url.searchParams.set('period', value);

            window.location.assign(url.toString());
        });
    }

    /**
     * Compatibilidade para listas de tarefas existentes.
     */
    function initTaskCheckboxes() {
        const checkboxes = document.querySelectorAll(
            '.task-checkbox input[type="checkbox"]'
        );

        checkboxes.forEach(function (checkbox) {
            checkbox.addEventListener('change', function () {
                const item = checkbox.closest('.task-item');

                if (!item) {
                    return;
                }

                item.classList.toggle('completed', checkbox.checked);
            });
        });
    }

    /**
     * Animação leve e acessível para cards, respeitando reduced motion.
     */
    function initEntranceAnimation() {
        const reduceMotion = window.matchMedia(
            '(prefers-reduced-motion: reduce)'
        ).matches;

        if (reduceMotion) {
            return;
        }

        const elements = document.querySelectorAll(
            '.stats-grid .stat-card, .dashboard-grid .card, .partner-summary-card'
        );

        elements.forEach(function (element, index) {
            element.style.opacity = '0';
            element.style.transform = 'translateY(10px)';
            element.style.transition = 'opacity 220ms ease, transform 220ms ease';

            window.setTimeout(function () {
                element.style.opacity = '1';
                element.style.transform = 'translateY(0)';
            }, 45 * index);
        });
    }

    /**
     * Usa o toast global quando existir; caso contrário, mantém o dashboard
     * funcional sem exigir dependência do layout público.
     */
    function notify(message, type) {
        if (typeof window.showToast === 'function') {
            window.showToast({
                message: message,
                type: type || 'info'
            });

            return;
        }

        console.info(`[dashboard:${type || 'info'}] ${message}`);
    }

    function debounce(callback, delay) {
        let timerId;

        return function () {
            const context = this;
            const args = arguments;

            window.clearTimeout(timerId);

            timerId = window.setTimeout(function () {
                callback.apply(context, args);
            }, delay);
        };
    }
})();
