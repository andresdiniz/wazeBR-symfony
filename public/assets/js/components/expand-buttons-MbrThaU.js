/**
 * components/expand-buttons.js
 * Botões [data-expand] para expandir/recolher listas do dashboard.
 */
export function initExpandButtons(doc = globalThis.document) {
    if (!doc) return;

    doc.querySelectorAll('[data-expand]').forEach((btn) => {
        if (btn.dataset.wazebrInitialized === 'true') return;
        btn.dataset.wazebrInitialized = 'true';

        btn.addEventListener('click', () => {
            const list = doc.querySelector(`[data-list="${btn.dataset.expand}"]`);
            if (!list) return;

            const isExpanded = btn.dataset.expanded === 'true';
            list.querySelectorAll('.dashboard-data-row').forEach((row) => {
                if (row.dataset.filtered !== 'true') row.hidden = isExpanded;
            });

            btn.dataset.expanded = isExpanded ? 'false' : 'true';
            btn.textContent      = isExpanded ? 'Ver todos →' : 'Recolher ↑';

            // Recalcula paginação após expandir/recolher
            const pagination = doc.querySelector(`[data-pagination="${btn.dataset.expand}"]`);
            if (typeof pagination?._render === 'function') pagination._render();
        });
    });
}
