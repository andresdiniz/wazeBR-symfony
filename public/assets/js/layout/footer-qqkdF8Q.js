/**
 * WazeBR — Footer
 *
 * Responsabilidades:
 * - Atualizar automaticamente o ano
 * - Evitar inicialização duplicada
 */

export function initFooter(document = globalThis.document) {
  if (!document) return;

  const footer = document.querySelector(
    '[data-component="footer"]'
  );

  if (!footer) return;

  if (footer.dataset.wazebrInitialized === 'true') {
    return;
  }

  footer.dataset.wazebrInitialized = 'true';

  const currentYear = footer.querySelector(
    '[data-current-year]'
  );

  if (currentYear) {
    currentYear.textContent = String(
      new Date().getFullYear()
    );
  }
}
