import { initApp } from '../core/app-init.js';

export function initFooter(document) {
  initApp(document);

  const year = document.querySelector('[data-current-year]');
  if (year) year.textContent = String(new Date().getFullYear());
}
