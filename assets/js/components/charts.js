export function initCharts(document = globalThis.document) {
  if (!document) return;

  document.querySelectorAll('[data-chart]').forEach((chart) => {
    chart.dataset.wazebrInitialized = 'true';
  });
}
