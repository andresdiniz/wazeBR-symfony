(function () {
  'use strict';

  const page = document.querySelector('[data-partner-events-list]');
  if (!page) return;

  const rows = Array.from(page.querySelectorAll('[data-event-row]'));
  const search = page.querySelector('[data-event-search]');
  const statusFilter = page.querySelector('[data-event-status-filter]');
  const typeFilter = page.querySelector('[data-event-type-filter]');
  const clearButton = page.querySelector('[data-clear-filters]');
  const visibleCount = page.querySelector('[data-visible-count]');
  const noResultsRow = page.querySelector('[data-no-results]');

  function applyFilters() {
    const query = (search && search.value ? search.value : '').trim().toLowerCase();
    const status = statusFilter && statusFilter.value ? statusFilter.value : 'all';
    const type = typeFilter && typeFilter.value ? typeFilter.value : 'all';
    let visible = 0;

    rows.forEach(function (row) {
      const matchesQuery = !query || (row.dataset.eventSearchText || '').includes(query);
      const matchesStatus = status === 'all' || row.dataset.eventStatus === status;
      const matchesType = type === 'all' || row.dataset.eventType === type;
      const shouldShow = matchesQuery && matchesStatus && matchesType;

      row.hidden = !shouldShow;
      if (shouldShow) visible += 1;
    });

    if (visibleCount) visibleCount.textContent = String(visible);
    if (noResultsRow) noResultsRow.hidden = visible !== 0;
  }

  [search, statusFilter, typeFilter].forEach(function (element) {
    if (!element) return;
    element.addEventListener('input', applyFilters);
    element.addEventListener('change', applyFilters);
  });

  if (clearButton) {
    clearButton.addEventListener('click', function () {
      if (search) search.value = '';
      if (statusFilter) statusFilter.value = 'all';
      if (typeFilter) typeFilter.value = 'all';
      applyFilters();
      if (search) search.focus();
    });
  }

  page.addEventListener('submit', function (event) {
    const deleteForm = event.target.closest('[data-delete-form]');
    if (deleteForm && !window.confirm('Excluir este evento? Esta ação não pode ser desfeita.')) {
      event.preventDefault();
    }
  });

  applyFilters();
})();