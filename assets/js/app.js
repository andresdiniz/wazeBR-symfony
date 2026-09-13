import './css/core/reset.css';
import './css/core/variables.css';
import './css/core/utilities.css';
import './css/core/theme.css';
import './css/layout/base-layout.css';
import './css/layout/header.css';
import './css/layout/sidebar.css';
import './css/layout/footer.css';

const THEME_KEY = 'wazebr-theme';
const THEMES = new Set(['system', 'light', 'dark']);

function applyTheme(theme) {
    const value = THEMES.has(theme) ? theme : 'system';
    document.documentElement.dataset.theme = value;
    document.querySelectorAll('[data-theme-select]').forEach((select) => { select.value = value; });
}

function initTheme() {
    const initial = localStorage.getItem(THEME_KEY) || document.documentElement.dataset.theme || 'system';
    applyTheme(initial);
    document.querySelectorAll('[data-theme-select]').forEach((select) => {
        select.addEventListener('change', () => {
            const value = THEMES.has(select.value) ? select.value : 'system';
            localStorage.setItem(THEME_KEY, value);
            applyTheme(value);
        });
    });
    const media = window.matchMedia('(prefers-color-scheme: dark)');
    media.addEventListener?.('change', () => { if (document.documentElement.dataset.theme === 'system') applyTheme('system'); });
}

document.addEventListener('DOMContentLoaded', initTheme);
