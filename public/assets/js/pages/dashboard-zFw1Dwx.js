/**
 * dashboard.js
 *
 * Inicializa todos os módulos do dashboard.
 * Sem Twig inline — todos os dados são lidos de atributos data-* do DOM.
 */

// ── Bootstrap ────────────────────────────────────────────────────────────────

export function initDashboard(root = document) {
    const dashboard = root.querySelector('[data-dashboard]');
    if (!dashboard || dashboard.dataset.initialized === 'true') return;
    dashboard.dataset.initialized = 'true';

    initClock(dashboard);
    initCounters(dashboard);
    initFilters(dashboard);
    initRefresh(dashboard);
    initPagination(dashboard);
    initExpandButtons(dashboard);
    initChart(dashboard);
}

document.addEventListener('DOMContentLoaded', () => initDashboard());

// ── Clock ─────────────────────────────────────────────────────────────────────

function initClock(dashboard) {
    const clock = dashboard.querySelector('[data-dashboard-clock]');
    if (!clock) return;

    const fmt = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short' });
    const update = () => { clock.textContent = fmt.format(new Date()); };

    update();
    window.setInterval(update, 30_000);
}

// ── Counters animados ─────────────────────────────────────────────────────────

function initCounters(dashboard) {
    dashboard.querySelectorAll('[data-count-value]').forEach((el) => {
        const target = Number(el.dataset.countValue ?? 0);
        if (!Number.isFinite(target)) return;

        const start = performance.now();
        const tick = (now) => {
            const progress = Math.min((now - start) / 700, 1);
            el.textContent = String(Math.round(target * (1 - Math.pow(1 - progress, 3))));
            if (progress < 1) window.requestAnimationFrame(tick);
        };
        window.requestAnimationFrame(tick);
    });
}

// ── Filtros ───────────────────────────────────────────────────────────────────

function initFilters(dashboard) {
    const queryField   = dashboard.querySelector('[data-filter="query"]');
    const sectionField = dashboard.querySelector('[data-filter="section"]');
    const periodField  = dashboard.querySelector('[data-filter="period"]');
    const feedback     = dashboard.querySelector('[data-filter-feedback]');

    const getAllRows = () => dashboard.querySelectorAll('.dashboard-data-row');

    /** Retorna true se a data da row está dentro do período selecionado. */
    const matchesPeriod = (row, period) => {
        if (period === 'all') return true;

        const dateStr = row.dataset.date ?? '';
        if (!dateStr) return true; // sem data → não filtra

        const rowDate = new Date(dateStr);
        const now     = new Date();

        if (period === 'today') {
            return rowDate.toDateString() === now.toDateString();
        }
        if (period === 'week') {
            const weekAgo = new Date(now);
            weekAgo.setDate(weekAgo.getDate() - 7);
            return rowDate >= weekAgo;
        }
        if (period === 'month') {
            const monthAgo = new Date(now);
            monthAgo.setDate(monthAgo.getDate() - 30);
            return rowDate >= monthAgo;
        }

        return true;
    };

    const apply = () => {
        const query   = (queryField?.value ?? '').trim().toLowerCase();
        const section = sectionField?.value ?? 'all';
        const period  = periodField?.value ?? 'all';

        let visible = 0;

        // Mostra/esconde seções inteiras
        dashboard.querySelectorAll('.data-section').forEach((panel) => {
            const enabled = section === 'all' || panel.dataset.section === section;
            panel.hidden = !enabled;
            if (!enabled) return;

            panel.querySelectorAll('.dashboard-data-row').forEach((row) => {
                const textMatch   = !query || (row.dataset.searchText ?? '').includes(query);
                const periodMatch = matchesPeriod(row, period);
                const show        = textMatch && periodMatch;

                row.dataset.filtered = show ? 'false' : 'true';
                row.hidden            = !show;
                if (show) visible += 1;
            });
        });

        if (feedback) {
            feedback.textContent = (query || section !== 'all' || period !== 'all')
                ? `${visible} registro(s) encontrado(s).`
                : '';
        }

        resetPagination(dashboard);
    };

    // Listeners
    [queryField, sectionField, periodField].forEach((field) => {
        field?.addEventListener('input', apply);
        field?.addEventListener('change', apply);
    });

    dashboard.querySelectorAll('[data-action="apply-filters"]').forEach((btn) =>
        btn.addEventListener('click', apply),
    );

    dashboard.querySelectorAll('[data-action="clear-filters"]').forEach((btn) =>
        btn.addEventListener('click', () => {
            if (queryField)   queryField.value   = '';
            if (sectionField) sectionField.value = 'all';
            if (periodField)  periodField.value  = 'all';
            apply();
        }),
    );
}

// ── Refresh ───────────────────────────────────────────────────────────────────

