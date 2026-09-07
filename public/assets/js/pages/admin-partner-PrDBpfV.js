document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('[data-partner-index]');
    if (!root) return;

    const search = root.querySelector('[data-partner-search]');
    const rows = [...root.querySelectorAll('[data-partner-row]')];
    const empty = root.querySelector('[data-partner-empty-filter]');

    search?.addEventListener('input', () => {
        const term = search.value.trim().toLowerCase();
        let visible = 0;

        rows.forEach((row) => {
            const matches = !term || (row.dataset.search || '').includes(term);
            row.hidden = !matches;
            if (matches) visible++;
        });

        if (empty) empty.hidden = visible !== 0;
    });

    root.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirm)) event.preventDefault();
        });
    });
});
