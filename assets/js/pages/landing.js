import { qs } from '../core/dom.js';

export function initLanding(root = document) {
    const page = qs('[data-page="landing"]', root) || qs('.landing-page', root);
    if (!page) return;
    page.dispatchEvent(new CustomEvent('landing:ready', { bubbles: true }));
}
