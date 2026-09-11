export function refreshTable(table) {
    table?.dispatchEvent(new CustomEvent('table:refresh', { bubbles: true }));
}
