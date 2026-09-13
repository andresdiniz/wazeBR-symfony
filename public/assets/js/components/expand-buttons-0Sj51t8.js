export function initExpandButtons(root = document) {
    root.querySelectorAll('[data-expand]').forEach((button) => {
        if (button.dataset.initialized === 'true') return;
        button.dataset.initialized = 'true';
        button.addEventListener('click', () => {
            const list = root.querySelector(`[data-list="${button.dataset.expand}"]`);
            if (!list) return;
            const expanded = button.dataset.expanded === 'true';
            list.querySelectorAll('.dashboard-data-row').forEach((row) => { row.hidden = expanded; });
            button.dataset.expanded = expanded ? 'false' : 'true';
            button.textContent = expanded ? 'Ver todos os registros →' : 'Recolher lista ↑';
        });
    });
}
