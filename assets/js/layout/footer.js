export function initFooter(document = globalThis.document) {
  if (!document) return;

  const year = document.querySelector('[data-current-year]');
  if (year) year.textContent = String(new Date().getFullYear());
}
