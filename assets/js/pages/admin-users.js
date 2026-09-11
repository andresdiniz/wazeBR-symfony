import { qs } from '../core/dom.js';
import { initTables } from '../components/table.js';

export function initAdminUsers(root = document) {
    if (!qs('[data-page="admin-users"]', root) && !qs('.admin-users-page', root)) return;
    initTables(root);
}
