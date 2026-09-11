import { qs } from '../core/dom.js';

export function initLogin(root = document) {
    const page = qs('[data-page="login"]', root) || qs('.login-page', root);
    const form = page?.querySelector('form') || qs('.login-form', root);
    if (!form) return;
    form.addEventListener('submit', () => form.classList.add('is-submitting'));
}
