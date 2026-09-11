import { qs } from '../core/dom.js';

export function initBaseLayout(root = document) {
    const sidebar = qs('[data-component="sidebar"]', root) || qs('.sidebar', root);
    const toggle = qs('[data-action="toggle-sidebar"]', root);
    if (!sidebar || !toggle) return;
    toggle.addEventListener('click', () => {
        const collapsed = sidebar.classList.toggle('is-collapsed');
        toggle.setAttribute('aria-expanded', String(!collapsed));
    });
}
