import { qs } from '../core/dom.js';

export function initHeader(root = document) {
    const header = qs('[data-component="header"]', root) || qs('header', root);
    if (!header) return;
    header.querySelectorAll('[data-action="toggle-sidebar"]').forEach((button) => {
        button.setAttribute('aria-expanded', button.getAttribute('aria-expanded') ?? 'true');
    });
}
