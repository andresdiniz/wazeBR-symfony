export function initHeader(document = globalThis.document) {
  if (!document) return;

  const header = document.querySelector('[data-header]');
  if (!header) return;

  header.querySelectorAll('[data-menu-toggle]').forEach((toggle) => {
    if (toggle.dataset.wazebrInitialized === 'true') return;
    toggle.dataset.wazebrInitialized = 'true';

    toggle.addEventListener('click', () => {
      const target = document.querySelector(toggle.dataset.menuToggle);
      target?.classList.toggle('is-open');
    });
  });
}
