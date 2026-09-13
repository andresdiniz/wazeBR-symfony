import '../css/core/reset.css';
import '../css/core/variables.css';
import '../css/core/utilities.css';
import '../css/core/theme.css';
import '../css/layout/base-layout.css';
import '../css/layout/header.css';
import '../css/layout/sidebar.css';
import '../css/layout/footer.css';

const THEME_KEY = 'wazebr-theme';
const THEMES = new Set(['system', 'light', 'dark']);

function applyTheme(theme) {
    const value = THEMES.has(theme) ? theme : 'system';
    document.documentElement.dataset.theme = value;
    document.querySelectorAll('[data-theme-select]').forEach((select) => { select.value = value; });
}

function initTheme() {
    const stored = window.localStorage.getItem(THEME_KEY);
    applyTheme(THEMES.has(stored) ? stored : 'system');
    document.querySelectorAll('[data-theme-select]').forEach((select) => {
        if (select.dataset.themeBound === 'true') return;
        select.dataset.themeBound = 'true';
        select.addEventListener('change', () => { const value = THEMES.has(select.value) ? select.value : 'system'; window.localStorage.setItem(THEME_KEY, value); applyTheme(value); });
    });
}

function initHeader() {
    const header = document.querySelector('[data-app-header]');
    if (!header || header.dataset.initialized === 'true') return;
    header.dataset.initialized = 'true';
    const close = () => { header.querySelectorAll('[data-header-notifications],[data-header-user-menu]').forEach((menu) => { menu.hidden = true; }); header.querySelectorAll('[data-header-notifications-toggle],[data-header-user-toggle]').forEach((button) => button.setAttribute('aria-expanded', 'false')); };
    const bind = (buttonSelector, menuSelector) => { const button = header.querySelector(buttonSelector); const menu = header.querySelector(menuSelector); if (!button || !menu) return; button.addEventListener('click', (event) => { event.stopPropagation(); const open = menu.hidden; close(); menu.hidden = !open; button.setAttribute('aria-expanded', String(open)); }); };
    bind('[data-header-notifications-toggle]', '[data-header-notifications]');
    bind('[data-header-user-toggle]', '[data-header-user-menu]');
    const mobile = header.querySelector('[data-header-mobile-toggle]'); const navigation = header.querySelector('[data-header-navigation]');
    mobile?.addEventListener('click', () => { const open = navigation.classList.toggle('is-open'); mobile.setAttribute('aria-expanded', String(open)); });
    header.querySelector('[data-header-notifications-read]')?.addEventListener('click', () => { header.querySelectorAll('.is-unread').forEach((item) => item.classList.remove('is-unread')); const badge = header.querySelector('.app-header__notification-badge'); if (badge) badge.hidden = true; });
    document.addEventListener('click', (event) => { if (!header.contains(event.target)) { close(); navigation?.classList.remove('is-open'); mobile?.setAttribute('aria-expanded', 'false'); } });
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape') close(); if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); header.querySelector('[data-header-search-input]')?.focus(); } });
}

function init() { initTheme(); initHeader(); }
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true }); else init();
