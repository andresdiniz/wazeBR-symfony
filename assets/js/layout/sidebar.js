export function initSidebar(document = globalThis.document) {
  if (!document) return;

  const sidebar = document.querySelector('[data-sidebar]');
  if (!sidebar) return;

  sidebar.querySelectorAll('[data-sidebar-toggle]').forEach((toggle) => {
    if (toggle.dataset.wazebrInitialized === 'true') return;
    toggle.dataset.wazebrInitialized = 'true';

    toggle.addEventListener('click', () => {
      const target = document.querySelector(toggle.dataset.sidebarToggle);
      target?.classList.toggle('is-open');
    });
  });
}
