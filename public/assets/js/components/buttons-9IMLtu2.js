/**
 * buttons.js — Feedback de clique, estado de loading automático em <form>.
 */

export function initButtons(root = document) {
    if (root.__buttonsInit) return;
    root.__buttonsInit = true;

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

export default initButtons;
