import '../core/theme.js';
import '../components/menus.js';
import '../components/notifications.js';

export function initHeader(document) {
  const header = document.querySelector('[data-header]');
  if (!header) return;

  header.querySelectorAll('[data-menu-toggle]').forEach((toggle) => {
    toggle.addEventListener('click', () => {
      const target = document.querySelector(toggle.dataset.menuToggle);
      target?.classList.toggle('is-open');
    });
  });
}
