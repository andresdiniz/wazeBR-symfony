// assets/js/pages/partner-feed-index.js

'use strict';

/**
 * Copia a URL do feed JSON de um parceiro e fornece feedback visual no botão.
 */
function initCopyFeedUrl(root = document) {
    root.querySelectorAll('[data-copy-feed-url]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const url = btn.dataset.copyFeedUrl;
            if (!url) return;

            try {
                await navigator.clipboard.writeText(url);
                btn.dataset.copied = 'true';
                btn.setAttribute('aria-label', 'URL copiada!');

                setTimeout(() => {
                    delete btn.dataset.copied;
                    btn.setAttribute('aria-label', 'Copiar URL do feed');
                }, 2000);
            } catch {
                // Fallback para ambientes sem Clipboard API (HTTP sem HTTPS)
                const tmp = document.createElement('textarea');
                tmp.value = url;
                tmp.style.cssText = 'position:absolute;left:-9999px;top:-9999px';
                document.body.appendChild(tmp);
                tmp.select();
                document.execCommand('copy');
                document.body.removeChild(tmp);

                btn.dataset.copied = 'true';
                btn.setAttribute('aria-label', 'URL copiada!');
                setTimeout(() => {
                    delete btn.dataset.copied;
                    btn.setAttribute('aria-label', 'Copiar URL do feed');
                }, 2000);
            }
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initCopyFeedUrl();
});
