import { qs } from '../core/dom.js';

export function initResetPassword(root = document) {
    const form = qs('[data-page="reset-password"] form', root) || qs('.reset-password-form', root);
    if (!form) return;
    form.addEventListener('submit', () => form.classList.add('is-submitting'));
}
