(() => {
  'use strict';
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  document.addEventListener('DOMContentLoaded', () => {

    /* ─── HEADER MENU ─── */
    const header = document.querySelector('.landing-header');
    const menuButton = document.querySelector('[data-landing-menu]');

    if (header && menuButton) {
      menuButton.addEventListener('click', () => {
        const isOpen = header.classList.toggle('is-open');
        menuButton.classList.toggle('is-open', isOpen);
        menuButton.setAttribute('aria-expanded', String(isOpen));
      });

      header.querySelectorAll('.landing-nav-link, .landing-header-actions a').forEach((link) => {
        link.addEventListener('click', () => {
          header.classList.remove('is-open');
          menuButton.classList.remove('is-open');
          menuButton.setAttribute('aria-expanded', 'false');
        });
      });
    }

    /* ─── SCROLL REVEAL ─── */
    const revealItems = document.querySelectorAll('[data-reveal]');
    if (reducedMotion || !('IntersectionObserver' in window)) {
      revealItems.forEach((item) => item.classList.add('is-visible'));
    } else {
      const revealObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach((entry) => {
          if (!entry.isIntersecting) return;
          const delay = Number(entry.target.dataset.revealDelay || 0);
          window.setTimeout(() => entry.target.classList.add('is-visible'), delay);
          observer.unobserve(entry.target);
        });
      }, { threshold: 0.1, rootMargin: '0px 0px -30px' });
      revealItems.forEach((item) => revealObserver.observe(item));
    }

    /* ─── ANIMATED COUNTERS ─── */
    const counters = document.querySelectorAll('[data-count]');

    const animateCounter = (element) => {
      const target = Number(element.dataset.count || 0);
      const startedAt = performance.now();
      const duration = 1200;
      const tick = (now) => {
        const progress = Math.min((now - startedAt) / duration, 1);
        const eased = 1 - Math.pow(1 - progress, 3);
        element.textContent = Math.round(target * eased).toLocaleString('pt-BR');
        if (progress < 1) requestAnimationFrame(tick);
      };
      requestAnimationFrame(tick);
    };

    if (reducedMotion || !('IntersectionObserver' in window)) {
      counters.forEach((counter) => {
        counter.textContent = Number(counter.dataset.count || 0).toLocaleString('pt-BR');
      });
    } else {
      const counterObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach((entry) => {
          if (!entry.isIntersecting) return;
          animateCounter(entry.target);
          observer.unobserve(entry.target);
        });
      }, { threshold: 0.5 });
      counters.forEach((counter) => counterObserver.observe(counter));
    }

    /* ─── HEADER SHADOW ON SCROLL ─── */
    if (header) {
      const onScroll = () => {
        header.style.boxShadow = window.scrollY > 8
          ? '0 4px 24px rgba(15,23,42,.08)'
          : '';
      };
      window.addEventListener('scroll', onScroll, { passive: true });
    }

    /* ─── SMOOTH SCROLL ─── */
    document.querySelectorAll('a[href^="#"]').forEach((anchor) => {
      anchor.addEventListener('click', (e) => {
        const id = anchor.getAttribute('href').slice(1);
        if (!id) return;
        const target = document.getElementById(id);
        if (!target) return;
        e.preventDefault();
        target.scrollIntoView({ behavior: reducedMotion ? 'auto' : 'smooth', block: 'start' });
      });
    });

  });
})();
