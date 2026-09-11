import { initBaseLayout } from '../layout/base-layout.js';
import { initHeader } from '../layout/header.js';
import { initTables } from '../components/table.js';
import { qs } from '../core/dom.js';

export function initDashboard(root = document) {
    if (!qs('[data-page="dashboard"]', root) && !qs('.dashboard-page', root)) return;
    initBaseLayout(root);
    initHeader(root);
    initTables(root);
}
