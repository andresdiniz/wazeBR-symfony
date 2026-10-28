/**
 * layout/header.js — v3
 *
 * Header global com:
 *  - Notificações do parceiro do usuário (dropdown)
 *  - Seletor de idioma PT-BR / EN / ES
 *  - Menu do usuário
 *  - Busca (Ctrl/Cmd+K)
 *  - Tema (system/light/dark) via color-scheme
 *  - Hamburger mobile → delega para sidebarController
 *  - Navegação por teclado (setas, Home/End, Esc)
 *  - Sincronização entre abas
 */

'use strict';

const THEME_KEY = 'wazebr-theme';
const LOCALE_KEY = 'wazebr-locale';
const THEMES = ['system', 'light', 'dark'];
const LOCALES = {
  pt_BR: { flag: '🇧🇷', htmlLang: 'pt-BR' },
  en: { flag: '🇺🇸', htmlLang: 'en' },
  es: { flag: '🇪🇸', htmlLang: 'es' },
};
const TRANSITION_MS = 220;

// ─────────────────────────────────────────────────────────────────────────
// Bootstrap
// ─────────────────────────────────────────────────────────────────────────

export function initHeader(doc = globalThis.document) {
  if (!doc) return;

  const header = doc.querySelector('[data-app-header]');
  if (!header || header.dataset.initialized === 'true') return;
  header.dataset.initialized = 'true';

  initTheme(header, doc);
  initNotifications(header, doc);
  initLanguageMenu(header, doc);
  initUserMenu(header, doc);
  initAdminMenu(header, doc);
  initMobileNav(header, doc);
  initSearchShortcut(header, doc);
  initGlobalClose(header, doc);
  initResponsiveState(header, doc);
}

// ─────────────────────────────────────────────────────────────────────────
// Tema — delega para color-scheme via [data-theme]
// ─────────────────────────────────────────────────────────────────────────

function readTheme() {
  try {
    const v = localStorage.getItem(THEME_KEY);
    return THEMES.includes(v) ? v : 'system';
  } catch { return 'system'; }
}

function persistTheme(theme) {
  try { localStorage.setItem(THEME_KEY, theme); } catch { /* noop */ }
}

/**
 * Aplica o tema. `system` remove o atributo (usa color-scheme padrão do :root).
 */
function applyTheme(theme, doc, withTransition = true) {
  const root = doc.documentElement;

  if (withTransition) {
    root.classList.add('theme-transition');
    clearTimeout(root._themeTimer);
    root._themeTimer = setTimeout(
      () => root.classList.remove('theme-transition'),
      TRANSITION_MS
    );
  }

  if (theme === 'system') {
    root.removeAttribute('data-theme');
    root.removeAttribute('data-theme-resolved');
  } else {
    root.setAttribute('data-theme', theme);
    root.setAttribute('data-theme-resolved', theme);
  }
  doc.defaultView?.dispatchEvent(new CustomEvent('wazebr:themechange', { detail: { theme } }));
}

function initTheme(header, doc) {
  const select = header.querySelector('[data-theme-select]');
  if (!select) return;

  // 1) Estado inicial
  const initial = readTheme();
  select.value = initial;
  applyTheme(initial, doc, false);

  // 2) Troca manual
  select.addEventListener('change', () => {
    const next = THEMES.includes(select.value) ? select.value : 'system';
    applyTheme(next, doc, true);
    persistTheme(next);
  });

  // 3) Sincronização entre abas
  window.addEventListener('storage', (event) => {
    if (event.key !== THEME_KEY) return;
    const next = THEMES.includes(event.newValue) ? event.newValue : 'system';
    select.value = next;
    applyTheme(next, doc, true);
  });

  // 4) Reage a mudanças do SO quando em modo 'system'
  const mq = window.matchMedia('(prefers-color-scheme: dark)');
  const onSystemChange = () => {
    if (readTheme() !== 'system') return;
    // O CSS já resolve via color-scheme — só dispara animação visual
    doc.documentElement.classList.add('theme-transition');
    clearTimeout(doc.documentElement._themeTimer);
    doc.documentElement._themeTimer = setTimeout(
      () => doc.documentElement.classList.remove('theme-transition'),
      TRANSITION_MS
    );
  };

  if (typeof mq.addEventListener === 'function') mq.addEventListener('change', onSystemChange);
  else if (typeof mq.addListener === 'function') mq.addListener(onSystemChange);
}

// ─────────────────────────────────────────────────────────────────────────
// Dropdown genérico
// ─────────────────────────────────────────────────────────────────────────

