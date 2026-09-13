export function initMap(document = globalThis.document) {
  if (!document) return;

  document.querySelectorAll('[data-map]').forEach((map) => {
    map.dataset.wazebrInitialized = 'true';
  });
}
