/**
 * forms.js — Validação suave, auto-resize de textarea e estado `is-invalid`.
 */

export function initForms(root = document) {
    if (root.__formsInit) return;
    root.__formsInit = true;

    root.querySelectorAll('textarea[data-autosize]').forEach((el) => {
        const resize = () => {
            el.style.height = 'auto';
            el.style.height = `${el.scrollHeight}px`;
        };
        el.addEventListener('input', resize);
        resize();
    });

    root.addEventListener('submit', (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement)) return;

        form.querySelectorAll('[required]').forEach((input) => {
            const wrapper = input.closest('.form-group, .dashboard-filter-field');
            if (!wrapper) return;
            wrapper.classList.toggle('is-invalid', !input.checkValidity());
        });
    }, { capture: true });
}

export default initForms;
