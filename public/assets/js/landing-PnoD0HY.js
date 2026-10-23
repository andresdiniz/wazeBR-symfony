(() => {
    'use strict';

    /* ═══════════════════════════════════════════════
       ESTADO GLOBAL
       ═══════════════════════════════════════════════ */
    const mediaMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    let reducedMotion = mediaMotion.matches;
    const hasIO = 'IntersectionObserver' in window;

    // Reage a mudanças de preferência do usuário em tempo real
    const onMotionChange = (e) => { reducedMotion = e.matches; };
    if (mediaMotion.addEventListener) mediaMotion.addEventListener('change', onMotionChange);
    else if (mediaMotion.addListener) mediaMotion.addListener(onMotionChange);

    /* ═══════════════════════════════════════════════
       TRACKING HELPER (module-level, sempre disponível)
       ═══════════════════════════════════════════════ */
    const track = (eventName, params = {}) => {
      try {
        if (typeof window.gtag === 'function') {
          window.gtag('event', eventName, params);
        } else if (Array.isArray(window.dataLayer)) {
          window.dataLayer.push({ event: eventName, ...params });
        }
        if (window.location.hostname === 'localhost' ||
            window.location.hostname === '127.0.0.1') {
          console.debug('[wazeBR:track]', eventName, params);
        }
      } catch (_) { /* silencioso */ }
    };

    /* ═══════════════════════════════════════════════
       READY (safe contra execução tardia)
       ═══════════════════════════════════════════════ */
    const ready = (fn) => {
      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fn, { once: true });
      } else {
        fn();
      }
    };

    ready(() => {
      initMobileMenu();
      initFaqAccordion();
      initScrollReveal();
      initCounters();
      initHeaderScrollShadow();
      initSmoothScroll();
      initScrollSpy();
      initCtaTracking();
      initCtaForm();
      track('page_view', { page: 'landing', path: window.location.pathname });
    });

    /* ═══════════════════════════════════════════════
       MOBILE MENU
       ═══════════════════════════════════════════════ */
    function initMobileMenu() {
      const header = document.querySelector('.landing-header');
      const button = document.querySelector('[data-landing-menu]');
      if (!header || !button) return;

      const nav = header.querySelector('.landing-nav');

      const applyState = (open) => {
        header.classList.toggle('is-open', open);
        button.classList.toggle('is-open', open);
        button.setAttribute('aria-expanded', String(open));
        button.setAttribute('aria-label', open ? 'Fechar menu' : 'Abrir menu');
        document.body.classList.toggle('is-menu-open', open);
      };

      const close = () => applyState(false);

      const toggle = () => {
        const willOpen = !header.classList.contains('is-open');
        applyState(willOpen);

        if (willOpen) {
          track('menu_open', { location: 'header' });
          if (nav) {
            const firstLink = nav.querySelector('a');
            if (firstLink) {
              window.setTimeout(() => firstLink.focus({ preventScroll: true }), 100);
            }
          }
        }
      };

      button.addEventListener('click', toggle);

      // Fecha ao clicar em qualquer link do menu
      header.querySelectorAll('.landing-nav-link, .landing-header-actions a')
        .forEach((link) => link.addEventListener('click', close));

      // Fecha com Escape
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && header.classList.contains('is-open')) {
          close();
          button.focus();
        }
      });

      // Fecha com clique fora
      document.addEventListener('click', (e) => {
        if (!header.classList.contains('is-open')) return;
        if (!header.contains(e.target)) close();
      });

      // Fecha ao voltar para desktop
      const mq = window.matchMedia('(min-width: 901px)');
      const onMq = (e) => { if (e.matches) close(); };
      if (mq.addEventListener) mq.addEventListener('change', onMq);
      else if (mq.addListener) mq.addListener(onMq);
    }

    /* ═══════════════════════════════════════════════
       FAQ ACCORDION
       ═══════════════════════════════════════════════ */
    function initFaqAccordion() {
      const items = document.querySelectorAll('.faq-item');
      if (!items.length) return;

      // Comportamento acordeão: abre um, fecha os outros
      items.forEach((item) => {
        item.addEventListener('toggle', () => {
          if (!item.open) return;

          items.forEach((other) => {
            if (other !== item) other.removeAttribute('open');
          });

          const summary = item.querySelector('summary');
          if (summary) {
            track('faq_open', {
              question: summary.textContent.trim().slice(0, 80)
            });
          }
        });
      });

      // Abre automaticamente o FAQ se URL tiver hash correspondente
      const hash = window.location.hash.slice(1);
      if (hash) {
        const target = document.getElementById(hash);
        if (target && target.classList.contains('faq-item')) {
          target.setAttribute('open', '');
        }
      }
    }

    /* ═══════════════════════════════════════════════
       SCROLL REVEAL
       ═══════════════════════════════════════════════ */
    function initScrollReveal() {
      const items = document.querySelectorAll('[data-reveal]');
      if (!items.length) return;

      if (reducedMotion || !hasIO) {
        items.forEach((el) => el.classList.add('is-visible'));
        return;
      }

      const observer = new IntersectionObserver((entries, obs) => {
        entries.forEach((entry) => {
          if (!entry.isIntersecting) return;
          const delay = Number(entry.target.dataset.revealDelay || 0);
          window.setTimeout(() => entry.target.classList.add('is-visible'), delay);
          obs.unobserve(entry.target);
        });
      }, { threshold: 0.12, rootMargin: '0px 0px -40px' });

      items.forEach((el) => observer.observe(el));
    }

    /* ═══════════════════════════════════════════════
       COUNTERS
       ═══════════════════════════════════════════════ */
    function initCounters() {
      const counters = document.querySelectorAll('[data-count]');
      if (!counters.length) return;

      const nf = new Intl.NumberFormat('pt-BR');

      const setFinal = (el) => {
        el.textContent = nf.format(Number(el.dataset.count || 0));
      };

      const animate = (el) => {
        const target = Number(el.dataset.count || 0);
        const duration = 1400;
        const start = performance.now();

        const tick = (now) => {
          const p = Math.min((now - start) / duration, 1);
          const eased = 1 - Math.pow(1 - p, 3);
          el.textContent = nf.format(Math.round(target * eased));
          if (p < 1) requestAnimationFrame(tick);
          else setFinal(el);
        };
        requestAnimationFrame(tick);
      };

      if (reducedMotion || !hasIO) {
        counters.forEach(setFinal);
        return;
      }

      const observer = new IntersectionObserver((entries, obs) => {
        entries.forEach((entry) => {
          if (!entry.isIntersecting) return;
          animate(entry.target);
          obs.unobserve(entry.target);
        });
      }, { threshold: 0.35 });

      counters.forEach((el) => observer.observe(el));
    }

    /* ═══════════════════════════════════════════════
       HEADER SCROLL SHADOW (throttle via rAF)
       ═══════════════════════════════════════════════ */
    function initHeaderScrollShadow() {
      const header = document.querySelector('.landing-header');
      if (!header) return;

      let ticking = false;

      const apply = () => {
        header.style.boxShadow = window.scrollY > 8
          ? '0 4px 24px rgba(15,23,42,.08)'
          : '';
        ticking = false;
      };

      window.addEventListener('scroll', () => {
        if (!ticking) {
          ticking = true;
          requestAnimationFrame(apply);
        }
      }, { passive: true });

      apply();
    }

    /* ═══════════════════════════════════════════════
       SMOOTH SCROLL (compensando header sticky)
       ═══════════════════════════════════════════════ */
    function initSmoothScroll() {
      const header = document.querySelector('.landing-header');

      document.querySelectorAll('a[href^="#"]').forEach((anchor) => {
        anchor.addEventListener('click', (e) => {
          const href = anchor.getAttribute('href');
          if (!href || href === '#') return;

          const id = href.slice(1);
          const target = document.getElementById(id);
          if (!target) return;

          e.preventDefault();

          const headerHeight = header ? header.offsetHeight : 0;
          const offset = headerHeight + 12;
          const top = target.getBoundingClientRect().top + window.scrollY - offset;

          window.scrollTo({
            top,
            behavior: reducedMotion ? 'auto' : 'smooth'
          });

          if (history.replaceState) {
            history.replaceState(null, '', `#${id}`);
          }

          // Foco acessível sem cancelar o scroll suave
          if (!target.hasAttribute('tabindex')) target.setAttribute('tabindex', '-1');
          target.focus({ preventScroll: true });
        });
      });
    }

    /* ═══════════════════════════════════════════════
       SCROLL SPY (destaca link ativo na nav)
       ═══════════════════════════════════════════════ */
    function initScrollSpy() {
      const links = document.querySelectorAll('.landing-nav-link[href^="#"]');
      if (!links.length || !hasIO) return;

      const sections = Array.from(links)
        .map((link) => document.getElementById(link.getAttribute('href').slice(1)))
        .filter(Boolean);

      if (!sections.length) return;

      const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
          if (!entry.isIntersecting) return;

          const id = entry.target.id;
          links.forEach((link) => {
            const isActive = link.getAttribute('href') === `#${id}`;
            link.classList.toggle('is-active', isActive);
            if (isActive) link.setAttribute('aria-current', 'true');
            else link.removeAttribute('aria-current');
          });
        });
      }, {
        rootMargin: '-40% 0px -55% 0px',
        threshold: 0
      });

      sections.forEach((s) => observer.observe(s));
    }

    /* ═══════════════════════════════════════════════
       CTA TRACKING
       ═══════════════════════════════════════════════ */
    function initCtaTracking() {
      const selector = '.landing-btn-primary, .landing-btn-white, .feature-link';
      document.querySelectorAll(selector).forEach((el) => {
        el.addEventListener('click', () => {
          const text = (el.textContent || '').trim().slice(0, 80);
          const section = el.closest('section');
          track('cta_click', {
            cta_text: text,
            cta_location: section?.id || 'unknown',
            cta_href: el.getAttribute('href') || ''
          });
        });
      });
    }

    /* ═══════════════════════════════════════════════
       CTA FORM (validação + feedback + tracking)
       ═══════════════════════════════════════════════ */
    function initCtaForm() {
      const form = document.querySelector('.cta-form');
      if (!form) return;

      const input = form.querySelector('input[type="email"]');
      const button = form.querySelector('button[type="submit"]');

      // Feedback acessível (criado dinamicamente para não quebrar layout)
      let feedback = form.querySelector('[data-form-feedback]');
      if (!feedback) {
        feedback = document.createElement('p');
        feedback.setAttribute('data-form-feedback', '');
        feedback.setAttribute('role', 'status');
        feedback.setAttribute('aria-live', 'polite');
        feedback.hidden = true;
        feedback.style.cssText =
          'margin-top:.6rem;font-size:.82rem;padding:.55rem .85rem;' +
          'border-radius:8px;font-weight:600;transition:opacity .2s';
        form.after(feedback);
      }

      const setFeedback = (msg, type) => {
        feedback.textContent = msg;
        feedback.hidden = false;
        if (type === 'error') {
          feedback.style.background = 'rgba(239,68,68,.18)';
          feedback.style.color = '#fee2e2';
        } else {
          feedback.style.background = 'rgba(16,185,129,.18)';
          feedback.style.color = '#d1fae5';
        }
      };

      // Validação básica no blur (UX melhor)
      input?.addEventListener('blur', () => {
        const v = (input.value || '').trim();
        if (!v) return;
        const ok = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
        input.setAttribute('aria-invalid', String(!ok));
        if (!ok) setFeedback('E-mail inválido. Verifique e tente novamente.', 'error');
        else if (feedback.dataset.type === 'error') feedback.hidden = true;
      });

      form.addEventListener('submit', (e) => {
        const email = (input?.value || '').trim();

        // Validação client-side (mailto: não valida no servidor)
        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
          e.preventDefault();
          setFeedback('Informe um e-mail válido para continuar.', 'error');
          input?.focus();
          return;
        }

        track('lead_form_submit', {
          form: 'landing_cta',
          email_domain: email.split('@')[1] || ''
        });

        setFeedback('Abrindo seu cliente de e-mail…', 'success');

        // Delay para não interferir na submissão nativa do form
        window.setTimeout(() => {
          if (button) {
            button.disabled = true;
            const original = button.innerHTML;
            button.innerHTML =
              '<i class="fa-solid fa-circle-notch fa-spin" aria-hidden="true"></i> Enviando…';
            window.setTimeout(() => {
              button.disabled = false;
              button.innerHTML = original;
            }, 2200);
          }
        }, 0);
      });
    }
  })();
