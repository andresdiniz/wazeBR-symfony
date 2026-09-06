/**
 * wazeBR - Application Initialization
 */

import { initSidebar } from '../components/sidebar.js';
import { initNavbar } from '../components/navbar.js';
import { initNotifications } from '../components/notifications.js';
import { initModals } from '../components/modals.js';
import { initTables } from '../components/tables.js';
import { initForms } from '../components/forms.js';
import { helpers } from '../utils/helpers.js';
import { storage } from '../utils/storage.js';

export function initApp(config) {
  console.log(`[wazeBR] Initializing v${config.appVersion}...`);
  storage.init(config.storage);
  initSidebar(config);
  initNavbar(config);
  initNotifications(config);
  initModals(config);
  initTables(config);
  initForms(config);
  initPageScripts();
  setupErrorHandler();
  setupDarkMode();
  console.log('[wazeBR] Application initialized successfully');
}

function initPageScripts() {
  const page = document.body.dataset.page;
  if (!page) return;
  console.log(`[wazeBR] Initializing page: ${page}`);
  switch (page) {
    case 'dashboard':
      import('../pages/dashboard.js').then(module => module.initDashboard());
      break;
    case 'alerts':
      import('../pages/alerts.js').then(module => module.initAlerts());
      break;
    case 'traffic':
      import('../pages/traffic.js').then(module => module.initTraffic());
      break;
    case 'hydro':
      import('../pages/hydro.js').then(module => module.initHydro());
      break;
    case 'map':
      import('../components/maps.js').then(module => module.initMap());
      break;
    case 'charts':
      import('../components/charts.js').then(module => module.initCharts());
      break;
  }
}

function setupErrorHandler() {
  window.addEventListener('error', (event) => {
    console.error('[wazeBR] Global error:', event.error);
  });
  window.addEventListener('unhandledrejection', (event) => {
    console.error('[wazeBR] Unhandled promise rejection:', event.reason);
  });
}

function setupDarkMode() {
  const darkMode = localStorage.getItem('wazebr_darkMode');
  const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
  if (darkMode === 'true' || (darkMode === null && prefersDark)) {
    document.documentElement.setAttribute('data-theme', 'dark');
  }
}

export default initApp;
