export function initBaseLayout(document = globalThis.document) {
  if (!document) return;
  document.documentElement.classList.add('app-layout-ready');
}
