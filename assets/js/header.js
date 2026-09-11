/**
 * Header interactions
 *
 * Responsabilidades:
 * - scroll effect (.header-scrolled)
 * - search focus highlight
 * - user dropdown via .is-open (click + fechar ao clicar fora)
 * - notification badge dismiss
 */

(function () {
    'use strict';

    // ── Scroll effect ────────────────────────────────────────────
    const header = document.getElementById('appHeader');

    if (header) {
        const onScroll = () => {
            header.classList.toggle('header-scrolled', window.scrollY > 20);
        };
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }

    // ── Search focus ─────────────────────────────────────────────
    const searchInput = document.querySelector('.search-input');

    if (searchInput) {
        const box = searchInput.closest('.search-box');

        searchInput.addEventListener('focus', () => box?.classList.add('search-focused'));
        searchInput.addEventListener('blur',  () => box?.classList.remove('search-focused'));

        searchInput.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter') return;
            const query = searchInput.value.trim();
            if (query) {
                // Substituir pela rota de busca quando implementada
                console.log('Busca:', query);
            }
        });
    }

    // ── User dropdown ─────────────────────────────────────────────
    const userMenu = document.querySelector('.user-menu');

    if (userMenu) {
        userMenu.addEventListener('click', (e) => {
            // Não fecha ao clicar em links dentro do dropdown
            if (e.target.closest('.dropdown-item')) return;
            userMenu.classList.toggle('is-open');
        });

        document.addEventListener('click', (e) => {
            if (!userMenu.contains(e.target)) {
                userMenu.classList.remove('is-open');
            }
        });

        // Fecha ao pressionar Escape
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                userMenu.classList.remove('is-open');
            }
        });
    }

    // ── Notification badge ────────────────────────────────────────
    const notificationLink = document.querySelector('.notification-link');

    if (notificationLink) {
        notificationLink.addEventListener('click', (e) => {
            e.preventDefault();

            const badge = notificationLink.querySelector('.notification-badge');
            if (!badge) return;

            badge.style.transition = 'transform 0.2s ease, opacity 0.2s ease';
            badge.style.transform  = 'scale(0)';
            badge.style.opacity    = '0';

            setTimeout(() => badge.remove(), 200);

            // Substituir pelo handler real de notificações quando implementado
        });
    }

})();
