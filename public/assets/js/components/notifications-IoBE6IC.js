export function initNotifications(document = globalThis.document) {
  if (!document) return;

  document.querySelectorAll('[data-notification]').forEach((notification) => {
    notification.dataset.wazebrInitialized = 'true';
  });
}
