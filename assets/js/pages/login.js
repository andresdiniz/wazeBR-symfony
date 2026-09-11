import { qs } from '../core/dom.js';

export function initLogin(root = document) {
    const form = qs('[data-page="login"] form', root) || qs('.login-form', root);
    if (!form) return;
    form.addEventListener('submit', () => form.classList.add('is-submitting'));
}
