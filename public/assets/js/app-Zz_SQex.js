import { initBaseLayout } from './layout/base-layout.js';
import { initHeader } from './layout/header.js';

import { initTables } from './components/table.js';
import { initFilters } from './components/filters.js';
import { initMap } from './components/map.js';
import { initCharts } from './components/charts.js';

import { initDashboard } from './pages/dashboard.js';
import { initLogin } from './pages/login.js';
import { initResetPassword } from './pages/reset-password.js';
import { initAdminUsers } from './pages/admin-users.js';
import { initCifs } from './pages/cifs.js';
import { initLanding } from './pages/landing.js';

function boot() {
    const root = document;

    initBaseLayout(root);
    initHeader(root);

    initTables(root);
    initFilters(root);
    initMap(root);
    initCharts(root);

    initDashboard(root);
    initLogin(root);
    initResetPassword(root);
    initAdminUsers(root);
    initCifs(root);
    initLanding(root);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
    boot();
}
