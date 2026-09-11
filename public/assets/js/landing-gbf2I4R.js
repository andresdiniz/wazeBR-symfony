/**
 * Landing — WazeBR
 * Inicializado via <script defer> no template.
 * Sem dependências externas.
 */

(function () {
    'use strict';

    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // ── Init ─────────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', init);

    function init() {
        const shell = document.querySelector('.landing-shell');
        if (!shell) return;

        initHamburger();
        initReveal();
        initCounters();
        initHeaderScroll();

        if (!reduced) {
            initParallaxOrbs();
            initCardParallax();
        }
    }

    // ── Hamburger ────────────────────────────────────────────────
    function initHamburger() {
        const btn    = document.querySelector('[data-landing-menu]');
        const header = document.querySelector('.landing-header');
        if (!btn || !header) return;

        btn.addEventListener('click', () => {
            const open = header.classList.toggle('is-open');
            btn.classList.toggle('is-open', open);
            btn.setAttribute('aria-expanded', String(open));
        });

        // Fecha ao clicar em link da nav mobile
        header.querySelectorAll('.landing-nav-link').forEach(link => {
            link.addEventListener('click', () => {
                header.classList.remove('is-open');
                btn.classList.remove('is-open');
                btn.setAttribute('aria-expanded', 'false');
            });
        });
    }

    // ── Header scroll shadow ─────────────────────────────────────
    function initHeaderScroll() {
        const header = document.querySelector('.landing-header');
        if (!header) return;

        const update = () => {
            header.style.boxShadow = window.scrollY > 10
                ? '0 1px 20px rgba(15,23,42,.08)'
                : '';
        };

        window.addEventListener('scroll', update, { passive: true });
        update();
    }

    // ── Reveal por scroll ────────────────────────────────────────
    function initReveal() {
        const items = document.querySelectorAll('[data-reveal]');
        if (!items.length) return;

        if (!('IntersectionObserver' in window) || reduced) {
            items.forEach(el => el.classList.add('is-visible'));
            return;
        }

        const observer = new IntersectionObserver((entries, obs) => {
            entries.forEach(entry => {
                if (!entry.isIntersecting) return;

                const delay = parseInt(entry.target.dataset.revealDelay ?? '0', 10);

                setTimeout(() => {
                    entry.target.classList.add('is-visible');
                }, delay);

                obs.unobserve(entry.target);
            });
        }, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });

        items.forEach(el => observer.observe(el));
    }

    // ── Contadores animados ──────────────────────────────────────
    function initCounters() {
        const counters = document.querySelectorAll('[data-count]');
        if (!counters.length) return;

        if (reduced) return; // Já renderizado com valor final

        const animate = (el) => {
            const target   = Number(el.dataset.count);
            const duration = 1200;
            const start    = performance.now();

            const step = (now) => {
                const progress = Math.min((now - start) / duration, 1);
                const eased    = 1 - Math.pow(1 - progress, 3);
                el.textContent = String(Math.round(target * eased));
                if (progress < 1) requestAnimationFrame(step);
            };

            requestAnimationFrame(step);
        };

        if (!('IntersectionObserver' in window)) {
            counters.forEach(animate);
            return;
        }

        const observer = new IntersectionObserver((entries, obs) => {
            entries.forEach(entry => {
                if (!entry.isIntersecting) return;
                animate(entry.target);
                obs.unobserve(entry.target);
            });
        }, { threshold: 0.8 });

        counters.forEach(el => observer.observe(el));
    }

    // ── Parallax nos orbs do hero ────────────────────────────────
    function initParallaxOrbs() {
        const orbs = document.querySelectorAll('.hero-orb');
        if (!orbs.length) return;

        const factors = [0.025, -0.015, 0.02];
        let frame;

        document.addEventListener('mousemove', (e) => {
            cancelAnimationFrame(frame);
            frame = requestAnimationFrame(() => {
                const cx = window.innerWidth  / 2;
                const cy = window.innerHeight / 2;
                const dx = e.clientX - cx;
                const dy = e.clientY - cy;

                orbs.forEach((orb, i) => {
                    const f = factors[i] ?? 0.01;
                    orb.style.transform = `translate3d(${dx * f}px, ${dy * f}px, 0)`;
                });
            });
        });
    }

    // ── Parallax suave no card visual ───────────────────────────
    function initCardParallax() {
        const visual = document.querySelector('.landing-hero-visual');
        if (!visual) return;

        const mq = window.matchMedia('(min-width: 901px)');
        if (!mq.matches) return;

        let frame;

        visual.addEventListener('pointermove', (e) => {
            cancelAnimationFrame(frame);
            frame = requestAnimationFrame(() => {
                const rect = visual.getBoundingClientRect();
                const x = ((e.clientX - rect.left) / rect.width  - .5) * 10;
                const y = ((e.clientY - rect.top)  / rect.height - .5) * 7;
                visual.style.transform = `translate3d(${x}px, ${y}px, 0)`;
            });
        });

        visual.addEventListener('pointerleave', () => {
            cancelAnimationFrame(frame);
            visual.style.transform = '';
            visual.style.transition = 'transform .5s ease';
            setTimeout(() => { visual.style.transition = ''; }, 500);
        });
    }

})();
