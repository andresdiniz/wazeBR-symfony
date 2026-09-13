import '../components/menus.js';
import '../components/accordions.js';

export function initSidebar(document) {
  const sidebar = document.querySelector('[data-sidebar]');
  if (!sidebar) return;

  sidebar.querySelectorAll('[data-sidebar-toggle]').forEach((toggle) => {
    toggle.addEventListener('click', () => {
      const target = document.querySelector(toggle.dataset.sidebarToggle);
      target?.classList.toggle('is-open');
    });
  });
}
