import { qs } from '../core/dom.js';

export function initResetPassword(root = document) {
    const page = qs('[data-page="reset-password"]', root) || qs('.reset-password-page', root);
    const form = page?.querySelector('form') || qs('.reset-password-form', root);
    if (!form) return;
    form.addEventListener('submit', () => form.classList.add('is-submitting'));
}
