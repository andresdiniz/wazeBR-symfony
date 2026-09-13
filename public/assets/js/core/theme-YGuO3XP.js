const THEME_KEY = 'wazebr-theme';
const THEMES = new Set(['system', 'light', 'dark']);

export function applyTheme(theme) {
    const value = THEMES.has(theme) ? theme : 'system';
    document.documentElement.dataset.theme = value;
    document.querySelectorAll('[data-theme-select]').forEach((select) => { select.value = value; });
    return value;
}

export function initTheme() {
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
}
