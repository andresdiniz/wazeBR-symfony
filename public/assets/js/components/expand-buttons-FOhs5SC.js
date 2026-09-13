export function initExpandButtons(document = globalThis.document) {
  if (!document) return;

  document.querySelectorAll('[data-expand-button]').forEach((button) => {
    if (button.dataset.wazebrInitialized === 'true') return;
    button.dataset.wazebrInitialized = 'true';

    button.addEventListener('click', () => {
      const selector = button.dataset.expandButton;
      const target = document.querySelector(selector);
      target?.classList.toggle('is-expanded');
    });
  });
}
