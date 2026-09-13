export function initAccordions(document = globalThis.document) {
  if (!document) return;

  document.querySelectorAll('[data-accordion]').forEach((accordion) => {
    if (accordion.dataset.wazebrInitialized === 'true') return;
    accordion.dataset.wazebrInitialized = 'true';
  });
}
