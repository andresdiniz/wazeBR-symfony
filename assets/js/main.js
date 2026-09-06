/**
 * wazeBR — JavaScript global do layout (sidebar, flashes, utilitários)
 */
(function () {
    'use strict';

    const SIDEBAR_STORAGE_KEY = 'wazebr_sidebar_collapsed';

    function initSidebar() {
        const sidebar = document.getElementById('sidebar');
        if (!sidebar) return;

        const collapseToggle = document.querySelector('[data-sidebar-toggle]');
        const mobileToggle = document.querySelector('[data-sidebar-mobile-toggle]');
        const overlay = document.querySelector('[data-sidebar-overlay]');

        if (localStorage.getItem(SIDEBAR_STORAGE_KEY) === 'true') {
            sidebar.classList.add('collapsed');
        }

        if (collapseToggle) {
            collapseToggle.addEventListener('click', () => {
                sidebar.classList.toggle('collapsed');
                localStorage.setItem(SIDEBAR_STORAGE_KEY, sidebar.classList.contains('collapsed'));
            });
        }

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
    }

    // ---------- Flash messages: fecha manualmente ou some sozinho ----------
    function initFlashes() {
        document.querySelectorAll('.flash').forEach((flash) => {
            const closeBtn = flash.querySelector('[data-flash-close]');
            const dismiss = () => {
                flash.style.opacity = '0';
                setTimeout(() => flash.remove(), 200);
            };
            if (closeBtn) closeBtn.addEventListener('click', dismiss);
            setTimeout(dismiss, 8000);
        });
    }

    // ---------- Utilitários globais reutilizados por outras páginas ----------
    window.WazeBR = {
        toast(message, type = 'info', duration = 3000) {
            const stack = document.querySelector('.flash-stack') || (() => {
                const el = document.createElement('div');
                el.className = 'flash-stack';
                document.body.prepend(el);
                return el;
            })();

            const toast = document.createElement('div');
            toast.className = `flash flash-${type}`;
            toast.setAttribute('role', 'status');
            toast.innerHTML = `<span>${message}</span>`;
            stack.appendChild(toast);

            setTimeout(() => {
                toast.style.opacity = '0';
                setTimeout(() => toast.remove(), 200);
            }, duration);
        },

        confirm(message) {
            return Promise.resolve(window.confirm(message));
        },

        debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                clearTimeout(timeout);
                timeout = setTimeout(() => func(...args), wait);
            };
        },

        formatDate(date) {
            return new Date(date).toLocaleDateString('pt-BR', {
                day: '2-digit', month: '2-digit', year: 'numeric',
            });
        },

        formatDateTime(date) {
            return new Date(date).toLocaleString('pt-BR', {
                day: '2-digit', month: '2-digit', year: 'numeric',
                hour: '2-digit', minute: '2-digit',
            });
        },
    };

    document.addEventListener('DOMContentLoaded', () => {
        initSidebar();
        initFlashes();
    });
})();
