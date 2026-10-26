// assets/js/pages/partner-feed-index.js

import { copyText, flashButton } from '../core/clipboard.js';

/**
 * Copia a URL do feed JSON de um parceiro e dá feedback no botão.
 * Delegação de evento: um único listener cobre todos os cards.
 */
function initCopyFeedUrl(page) {
    page.addEventListener('click', async (event) => {
        const btn = event.target.closest('[data-copy-feed-url]');
        if (!btn || !page.contains(btn)) return;

        const url = btn.dataset.copyFeedUrl;
        if (!url) return;

        const ok = await copyText(url);
        flashButton(btn, {
            okLabel: 'URL copiada!',
            idleLabel: 'Copiar URL do feed',
            ok,
        });
    });
}

export function init(root = document) {
    const page = root.querySelector('[data-partner-feed-index]');
    if (!page || page.dataset.pfIndexInit === '1') return;
    page.dataset.pfIndexInit = '1';

    initCopyFeedUrl(page);
}

export default init;
