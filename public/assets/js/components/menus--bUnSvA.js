export function initMenus(document = globalThis.document) {
  if (!document) return;

  document.querySelectorAll('[data-menu-toggle]').forEach((toggle) => {
    if (toggle.dataset.wazebrInitialized === 'true') return;
    toggle.dataset.wazebrInitialized = 'true';

    toggle.addEventListener('click', () => {
      const target = document.querySelector(toggle.dataset.menuToggle);
      target?.classList.toggle('is-open');
    });
  });
}
