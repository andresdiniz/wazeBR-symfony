/**
 * forms.js — Validação suave, auto-resize de textarea e estado `is-invalid`.
 */

export default function initForms(root = document) {
    root.querySelectorAll('textarea[data-autosize]').forEach((el) => {
        const resize = () => {
            el.style.height = 'auto';
            el.style.height = `${el.scrollHeight}px`;
        };
        el.addEventListener('input', resize);
        resize();
    });

    // Marca inputs required inválidos ao submeter
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