function createDropdown({ trigger, dropdown, onOpen, onClose, doc }) {
  if (!trigger || !dropdown) return null;

  const open = () => {
    dropdown.hidden = false;
    trigger.setAttribute('aria-expanded', 'true');
    onOpen?.();
    const first = dropdown.querySelector('a[href], button:not([hidden])');
    if (first) requestAnimationFrame(() => first.focus());
  };

  const close = (returnFocus = false) => {
    if (dropdown.hidden) return;
    dropdown.hidden = true;
    trigger.setAttribute('aria-expanded', 'false');
    onClose?.();
    if (returnFocus) requestAnimationFrame(() => trigger.focus());
  };

  const toggle = () => (dropdown.hidden ? open() : close());

  trigger.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    toggle();
  });

  dropdown.addEventListener('keydown', (e) => {
    const items = [...dropdown.querySelectorAll('a[href], button:not([hidden])')]
      .filter((el) => !el.hidden);
    if (!items.length) return;

    const idx = items.indexOf(doc.activeElement);

    switch (e.key) {
      case 'ArrowDown':
        e.preventDefault();
        items[(idx < 0 ? 0 : (idx + 1)) % items.length].focus();
        break;
      case 'ArrowUp':
        e.preventDefault();
        items[idx <= 0 ? items.length - 1 : idx - 1].focus();
        break;
      case 'Home':
        e.preventDefault();
        items[0].focus();
        break;
      case 'End':
        e.preventDefault();
        items[items.length - 1].focus();
        break;
      case 'Escape':
        e.preventDefault();
        close(true);
        break;
    }
  });

  return { open, close, toggle, isOpen: () => !dropdown.hidden };
}

// ─────────────────────────────────────────────────────────────────────────
// Notificações
// ─────────────────────────────────────────────────────────────────────────

let notificationsApi = null;
let languageMenuApi = null;
let userMenuApi = null;
let adminMenuApi = null;

function initNotifications(header, doc) {
  const btn = header.querySelector('[data-header-notifications-toggle]');
  const dropdown = header.querySelector('[data-header-notifications]');
  const readBtn = header.querySelector('[data-header-notifications-read]');
  const badge = header.querySelector('.app-header__notification-badge');

  if (!btn || !dropdown) return;

  notificationsApi = createDropdown({
    trigger: btn,
    dropdown,
    doc,
    onOpen: () => { languageMenuApi?.close(); userMenuApi?.close(); adminMenuApi?.close(); },
  });

  readBtn?.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();

    header.querySelectorAll('.app-header__notification-item.is-unread')
      .forEach((item) => item.classList.remove('is-unread'));

    if (badge) {
      badge.hidden = true;
      badge.textContent = '0';
    }
    btn.setAttribute('aria-label', 'Notificações');
    readBtn.remove();
  });
}

// ─────────────────────────────────────────────────────────────────────────
// Idioma
// ─────────────────────────────────────────────────────────────────────────

function readLocale() {
  try {
    const value = localStorage.getItem(LOCALE_KEY);
    return LOCALES[value] ? value : 'pt_BR';
  } catch { return 'pt_BR'; }
}

function applyLocale(locale, doc) {
  const config = LOCALES[locale] || LOCALES.pt_BR;
  const flag = header_language_flag(doc);

  doc.documentElement.lang = config.htmlLang;

  if (flag) flag.textContent = config.flag;

  doc.querySelectorAll('[data-language-option]').forEach((option) => {
    option.classList.toggle('is-active', option.dataset.languageOption === locale);
  });
}

function header_language_flag(doc) {
  return doc.querySelector('[data-header-language-flag]');
}

function initLanguageMenu(header, doc) {
  const btn = header.querySelector('[data-header-language-toggle]');
  const dropdown = header.querySelector('[data-header-language-menu]');

  if (!btn || !dropdown) return;

  languageMenuApi = createDropdown({
    trigger: btn,
    dropdown,
    doc,
    onOpen: () => { notificationsApi?.close(); userMenuApi?.close(); adminMenuApi?.close(); },
  });

  applyLocale(readLocale(), doc);

  dropdown.querySelectorAll('[data-language-option]').forEach((option) => {
    option.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();

      const locale = option.dataset.languageOption;
      if (!LOCALES[locale]) return;

      try { localStorage.setItem(LOCALE_KEY, locale); } catch { /* noop */ }

      applyLocale(locale, doc);
      languageMenuApi?.close();

      // Recarrega a página para o backend aplicar a locale selecionada.
      window.location.reload();
    });
  });

  window.addEventListener('storage', (event) => {
    if (event.key !== LOCALE_KEY || !LOCALES[event.newValue]) return;
    applyLocale(event.newValue, doc);
  });
}

// ─────────────────────────────────────────────────────────────────────────
// User menu
// ─────────────────────────────────────────────────────────────────────────

