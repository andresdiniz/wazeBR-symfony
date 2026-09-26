// assets/js/pages/partner-feed-show.js

'use strict';

/**
 * Copia o conteúdo do preview JSON e fornece feedback visual no botão.
 */
function initCopyJson(root = document) {
    root.querySelectorAll('[data-copy-json]').forEach((btn) => {
        const targetId = btn.dataset.copyJson;
        const target = targetId ? root.getElementById(targetId) : null;

        if (!target) return;

        btn.addEventListener('click', async () => {
            const text = target.textContent ?? '';

            const applyFeedback = () => {
                btn.dataset.copied = 'true';
                btn.setAttribute('aria-label', 'JSON copiado!');
                setTimeout(() => {
                    delete btn.dataset.copied;
                    btn.setAttribute('aria-label', 'Copiar JSON do feed');
                }, 2200);
            };

            try {
                await navigator.clipboard.writeText(text);
                applyFeedback();
            } catch {
                // Fallback para contextos sem Clipboard API (HTTP puro)
                const tmp = document.createElement('textarea');
                tmp.value = text;
                tmp.style.cssText = 'position:fixed;left:-9999px;top:-9999px;opacity:0';
                document.body.appendChild(tmp);
                tmp.focus();
                tmp.select();
                try {
                    document.execCommand('copy');
                    applyFeedback();
                } finally {
                    document.body.removeChild(tmp);
                }
            }
        });
    });
}

/**
 * Ponto de entrada chamado pelo registry do app.js.
 * O `findInit` resolve: default → init → initXxx.
 */
export function init(root = document) {
    initCopyJson(root);
}

export default init;
