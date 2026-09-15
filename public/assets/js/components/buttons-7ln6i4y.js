/**
 * buttons.js — Feedback de clique, estado de loading automático em <form>.
 */

export default function initButtons(root = document) {
    // Auto-submit de botões dentro de <form> quando o form tiver [data-autosubmit]
    root.addEventListener('submit', (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement)) return;

        form.querySelectorAll('button[type="submit"], button:not([type])')
            .forEach((btn) => {
                if (!btn.dataset.keepEnabled) {
                    btn.classList.add('is-loading');
                    btn.setAttribute('aria-busy', 'true');
                }
            });
    }, { passive: true });
}
