/**
 * wazeBR - Dashboard JavaScript
 *
 * Este arquivo cobre DOIS layouts diferentes que compartilham o mesmo
 * dashboard.css:
 *
 *  1) LAYOUT NOVO — templates/layouts/dashboard.html.twig
 *     (sidebar/topbar com id="dashboard-sidebar" e atributos data-dashboard-*)
 *
 *  2) LAYOUT LEGADO — templates/admin/dashboard_base.html.twig
 *     (sidebar/topbar com id="sidebar", id="sidebar-toggle", etc.)
 *
 * Cada init* já verifica se os elementos que precisa existem antes de
 * fazer qualquer coisa, então os dois blocos convivem sem conflito: só o
 * bloco correspondente ao layout realmente renderizado na página roda.
 */

document.addEventListener('DOMContentLoaded', function () {
    // Layout novo (templates/layouts/dashboard.html.twig)
    initNewDashboardSidebar();
    initNewDashboardPanels();

    // Layout legado (templates/admin/dashboard_base.html.twig)
    initLegacySidebar();
    initLegacyMobileMenu();
    initLegacyNotifications();
    initLegacySearch();
    initLegacyTaskCheckboxes();
    initLegacyQuickActions();
    initLegacyAnimations();
});

/* ========================================================================
   LAYOUT NOVO — sidebar (drawer no mobile)
   ======================================================================== */

function initNewDashboardSidebar() {
    const wrapper = document.querySelector('.dashboard-wrapper');
    const sidebar = document.getElementById('dashboard-sidebar');
    const openBtn = document.querySelector('[data-dashboard-sidebar-toggle]');
    const closeBtn = document.querySelector('[data-dashboard-sidebar-close]');

    if (!wrapper || !sidebar) {
        return;
    }

    function openSidebar() {
        sidebar.classList.add('is-open');
        wrapper.classList.add('has-sidebar-open');
        if (openBtn) openBtn.setAttribute('aria-expanded', 'true');
    }

    function closeSidebar() {
        sidebar.classList.remove('is-open');
        wrapper.classList.remove('has-sidebar-open');
        if (openBtn) openBtn.setAttribute('aria-expanded', 'false');
    }

    if (openBtn) {
        openBtn.addEventListener('click', () => {
            const isOpen = sidebar.classList.contains('is-open');
            isOpen ? closeSidebar() : openSidebar();
        });
    }

    if (closeBtn) {
        closeBtn.addEventListener('click', closeSidebar);
    }

    // Fecha ao clicar no backdrop (fora da sidebar, dentro do wrapper)
    wrapper.addEventListener('click', (event) => {
        const isMobileOpen = sidebar.classList.contains('is-open');
        if (!isMobileOpen) return;

        const clickedInsideSidebar = sidebar.contains(event.target);
        const clickedToggle = openBtn && openBtn.contains(event.target);

        if (!clickedInsideSidebar && !clickedToggle) {
            closeSidebar();
        }
    });

    // Fecha com Esc
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && sidebar.classList.contains('is-open')) {
            closeSidebar();
        }
    });

    // Ao voltar para desktop, garante que o estado "aberto no mobile" não fique preso
    window.addEventListener('resize', debounceLocal(() => {
        if (window.innerWidth > 992) {
            closeSidebar();
        }
    }, 200));
}

/* ========================================================================
   LAYOUT NOVO — painéis do topbar (notificações e menu de conta)
   ======================================================================== */

function initNewDashboardPanels() {
    const panels = [
        {
            toggle: document.querySelector('[data-dashboard-notifications-toggle]'),
            panel: document.querySelector('[data-dashboard-notifications-panel]'),
        },
        {
            toggle: document.querySelector('[data-dashboard-account-toggle]'),
            panel: document.querySelector('[data-dashboard-account-panel]'),
        },
    ].filter((entry) => entry.toggle && entry.panel);

    if (!panels.length) {
        return;
    }

    function closeAll(except) {
        panels.forEach(({ toggle, panel }) => {
            if (panel === except) return;
            panel.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
        });
    }

    panels.forEach(({ toggle, panel }) => {
        toggle.addEventListener('click', (event) => {
            event.stopPropagation();
            const isOpen = !panel.hidden;

            closeAll(isOpen ? null : panel);

            panel.hidden = isOpen;
            toggle.setAttribute('aria-expanded', String(!isOpen));
        });
    });

    // Fecha ao clicar fora de qualquer painel/toggle
    document.addEventListener('click', (event) => {
        const clickedInsideAny = panels.some(
            ({ toggle, panel }) => toggle.contains(event.target) || panel.contains(event.target)
        );

        if (!clickedInsideAny) {
            closeAll(null);
        }
    });

    // Fecha com Esc
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeAll(null);
        }
    });

    // Ação "marcar como lidas" no painel de notificações (se existir)
    const markReadBtn = document.querySelector('[data-dashboard-notifications-read]');
    const badge = document.querySelector('.dashboard-icon-badge');

    if (markReadBtn) {
        markReadBtn.addEventListener('click', () => {
            // TODO: chamar o backend para marcar as notificações como lidas
            if (badge) {
                badge.remove();
            }
        });
    }
}

