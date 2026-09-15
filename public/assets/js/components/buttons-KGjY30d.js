/**
 * buttons.js — Feedback de clique / estado de loading.
 *
 * ⚠️ O loading automático é OPT-IN via [data-loading="auto"] no <form>.
 * Motivo: forms que fazem preventDefault + AJAX (ex.: filtros do dashboard)
 * nunca disparam navegação, então uma classe .is-loading (que tem
 * pointer-events: none) travaria o botão pra sempre após o 1º clique.
 */

export function initButtons(root = document) {
    if (root.__buttonsInit) return;
    root.__buttonsInit = true;

    root.addEventListener('submit', (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement)) return;

        // Só aplica loading se o form pedir explicitamente.
        if (form.dataset.loading !== 'auto') return;

        const buttons = form.querySelectorAll(
            'button[type="submit"], button:not([type])'
        );

        buttons.forEach((btn) => {
            if (btn.dataset.keepEnabled) return;
            btn.classList.add('is-loading');
            btn.setAttribute('aria-busy', 'true');
        });

        // Rede de segurança: se por algum motivo o botão travar,
        // libera em 6s.
        window.setTimeout(() => {
            buttons.forEach((btn) => {
                btn.classList.remove('is-loading');
                btn.removeAttribute('aria-busy');
            });
        }, 6000);
    }, { passive: true });
}

export default initButtons;
