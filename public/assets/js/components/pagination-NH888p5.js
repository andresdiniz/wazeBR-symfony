export function initPagination(document = globalThis.document) {
  if (!document) return;

  document.querySelectorAll('[data-pagination]').forEach((pagination) => {
    pagination.dataset.wazebrInitialized = 'true';
  });
}
