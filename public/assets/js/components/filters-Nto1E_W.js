/**
 * components/filters.js
 * Filtros genéricos + filtro do dashboard (query, section, period).
 */
export function initFilters(doc = globalThis.document) {
    if (!doc) return;

    // ── Filtros genéricos [data-filter-form] ─────────────────────────────────
    doc.querySelectorAll('[data-filter-form]').forEach((form) => {
        if (form.dataset.wazebrInitialized === 'true') return;
        form.dataset.wazebrInitialized = 'true';

        const fields   = form.querySelectorAll('[data-filter]');
        const feedback = form.querySelector('[data-filter-feedback]');

        fields.forEach((f) => f.addEventListener('input',  () => applyGenericFilter(form, fields, feedback)));
        fields.forEach((f) => f.addEventListener('change', () => applyGenericFilter(form, fields, feedback)));

        form.querySelector('[data-action="apply-filters"]')
            ?.addEventListener('click', () => applyGenericFilter(form, fields, feedback));

        form.querySelectorAll('[data-action="clear-filters"]').forEach((btn) =>
            btn.addEventListener('click', () => {
                fields.forEach((f) => { f.tagName === 'SELECT' ? (f.value = f.options[0]?.value ?? '') : (f.value = ''); });
                applyGenericFilter(form, fields, feedback);
            }),
        );
    });

    // ── Dashboard: filtros globais sem [data-filter-form] ────────────────────
    // (o dashboard usa fields soltos dentro de .dashboard-filter-panel)
    const dashboard = doc.querySelector('[data-dashboard]');
    if (dashboard && dashboard.dataset.filtersInitialized !== 'true') {
        dashboard.dataset.filtersInitialized = 'true';
        initDashboardFilters(dashboard, doc);
    }
}

// ── Dashboard filters ─────────────────────────────────────────────────────────

function initDashboardFilters(dashboard, doc) {
    const queryField   = dashboard.querySelector('[data-filter="query"]');
    const sectionField = dashboard.querySelector('[data-filter="section"]');
    const periodField  = dashboard.querySelector('[data-filter="period"]');
    const feedback     = dashboard.querySelector('[data-filter-feedback]');

    if (!queryField && !sectionField && !periodField) return;

    const apply = () => applyDashboardFilter(dashboard, queryField, sectionField, periodField, feedback);

    [queryField, sectionField, periodField].forEach((f) => {
        f?.addEventListener('input', apply);
        f?.addEventListener('change', apply);
    });

    dashboard.querySelectorAll('[data-action="apply-filters"]').forEach((b) => b.addEventListener('click', apply));
    dashboard.querySelectorAll('[data-action="clear-filters"]').forEach((b) =>
        b.addEventListener('click', () => {
            if (queryField)   queryField.value   = '';
            if (sectionField) sectionField.value = sectionField.options[0]?.value ?? 'all';
            if (periodField)  periodField.value  = periodField.options[0]?.value ?? 'all';
            apply();
        }),
    );
}

function applyDashboardFilter(dashboard, queryField, sectionField, periodField, feedback) {
    const query   = (queryField?.value  ?? '').trim().toLowerCase();
    const section = sectionField?.value ?? 'all';
    const period  = periodField?.value  ?? 'all';

    let visible = 0;

    dashboard.querySelectorAll('.data-section').forEach((panel) => {
        const enabled = section === 'all' || panel.dataset.section === section;
        panel.hidden = !enabled;
        if (!enabled) return;

        panel.querySelectorAll('.dashboard-data-row').forEach((row) => {
            const textOk   = !query || (row.dataset.searchText ?? '').includes(query);
            const periodOk = matchesPeriod(row.dataset.date ?? '', period);
            const show     = textOk && periodOk;

            row.dataset.filtered = show ? 'false' : 'true';
            row.hidden            = !show;
            if (show) visible++;
        });
    });

    if (feedback) {
        feedback.textContent = (query || section !== 'all' || period !== 'all')
            ? `${visible} registro(s) encontrado(s).`
            : '';
    }

    // Notifica paginação para recalcular
    dashboard.dispatchEvent(new CustomEvent('wazebr:filter-applied', { bubbles: true }));
}

function matchesPeriod(dateStr, period) {
    if (period === 'all' || !dateStr) return true;
    const d   = new Date(dateStr);
    const now = new Date();
    if (period === 'today') return d.toDateString() === now.toDateString();
    const days = period === 'week' ? 7 : 30;
    const ago  = new Date(now); ago.setDate(ago.getDate() - days);
    return d >= ago;
}

// ── Filtro genérico ───────────────────────────────────────────────────────────

function applyGenericFilter(form, fields, feedback) {
    const query = [...fields]
        .filter((f) => f.dataset.filter === 'query')
        .map((f) => f.value.trim().toLowerCase())[0] ?? '';

    let visible = 0;
    form.querySelectorAll('[data-searchable]').forEach((row) => {
        const match = !query || (row.dataset.searchText ?? row.textContent).toLowerCase().includes(query);
        row.hidden = !match;
        if (match) visible++;
    });

    if (feedback) feedback.textContent = query ? `${visible} resultado(s).` : '';
}
