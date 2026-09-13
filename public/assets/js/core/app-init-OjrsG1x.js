import './csrf.js';
import './dom.js';
import './http-client.js';
import './modal.js';
import './notifications.js';
import './theme.js';

import { initBaseLayout } from '../layout/base-layout.js';
import { initFooter } from '../layout/footer.js';
import { initHeader } from '../layout/header.js';
import { initSidebar } from '../layout/sidebar.js';

import { initAccordions } from '../components/accordions.js';
import { initCharts } from '../components/charts.js';
import { initExpandButtons } from '../components/expand-buttons.js';
import { initFilters } from '../components/filters.js';
import { initMap } from '../components/map.js';
import { initMenus } from '../components/menus.js';
import { initNotifications } from '../components/notifications.js';
import { initPagination } from '../components/pagination.js';
import { initTable } from '../components/table.js';

let initialized = false;

export function init(doc = globalThis.document) {
    console.debug('Initializing WazeBR App UI...');
  if (initialized || !doc) return;

  initialized = true;

  console.log('🚀 Inicializando WazeBR App UI...');

  initBaseLayout(doc);
  initHeader(doc);
  initSidebar(doc);
  initFooter(doc);

  initAccordions(doc);
  initCharts(doc);
  initExpandButtons(doc);
  initFilters(doc);
  initMap(doc);
  initMenus(doc);
  initNotifications(doc);
  initPagination(doc);
  initTable(doc);

  doc.documentElement.classList.add('app-ready');
  doc.dispatchEvent(new CustomEvent('wazebr:ready'));
}

function start() {
  if (globalThis.document.readyState === 'loading') {
    globalThis.document.addEventListener('DOMContentLoaded', () => init(), { once: true });
  } else {
    init();
  }
}

start();
