import { qs } from '../core/dom.js';

export function initCifs(root = document) {
    const page = qs('[data-page="cifs"]', root) || qs('.cifs-page', root);
    if (!page) return;
    page.dispatchEvent(new CustomEvent('cifs:ready', { bubbles: true }));
}
