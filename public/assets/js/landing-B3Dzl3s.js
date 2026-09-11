/**
 * Landing — WazeBR
 */
(function () {
    'use strict';

    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        if (!document.querySelector('.landing-shell')) return;
        initHamburger();
        initHeaderScroll();
        initReveal();
        initCounters();
        if (!reduced) {
            initParallaxOrbs();
            initCardParallax();
        }
    }

    /* ── Hamburger ─────────────────────────────────────────────── */
    function initHamburger() {
        const btn    = document.querySelector('[data-landing-menu]');
        const header = document.querySelector('.landing-header');
        if (!btn || !header) return;

        btn.addEventListener('click', () => {
            const open = header.classList.toggle('is-open');
            btn.classList.toggle('is-open', open);
            btn.setAttribute('aria-expanded', String(open));
        });

        header.querySelectorAll('.landing-nav-link').forEach(link => {
            link.addEventListener('click', () => {
                header.classList.remove('is-open');
                btn.classList.remove('is-open');
                btn.setAttribute('aria-expanded', 'false');
            });
        });
    }

    /* ── Header scroll ─────────────────────────────────────────── */
    function initHeaderScroll() {
        const header = document.querySelector('.landing-header');
        if (!header) return;
        const update = () => {
            header.style.boxShadow = window.scrollY > 10
                ? '0 1px 20px rgba(15,23,42,.08)' : '';
        };
        window.addEventListener('scroll', update, { passive: true });
        update();
    }

    /* ── Reveal ────────────────────────────────────────────────── */
    function initReveal() {
        const items = document.querySelectorAll('[data-reveal]');
        if (!items.length) return;

        // Fallback sem suporte a IntersectionObserver
        if (!('IntersectionObserver' in window) || reduced) {
            items.forEach(el => el.classList.add('is-visible'));
            return;
        }

        const observer = new IntersectionObserver((entries, obs) => {
            entries.forEach(entry => {
                if (!entry.isIntersecting) return;

                const delay = parseInt(entry.target.dataset.revealDelay ?? '0', 10);
                setTimeout(() => entry.target.classList.add('is-visible'), delay);
                obs.unobserve(entry.target);
            });
        }, {
            threshold: 0.08,
            rootMargin: '0px 0px -60px 0px'
        });

        items.forEach(el => observer.observe(el));

        // Process steps: escalonamento extra dentro do grupo
        document.querySelectorAll('.process-step').forEach((step, i) => {
            step.dataset.revealDelay = String(i * 130);
        });

        // Feature cards: escalonamento
        document.querySelectorAll('.feature-card').forEach((card, i) => {
            card.dataset.revealDelay = String(i * 90);
        });
    }

    /* ── Contadores animados ───────────────────────────────────── */
    function initCounters() {
        const counters = document.querySelectorAll('[data-count]');
        if (!counters.length || reduced) return;

        const animate = (el) => {
            const target   = Number(el.dataset.count);
            const duration = 1100;
            const start    = performance.now();

            const step = (now) => {
                const t = Math.min((now - start) / duration, 1);
                el.textContent = String(Math.round(target * (1 - Math.pow(1 - t, 3))));
                if (t < 1) requestAnimationFrame(step);
            };

            requestAnimationFrame(step);
        };

        if (!('IntersectionObserver' in window)) {
            counters.forEach(animate);
            return;
        }

        const obs = new IntersectionObserver((entries, o) => {
            entries.forEach(entry => {
                if (!entry.isIntersecting) return;
                animate(entry.target);
                o.unobserve(entry.target);
            });
        }, { threshold: 0.8 });

        counters.forEach(el => obs.observe(el));
    }

    /* ── Parallax orbs ─────────────────────────────────────────── */
    function initParallaxOrbs() {
        const orbs = document.querySelectorAll('.hero-orb');
        if (!orbs.length) return;

        const factors = [0.022, -0.014, 0.018];
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

    /* ── Parallax card visual ──────────────────────────────────── */
    function initCardParallax() {
        const visual = document.querySelector('.landing-hero-visual');
        if (!visual || !window.matchMedia('(min-width: 901px)').matches) return;

        let frame;

        visual.addEventListener('pointermove', (e) => {
            cancelAnimationFrame(frame);
            frame = requestAnimationFrame(() => {
                const rect = visual.getBoundingClientRect();
                const x = ((e.clientX - rect.left) / rect.width  - .5) * 9;
                const y = ((e.clientY - rect.top)  / rect.height - .5) * 6;
                visual.style.transform = `translate3d(${x}px, ${y}px, 0)`;
            });
        });

        visual.addEventListener('pointerleave', () => {
            cancelAnimationFrame(frame);
            visual.style.transition = 'transform .5s ease';
            visual.style.transform  = '';
            setTimeout(() => { visual.style.transition = ''; }, 500);
        });
    }

})();
