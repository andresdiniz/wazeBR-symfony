/**
 * wazeBR — JavaScript global do layout (flashes, utilitários)
 * A sidebar/navegação tem JS próprio em navbar.js.
 */
(function () {
    'use strict';

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

    document.addEventListener('DOMContentLoaded', initFlashes);
})();