/* ========================================================================
   LAYOUT LEGADO — templates/admin/dashboard_base.html.twig
   ======================================================================== */

function initLegacySidebar() {
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebar-toggle');

    if (!sidebar || !sidebarToggle) return;

    sidebarToggle.addEventListener('click', () => {
        sidebar.classList.toggle('collapsed');
        storageSet('sidebarCollapsed', sidebar.classList.contains('collapsed'));
    });

    if (storageGet('sidebarCollapsed') === true) {
        sidebar.classList.add('collapsed');
    }
}

function initLegacyMobileMenu() {
    const sidebar = document.getElementById('sidebar');
    const mobileMenuToggle = document.getElementById('mobile-menu-toggle');

    if (!sidebar || !mobileMenuToggle) return;

    mobileMenuToggle.addEventListener('click', () => {
        sidebar.classList.toggle('active');
    });

    document.addEventListener('click', (event) => {
        if (window.innerWidth > 992) return;
        if (!sidebar.contains(event.target) && !mobileMenuToggle.contains(event.target)) {
            sidebar.classList.remove('active');
        }
    });

    window.addEventListener('resize', debounceLocal(() => {
        if (window.innerWidth > 992) {
            sidebar.classList.remove('active');
        }
    }, 200));
}

function initLegacyNotifications() {
    const notificationBtn = document.querySelector('.notification-btn');
    if (!notificationBtn) return;

    notificationBtn.addEventListener('click', () => {
        // TODO: implementar o dropdown de notificações do layout legado
    });
}

function initLegacySearch() {
    const searchInput = document.querySelector('.search-input');
    if (!searchInput) return;

    const runSearch = debounceLocal((value) => performLegacySearch(value), 300);

    searchInput.addEventListener('input', (event) => runSearch(event.target.value));
    searchInput.addEventListener('keypress', (event) => {
        if (event.key === 'Enter') {
            performLegacySearch(event.target.value);
        }
    });
}

function performLegacySearch(query) {
    if (!query.trim()) return;
    // TODO: implementar a busca do layout legado
}

function initLegacyTaskCheckboxes() {
    document.querySelectorAll('.task-checkbox input[type="checkbox"]').forEach((checkbox) => {
        checkbox.addEventListener('change', (event) => {
            const taskItem = event.target.closest('.task-item');
            if (!taskItem) return;

            taskItem.classList.toggle('completed', event.target.checked);
            // TODO: persistir o status da tarefa no backend
        });
    });
}

function initLegacyQuickActions() {
    document.querySelectorAll('.quick-action-btn').forEach((btn) => {
        btn.addEventListener('click', (event) => {
            event.preventDefault();
            // TODO: implementar a ação rápida correspondente
        });
    });
}

function initLegacyAnimations() {
    const groups = [
        { selector: '.stat-card', axis: 'y', baseDelay: 0 },
        { selector: '.card', axis: 'y', baseDelay: 300 },
        { selector: '.activity-item, .task-item, .route-item, .alert-item, .partner-item', axis: 'x', baseDelay: 500 },
    ];

    groups.forEach(({ selector, axis, baseDelay }) => {
        document.querySelectorAll(selector).forEach((el, index) => {
            const offset = axis === 'x' ? 'translateX(-20px)' : 'translateY(20px)';

            el.style.opacity = '0';
            el.style.transform = offset;

            setTimeout(() => {
                el.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                el.style.opacity = '1';
                el.style.transform = axis === 'x' ? 'translateX(0)' : 'translateY(0)';
            }, baseDelay + index * (axis === 'x' ? 50 : 100));
        });
    });
}

/* ========================================================================
   Utilitários locais
   Reaproveita window.debounce/window.storage de assets/js/global.js quando
   disponíveis (base.html.twig sempre carrega global.js antes deste
   arquivo); caso contrário usa um fallback simples.
   ======================================================================== */

function debounceLocal(fn, wait) {
    if (typeof window.debounce === 'function') {
        return window.debounce(fn, wait);
    }

    let timeout;
    return function (...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => fn.apply(this, args), wait);
    };
}

function storageGet(key) {
    if (window.storage && typeof window.storage.get === 'function') {
        return window.storage.get(key);
    }

    try {
        const raw = localStorage.getItem(key);
        return raw ? JSON.parse(raw) : null;
    } catch (error) {
        return null;
    }
}

function storageSet(key, value) {
    if (window.storage && typeof window.storage.set === 'function') {
        window.storage.set(key, value);
        return;
    }

    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch (error) {
        // localStorage indisponível (modo privado, quota excedida etc.) — ignora
    }
}
