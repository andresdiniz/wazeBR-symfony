import { initTheme } from '../core/theme.js';
import { closeMenus, initMenus } from '../components/menus.js';
import { initNotifications } from '../components/notifications.js';

export function initHeader(root = document) {
    const header = root.querySelector('[data-app-header]');
    if (!header || header.dataset.headerInitialized === 'true') return;
    header.dataset.headerInitialized = 'true';
    initTheme();
    initMenus(root);
    initNotifications(root);
    const mobile = header.querySelector('[data-header-mobile-toggle]');
    const navigation = header.querySelector('[data-header-navigation]');
    mobile?.addEventListener('click', (event) => { event.stopPropagation(); const open = navigation.classList.toggle('is-open'); mobile.setAttribute('aria-expanded', String(open)); });
    document.addEventListener('click', (event) => { if (!header.contains(event.target)) { closeMenus(header); navigation?.classList.remove('is-open'); mobile?.setAttribute('aria-expanded', 'false'); } });
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape') { closeMenus(header); navigation?.classList.remove('is-open'); mobile?.setAttribute('aria-expanded', 'false'); } if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); header.querySelector('[data-header-search-input]')?.focus(); } });
}
