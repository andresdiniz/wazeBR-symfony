import { qsa } from '../core/dom.js';

export function initTables(root = document) {
    qsa('[data-table]', root).forEach((table) => {
        table.dispatchEvent(new CustomEvent('table:ready', { bubbles: true }));
    });
}
