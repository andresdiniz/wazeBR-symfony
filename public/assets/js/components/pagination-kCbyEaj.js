/**
 * components/pagination.js
 * Paginação genérica para listas com [data-pagination] + [data-list].
 * Recalcula automaticamente quando o filtro é aplicado.
 */
export function initPagination(doc = globalThis.document) {
    if (!doc) return;

    doc.querySelectorAll('[data-pagination]').forEach((pagination) => {
        if (pagination.dataset.wazebrInitialized === 'true') return;
        pagination.dataset.wazebrInitialized = 'true';

        const listKey = pagination.dataset.pagination;
        const list    = doc.querySelector(`[data-list="${listKey}"]`);
        if (!list) return;

        const PAGE_SIZE = 5;
        let page = 1;

        const render = () => {
            const rows    = [...list.querySelectorAll('.dashboard-data-row')];
            const visible = rows.filter((r) => r.dataset.filtered !== 'true');
            const pages   = Math.max(1, Math.ceil(visible.length / PAGE_SIZE));
            page          = Math.min(page, pages);

            rows.forEach((row) => {
                const inPage = visible.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE).includes(row);
                row.hidden   = row.dataset.filtered === 'true' || !inPage;
            });

            pagination.innerHTML = pages <= 1 ? '' :
                Array.from({ length: pages }, (_, i) =>
                    `<button type="button" class="${i + 1 === page ? 'is-active' : ''}" data-page="${i + 1}" aria-label="Página ${i + 1}">${i + 1}</button>`,
                ).join('');

            pagination.querySelectorAll('[data-page]').forEach((btn) =>
                btn.addEventListener('click', () => { page = Number(btn.dataset.page); render(); }),
            );
        };

        // Expõe para o expand-buttons e filtros
        pagination._render = render;
        render();
    });

    // Recalcula quando filtro emitir evento
    doc.addEventListener('wazebr:filter-applied', () => {
        doc.querySelectorAll('[data-pagination]').forEach((p) => {
            if (typeof p._render === 'function') p._render();
        });
    });
}
