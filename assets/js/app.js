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

export function applyTheme(theme) {
    const value = THEMES.has(theme) ? theme : 'system';
    document.documentElement.dataset.theme = value;
    document.querySelectorAll('[data-theme-select]').forEach((select) => {
        select.value = value;
    });
}

function initTheme() {
    const stored = window.localStorage.getItem(THEME_KEY);
    applyTheme(THEMES.has(stored) ? stored : 'system');
    document.querySelectorAll('[data-theme-select]').forEach((select) => {
        if (select.dataset.themeBound === 'true') return;
        select.dataset.themeBound = 'true';
        select.addEventListener('change', () => {
            const value = THEMES.has(select.value) ? select.value : 'system';
            window.localStorage.setItem(THEME_KEY, value);
            applyTheme(value);
        });
    });
    const media = window.matchMedia('(prefers-color-scheme: dark)');
    media.addEventListener?.('change', () => {
        if (document.documentElement.dataset.theme === 'system') applyTheme('system');
    });
}

function closeHeaderMenus(except = null) {
    document.querySelectorAll('[data-header-notifications], [data-header-user-menu]').forEach((menu) => {
        if (menu !== except) menu.hidden = true;
    });
    document.querySelectorAll('[data-header-notifications-toggle], [data-header-user-toggle]').forEach((button) => {
        const target = button.getAttribute('aria-controls');
        const menu = target ? document.getElementById(target) : null;
        if (menu !== except) button.setAttribute('aria-expanded', 'false');
    });
}

function initHeaderMenus() {
    const notificationToggle = document.querySelector('[data-header-notifications-toggle]');
    const notificationMenu = document.querySelector('[data-header-notifications]');
    const userToggle = document.querySelector('[data-header-user-toggle]');
    const userMenu = document.querySelector('[data-header-user-menu]');
    const mobileToggle = document.querySelector('[data-header-mobile-toggle]');
    const navigation = document.querySelector('[data-header-navigation]');

    const bindToggle = (button, menu) => {
        if (!button || !menu || button.dataset.bound === 'true') return;
        button.dataset.bound = 'true';
        button.addEventListener('click', (event) => {
            event.stopPropagation();
            const opening = menu.hidden;
            closeHeaderMenus(opening ? menu : null);
            menu.hidden = !opening;
            button.setAttribute('aria-expanded', String(opening));
        });
    };

    bindToggle(notificationToggle, notificationMenu);
    bindToggle(userToggle, userMenu);

    if (mobileToggle && navigation && mobileToggle.dataset.bound !== 'true') {
        mobileToggle.dataset.bound = 'true';
        mobileToggle.addEventListener('click', (event) => {
            event.stopPropagation();
            const open = navigation.classList.toggle('is-open');
            mobileToggle.setAttribute('aria-expanded', String(open));
        });
    }

    document.querySelector('[data-header-notifications-read]')?.addEventListener('click', () => {
        document.querySelectorAll('.app-header__notification-item.is-unread').forEach((item) => item.classList.remove('is-unread'));
        const badge = document.querySelector('.app-header__notification-badge');
        if (badge) badge.hidden = true;
    });

    document.addEventListener('click', (event) => {
        if (!event.target.closest('[data-app-header]')) {
            closeHeaderMenus();
            navigation?.classList.remove('is-open');
            mobileToggle?.setAttribute('aria-expanded', 'false');
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeHeaderMenus();
            navigation?.classList.remove('is-open');
            mobileToggle?.setAttribute('aria-expanded', 'false');
        }
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            document.querySelector('[data-header-search-input]')?.focus();
        }
    });
}

function initHeaderSearch() {
    const form = document.querySelector('[data-header-search]');
    const input = document.querySelector('[data-header-search-input]');
    if (!form || !input || form.dataset.bound === 'true') return;
    form.dataset.bound = 'true';
    input.addEventListener('input', () => form.classList.toggle('has-value', input.value.trim() !== ''));
}

export function initThemeAndHeader() {
    initTheme();
    initHeaderMenus();
    initHeaderSearch();
}

document.addEventListener('DOMContentLoaded', initThemeAndHeader);
