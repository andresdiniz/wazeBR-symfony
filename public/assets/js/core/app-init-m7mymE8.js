alert('App initialized');

import '../app.js';

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

export function initApp(document = globalThis.document) {
  if (initialized || !document) return;

  const start = () => {
    if (initialized) return;
    initialized = true;

    initBaseLayout(document);
    initHeader(document);
    initSidebar(document);
    initFooter(document);

    initAccordions(document);
    initCharts(document);
    initExpandButtons(document);
    initFilters(document);
    initMap(document);
    initMenus(document);
    initNotifications(document);
    initPagination(document);
    initTable(document);

    document.documentElement.classList.add('app-ready');
    document.dispatchEvent(new CustomEvent('wazebr:ready'));
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
  } else {
    start();
  }
}

initApp();
