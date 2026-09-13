export function initFilters(document = globalThis.document) {
  if (!document) return;

  document.querySelectorAll('[data-filter]').forEach((filter) => {
    filter.dataset.wazebrInitialized = 'true';
  });
}
