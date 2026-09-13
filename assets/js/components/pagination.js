export function initPagination(root = document, pageSize = 5) {
    root.querySelectorAll('[data-pagination]').forEach((pagination) => {
        if (pagination.dataset.initialized === 'true') return;
        pagination.dataset.initialized = 'true';
        const list = root.querySelector(`[data-list="${pagination.dataset.pagination}"]`);
        if (!list) return;
        const rows = [...list.querySelectorAll('.dashboard-data-row')];
        let page = 1;
        const render = () => {
            const visible = rows.filter((row) => row.dataset.filtered !== 'true');
            const pages = Math.max(1, Math.ceil(visible.length / pageSize));
            page = Math.min(page, pages);
            const current = new Set(visible.slice((page - 1) * pageSize, page * pageSize));
            rows.forEach((row) => { row.hidden = row.dataset.filtered === 'true' || !current.has(row); });
            pagination.innerHTML = pages <= 1 ? '' : Array.from({ length: pages }, (_, index) => `<button type="button" class="${index + 1 === page ? 'is-active' : ''}" data-page="${index + 1}">${index + 1}</button>`).join('');
            pagination.querySelectorAll('[data-page]').forEach((button) => button.addEventListener('click', () => { page = Number(button.dataset.page); render(); }));
        };
        pagination._render = render;
        rows.forEach((row) => { row.dataset.filtered = row.dataset.filtered || 'false'; });
        render();
    });
}
