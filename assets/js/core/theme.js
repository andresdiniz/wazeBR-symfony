const THEME_KEY = 'wazebr-theme';
const THEMES = new Set(['system', 'light', 'dark']);

function readStoredTheme() {
    try {
        return window.localStorage.getItem(THEME_KEY);
    } catch {
        return null;
    }
}

function storeTheme(theme) {
    try {
        window.localStorage.setItem(THEME_KEY, theme);
    } catch {
        // O tema continua funcionando nesta página mesmo quando o storage é bloqueado.
    }
}

export function applyTheme(theme) {
    const value = THEMES.has(theme) ? theme : 'system';
    document.documentElement.dataset.theme = value;
    document.querySelectorAll('[data-theme-select]').forEach((select) => { select.value = value; });
    return value;
}

export function initTheme() {
    const stored = readStoredTheme();
    applyTheme(THEMES.has(stored) ? stored : 'system');
    document.querySelectorAll('[data-theme-select]').forEach((select) => {
        if (select.dataset.themeBound === 'true') return;
        select.dataset.themeBound = 'true';
        select.addEventListener('change', () => {
            const value = THEMES.has(select.value) ? select.value : 'system';
            storeTheme(value);
            applyTheme(value);
        });
    });
}
