import { qs } from '../core/dom.js';
import { initTables } from '../components/table.js';

export function initDashboard(root = document) {
    const page = qs('[data-page="dashboard"]', root) || qs('.dashboard-page', root);
    if (!page) return;
    initTables(page);
    page.dispatchEvent(new CustomEvent('dashboard:ready', { bubbles: true }));
}
