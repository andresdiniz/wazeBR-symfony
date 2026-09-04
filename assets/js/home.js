// home.js — comportamento da landing page (WazeBR Monitor)

(() => {
    'use strict';

    // Revela elementos ao entrar na viewport
    const initReveal = () => {
        const els = Array.prototype.slice.call(document.querySelectorAll('.reveal'));
        if (!els.length) return;

        if (!('IntersectionObserver' in window)) {
            els.forEach((e) => e.classList.add('visible'));
            return;
        }

        const observer = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('visible');
                        observer.unobserve(entry.target);
                    }
                });
            },
            { threshold: 0.12 }
        );

        els.forEach((e) => observer.observe(e));
    };

    // Mantém apenas um <details> do FAQ aberto por vez
    const initFaq = () => {
        const items = document.querySelectorAll('.faq-item');
        items.forEach((item) => {
            item.addEventListener('toggle', () => {
                if (item.open) {
                    items.forEach((other) => {
                        if (other !== item) other.open = false;
                    });
                }
            });
        });
    };

    // Sombra sutil na topbar ao rolar
    const initTopbarShadow = () => {
        const topbar = document.querySelector('.topbar');
        if (!topbar) return;
        const onScroll = () => {
            topbar.classList.toggle('scrolled', window.scrollY > 8);
        };
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
    };

    document.addEventListener('DOMContentLoaded', () => {
        initReveal();
        initFaq();
        initTopbarShadow();
    });
})();