function initRefresh(dashboard) {
    dashboard.querySelector('[data-action="refresh-dashboard"]')?.addEventListener('click', (e) => {
        const btn = e.currentTarget;
        btn.classList.add('is-loading');
        btn.setAttribute('aria-busy', 'true');
        btn.setAttribute('aria-label', 'Recarregando…');
        window.location.reload();
    });
}

// ── Paginação ─────────────────────────────────────────────────────────────────

function initPagination(dashboard) {
    dashboard.querySelectorAll('[data-pagination]').forEach((pagination) => {
        const section = pagination.dataset.pagination;
        const list    = dashboard.querySelector(`[data-list="${section}"]`);
        if (!list) return;

        const rows    = [...list.querySelectorAll('.dashboard-data-row')];
        const PAGE_SIZE = 5;
        let page = 1;

        const render = () => {
            const visible = rows.filter((row) => row.dataset.filtered !== 'true');
            const pages   = Math.max(1, Math.ceil(visible.length / PAGE_SIZE));
            page          = Math.min(page, pages);

            rows.forEach((row) => {
                const inPage = visible.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE).includes(row);
                row.hidden = row.dataset.filtered === 'true' || !inPage;
            });

            pagination.innerHTML = pages <= 1
                ? ''
                : Array.from({ length: pages }, (_, i) =>
                    `<button type="button" class="${i + 1 === page ? 'is-active' : ''}" data-page="${i + 1}" aria-label="Página ${i + 1}">${i + 1}</button>`,
                ).join('');

            pagination.querySelectorAll('[data-page]').forEach((btn) =>
                btn.addEventListener('click', () => { page = Number(btn.dataset.page); render(); }),
            );
        };

        pagination._render = render;
        render();
    });
}

function resetPagination(dashboard) {
    dashboard.querySelectorAll('[data-pagination]').forEach((p) => {
        if (typeof p._render === 'function') p._render();
    });
}

// ── Expand ────────────────────────────────────────────────────────────────────

function initExpandButtons(dashboard) {
    dashboard.querySelectorAll('[data-expand]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const list = dashboard.querySelector(`[data-list="${btn.dataset.expand}"]`);
            if (!list) return;

            const isExpanded = btn.dataset.expanded === 'true';
            list.querySelectorAll('.dashboard-data-row').forEach((row) => {
                if (row.dataset.filtered !== 'true') row.hidden = isExpanded;
            });

            btn.dataset.expanded = isExpanded ? 'false' : 'true';
            btn.textContent = isExpanded ? `Ver todos →` : 'Recolher ↑';
        });
    });
}

// ── Chart (dual line — alertas e jams) ───────────────────────────────────────

function initChart(dashboard) {
    const container = dashboard.querySelector('[data-dashboard-line-chart]');
    if (!container) return;

    // Lê a série horária do data-attribute (JSON emitido pelo Twig)
    let hourly = [];
    try {
        hourly = JSON.parse(dashboard.dataset.hourly || '[]');
    } catch {
        hourly = [];
    }

    if (hourly.length === 0) {
        // Fallback: gera pontos a partir dos totais para não deixar chart vazio
        const totalAlerts = Number(dashboard.dataset.totalAlerts ?? 0);
        const totalJams   = Number(dashboard.dataset.totalJams ?? 0);
        hourly = Array.from({ length: 8 }, (_, i) => ({
            hour:   `${String(i).padStart(2, '0')}:00`,
            alerts: Math.max(0, Math.round(totalAlerts / 8 + ((i * 17) % 9))),
            jams:   Math.max(0, Math.round(totalJams   / 8 + ((i * 11) % 7))),
            total:  0,
        }));
    }

    // Normaliza pontos para o viewBox (0–700 x, 10–200 y invertido)
    const W = 700;
    const H = 200;
    const N = hourly.length;

    const maxAlerts = Math.max(1, ...hourly.map((p) => p.alerts));
    const maxJams   = Math.max(1, ...hourly.map((p) => p.jams));

    const toY = (val, max) => H - Math.round((val / max) * (H - 20)) - 5;

    const alertPoints = hourly.map((p, i) => ({
        x: Math.round((i / (N - 1)) * W),
        y: toY(p.alerts, maxAlerts),
    }));

    const jamPoints = hourly.map((p, i) => ({
        x: Math.round((i / (N - 1)) * W),
        y: toY(p.jams, maxJams),
    }));

    const buildLinePath  = (pts) => pts.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x},${p.y}`).join(' ');
    const buildFillPath  = (pts) => `M0,${H} ${pts.map((p) => `L${p.x},${p.y}`).join(' ')} L${W},${H} Z`;

    // Alertas
    container.querySelector('[data-chart-line]')?.setAttribute('d', buildLinePath(alertPoints));
    container.querySelector('[data-chart-fill]')?.setAttribute('d', buildFillPath(alertPoints));

    // Jams
    container.querySelector('[data-chart-line-jams]')?.setAttribute('d', buildLinePath(jamPoints));
    container.querySelector('[data-chart-fill-jams]')?.setAttribute('d', buildFillPath(jamPoints));
}