function initUserMenu(header, doc) {
  const btn = header.querySelector('[data-header-user-toggle]');
  const dropdown = header.querySelector('[data-header-user-menu]');
  if (!btn || !dropdown) return;

  userMenuApi = createDropdown({
    trigger: btn,
    dropdown,
    doc,
    onOpen: () => { notificationsApi?.close(); languageMenuApi?.close(); adminMenuApi?.close(); },
  });
}

function initAdminMenu(header, doc) {
  const btn = header.querySelector('[data-header-admin-toggle]');
  const dropdown = header.querySelector('[data-header-admin-menu]');
  if (!btn || !dropdown) return;

  adminMenuApi = createDropdown({
    trigger: btn,
    dropdown,
    doc,
    onOpen: () => { notificationsApi?.close(); languageMenuApi?.close(); userMenuApi?.close(); },
  });
}

// ─────────────────────────────────────────────────────────────────────────
// Mobile nav → delega para sidebar
// ─────────────────────────────────────────────────────────────────────────

function initMobileNav(header, doc) {
  const btn = header.querySelector('[data-header-mobile-toggle]');
  if (!btn) return;

  btn.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();

    notificationsApi?.close();
    languageMenuApi?.close();
    userMenuApi?.close();
    adminMenuApi?.close();

    const sidebar = doc.querySelector('[data-sidebar]');

    if (sidebar?.sidebarController) {
      sidebar.sidebarController.toggle();
      syncHamburger(btn, sidebar.sidebarController.isOpen());
      return;
    }

    // Fallback: nav interna
    const nav = header.querySelector('[data-header-navigation]');
    if (!nav) return;
    const isOpen = btn.getAttribute('aria-expanded') === 'true';
    nav.classList.toggle('is-open', !isOpen);
    btn.setAttribute('aria-expanded', String(!isOpen));
    btn.setAttribute('aria-label', !isOpen ? 'Fechar navegação' : 'Abrir navegação');
  });

  doc.addEventListener('wazebr:sidebar:open',  () => syncHamburger(btn, true));
  doc.addEventListener('wazebr:sidebar:close', () => syncHamburger(btn, false));
}

function syncHamburger(btn, open) {
  btn.setAttribute('aria-expanded', String(open));
  btn.setAttribute('aria-label', open ? 'Fechar menu lateral' : 'Abrir menu lateral');
}

// ─────────────────────────────────────────────────────────────────────────
// Atalho Ctrl/Cmd+K
// ─────────────────────────────────────────────────────────────────────────

function initSearchShortcut(header, doc) {
  const input = header.querySelector('[data-header-search-input]');
  if (!input) return;

  doc.addEventListener('keydown', (e) => {
    const isShortcut = (e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k';
    if (!isShortcut) return;

    e.preventDefault();
    input.focus();
    try { input.select(); } catch { /* noop */ }
  });
}

// ─────────────────────────────────────────────────────────────────────────
// Fechamento global
// ─────────────────────────────────────────────────────────────────────────

function initGlobalClose(header, doc) {
  doc.addEventListener('click', (e) => {
    if (!header.contains(e.target)) {
      notificationsApi?.close();
      languageMenuApi?.close();
      userMenuApi?.close();
      adminMenuApi?.close();
    }

    // Nav-fallback do header
    const sidebar = doc.querySelector('[data-sidebar]');
    if (!sidebar) {
      const nav = header.querySelector('[data-header-navigation]');
      const btn = header.querySelector('[data-header-mobile-toggle]');
      nav?.classList.remove('is-open');
      if (btn) btn.setAttribute('aria-expanded', 'false');
    }
  });

  doc.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;

    if (notificationsApi?.isOpen()) { notificationsApi.close(true); return; }
    if (languageMenuApi?.isOpen())  { languageMenuApi.close(true);  return; }
    if (userMenuApi?.isOpen())      { userMenuApi.close(true);      return; }
    if (adminMenuApi?.isOpen())     { adminMenuApi.close(true);     return; }
  });
}

// ─────────────────────────────────────────────────────────────────────────
// Responsividade
// ─────────────────────────────────────────────────────────────────────────

function initResponsiveState(header, doc) {
  const mq = doc.defaultView?.matchMedia('(max-width: 1180px)');
  if (!mq) return;

  const handle = (e) => {
    if (e.matches) return;
    // Voltou para desktop
    const nav = header.querySelector('[data-header-navigation]');
    const btn = header.querySelector('[data-header-mobile-toggle]');
    nav?.classList.remove('is-open');
    if (btn) syncHamburger(btn, false);
  };

  if (typeof mq.addEventListener === 'function') mq.addEventListener('change', handle);
  else if (typeof mq.addListener === 'function') mq.addListener(handle);
}
