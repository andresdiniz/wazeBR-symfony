
import { initBaseLayout } from '../layout/base-layout.js';
import { initHeader } from '../layout/header.js';
import { initTables } from '../components/table.js';
import { initLogin } from '../pages/login.js';
import { initResetPassword } from '../pages/reset-password.js';
import { initDashboard } from '../pages/dashboard.js';
import { initAdminUsers } from '../pages/admin-users.js';
import { initCifs } from '../pages/cifs.js';

export function initApp(root = document) {
    initBaseLayout(root);
    initHeader(root);
    initTables(root);
    initLogin(root);
    initResetPassword(root);
    initDashboard(root);
    initAdminUsers(root);
    initCifs(root);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => initApp());
} else {
    initApp();
}
