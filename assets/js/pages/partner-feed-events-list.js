/**
 * pages/partner-feed-events-list.js — Lista de eventos do parceiro.
 */

export function initPartnerFeedEventsList(root = document) {
    const page = root.querySelector('[data-partner-events-list]');

    if (!page || page.dataset.partnerEventsListInit === '1') {
        return;
    }

    page.dataset.partnerEventsListInit = '1';

    const rows = [...page.querySelectorAll('[data-event-row]')];
    const searchInput = page.querySelector('[data-event-search]');
    const statusFilter = page.querySelector('[data-event-status-filter]');
    const typeFilter = page.querySelector('[data-event-type-filter]');
    const clearButton = page.querySelector('[data-clear-filters]');
    const countElement = page.querySelector('[data-visible-count]');
    const noResults = page.querySelector('[data-no-results]');

    const normalize = (value) => (value || '').toString().trim().toLowerCase();

    function applyFilters() {
        const query = normalize(searchInput?.value);
        const status = normalize(statusFilter?.value || 'all');
        const type = normalize(typeFilter?.value || 'all');
        let visibleCount = 0;

        rows.forEach((row) => {
            const matchesQuery = !query || normalize(row.dataset.eventSearchText).includes(query);
            const matchesStatus = status === 'all' || row.dataset.eventStatus === status;
            const matchesType = type === 'all' || row.dataset.eventType === type;
            const visible = matchesQuery && matchesStatus && matchesType;

            row.hidden = !visible;
            if (visible) visibleCount += 1;
        });

        if (countElement) countElement.textContent = visibleCount;
        if (noResults) noResults.hidden = visibleCount !== 0;
    }

    searchInput?.addEventListener('input', applyFilters);
    statusFilter?.addEventListener('change', applyFilters);
    typeFilter?.addEventListener('change', applyFilters);

    clearButton?.addEventListener('click', () => {
        if (searchInput) searchInput.value = '';
        if (statusFilter) statusFilter.value = 'all';
        if (typeFilter) typeFilter.value = 'all';
        applyFilters();
        searchInput?.focus();
    });

    page.querySelectorAll('[data-delete-form]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm('Tem certeza que deseja excluir este evento? Essa ação não pode ser desfeita.')) {
                event.preventDefault();
            }
        });
    });

    applyFilters();
}

export default initPartnerFeedEventsList;
