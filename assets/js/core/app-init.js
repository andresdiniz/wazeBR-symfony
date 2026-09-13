import './csrf.js';
import './dom.js';
import './http-client.js';
import './modal.js';
import './notifications.js';
import './theme.js';

import '../layout/base-layout.js';
import '../layout/header.js';
import '../layout/sidebar.js';
import '../layout/footer.js';

import '../components/accordions.js';
import '../components/charts.js';
import '../components/expand-buttons.js';
import '../components/filters.js';
import '../components/map.js';
import '../components/menus.js';
import '../components/notifications.js';
import '../components/pagination.js';
import '../components/table.js';

let initialized = false;

export function initApp(document = globalThis.document) {
  if (initialized || !document) return;
  initialized = true;

  const init = () => {
    document.documentElement.classList.add('app-ready');

    document.dispatchEvent(new CustomEvent('wazebr:ready'));
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
}

initApp();
