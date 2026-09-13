export function initTable(document = globalThis.document) {
  if (!document) return;

  document.querySelectorAll('[data-table]').forEach((table) => {
    table.dataset.wazebrInitialized = 'true';
  });
}
