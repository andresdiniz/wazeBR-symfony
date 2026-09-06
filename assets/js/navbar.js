/**
 * wazeBR — JavaScript da sidebar de navegação
 * (templates/partials/_navbar_menu.html.twig + estrutura em base.html.twig)
 */
(function () {
    'use strict';

    const SIDEBAR_STORAGE_KEY = 'wazebr_sidebar_collapsed';

    function initNavbar() {
        const sidebar = document.getElementById('sidebar');
        if (!sidebar) return;

        const collapseToggle = document.querySelector('[data-sidebar-toggle]');
        const mobileToggle = document.querySelector('[data-sidebar-mobile-toggle]');
        const overlay = document.querySelector('[data-sidebar-overlay]');

        // Lembra se o usuário deixou a sidebar recolhida
        if (localStorage.getItem(SIDEBAR_STORAGE_KEY) === 'true') {
            sidebar.classList.add('collapsed');
        }

        if (collapseToggle) {
            collapseToggle.addEventListener('click', () => {
                sidebar.classList.toggle('collapsed');
                localStorage.setItem(SIDEBAR_STORAGE_KEY, sidebar.classList.contains('collapsed'));
            });
        }

        // Sidebar em modo gaveta no mobile
        const closeMobile = () => {
            sidebar.classList.remove('mobile-open');
            if (overlay) overlay.classList.remove('visible');
        };

        if (mobileToggle) {
            mobileToggle.addEventListener('click', () => {
                sidebar.classList.add('mobile-open');
                if (overlay) overlay.classList.add('visible');
            });
        }

        if (overlay) {
            overlay.addEventListener('click', closeMobile);
        }

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeMobile();
        });

        // Fecha a gaveta mobile ao navegar para outra página do menu
        sidebar.querySelectorAll('.sidebar-menu-link').forEach((link) => {
            link.addEventListener('click', closeMobile);
        });
    }

    document.addEventListener('DOMContentLoaded', initNavbar);
})();
