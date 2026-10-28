const root = document.querySelector('[data-route-show]');
export const RS_LOCALE = (root?.dataset.routeShowLocale || document.documentElement.lang || 'pt-BR').replace('_', '-');
let messages = {};
try { messages = JSON.parse(document.querySelector('[data-route-show-i18n]')?.textContent || '{}'); } catch {}
export function rsT(key, parameters = {}) {
    let value = Object.hasOwn(messages, key) ? messages[key] : key;
    for (const [name, text] of Object.entries(parameters)) value = value.replaceAll(name, String(text));
    return value;
}
export function rsLabel(value, fallback = '—') {
    if (value == null || value === '') return fallback;
    const text = String(value);
    for (const key of [text, `code.${text}`, `level.${text}`]) {
        if (Object.hasOwn(messages, key)) return messages[key];
    }
    return text;
}
