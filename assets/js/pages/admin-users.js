import { qs } from '../core/dom.js';
import { initTables } from '../components/table.js';

export function initAdminUsers(root = document) {
    const page = qs('[data-page="admin-users"]', root) || qs('.admin-users-page', root);
    if (!page) return;
    initTables(page);
    page.dispatchEvent(new CustomEvent('admin-users:ready', { bubbles: true }));
}
