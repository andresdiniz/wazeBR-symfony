(function () {
  'use strict';

  document.addEventListener('click', async function (event) {
    const button = event.target.closest('[data-copy-json]');
    if (!button) return;

    const target = document.getElementById(button.dataset.copyJson);
    if (!target) return;

    try {
      await navigator.clipboard.writeText(target.textContent.trim());
      button.classList.add('is-copied');

      window.setTimeout(function () {
        button.classList.remove('is-copied');
      }, 2000);
    } catch (error) {
      console.error('Não foi possível copiar o JSON.', error);
    }
  });
})();