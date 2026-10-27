(function () {
  'use strict';

  document.addEventListener('click', async function (event) {
    const button = event.target.closest('[data-copy-feed-url]');
    if (!button) return;

    const url = button.dataset.copyFeedUrl;
    if (!url) return;

    const originalLabel = button.getAttribute('aria-label') || 'Copiar URL do feed';

    try {
      await navigator.clipboard.writeText(url);
      button.classList.add('is-copied');
      button.setAttribute('aria-label', 'URL copiada!');

      window.setTimeout(function () {
        button.classList.remove('is-copied');
        button.setAttribute('aria-label', originalLabel);
      }, 2000);
    } catch (error) {
      console.error('Não foi possível copiar a URL do feed.', error);
    }
  });
})();