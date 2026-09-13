import '../../css/pages/dashboard.css';
import { initTheme } from '../app.js';


console.debug('Dashboard script loaded');

function initDashboard(root = document) {
    const dashboard = root.querySelector('[data-dashboard]');
    if (!dashboard || dashboard.dataset.dashboardInitialized === 'true') return;
    dashboard.dataset.dashboardInitialized = 'true';
    initTheme();
    initClock(dashboard);
    initCounters(dashboard);
    initFilters(dashboard);
    initRefresh(dashboard);
    initPagination(dashboard);
    initExpandButtons(dashboard);
    initChart(dashboard);
}

console.log('Dashboard initialized');

function initClock(dashboard) {
    const clock = dashboard.querySelector('[data-dashboard-clock]');
    if (!clock) return;
    const update = () => { clock.textContent = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short' }).format(new Date()); };
    update();
    window.setInterval(update, 30000);
}

function initCounters(dashboard) {
    dashboard.querySelectorAll('[data-count-value]').forEach((element) => {
        const target = Number(element.dataset.countValue || 0);
        if (!Number.isFinite(target)) return;
        const start = performance.now();
        const tick = (now) => {
            const progress = Math.min((now - start) / 700, 1);
            element.textContent = String(Math.round(target * (1 - Math.pow(1 - progress, 3))));
            if (progress < 1) window.requestAnimationFrame(tick);
        };
        window.requestAnimationFrame(tick);
    });
}

function initFilters(dashboard) {
    const fields = dashboard.querySelectorAll('[data-filter]');
    const feedback = dashboard.querySelector('[data-filter-feedback]');
    const apply = () => {
        const query = (dashboard.querySelector('[data-filter="query"]')?.value || '').trim().toLowerCase();
        const section = dashboard.querySelector('[data-filter="section"]')?.value || 'all';
        let visible = 0;
        dashboard.querySelectorAll('.data-section').forEach((panel) => {
            const enabled = section === 'all' || panel.dataset.section === section;
            panel.hidden = !enabled;
            if (!enabled) return;
            panel.querySelectorAll('.dashboard-data-row').forEach((row) => {
                const matches = !query || (row.dataset.searchText || '').includes(query);
                row.dataset.filtered = matches ? 'false' : 'true';
                row.hidden = !matches;
                if (matches) visible += 1;
            });
        });
        if (feedback) feedback.textContent = query || section !== 'all' ? `${visible} registro(s) encontrado(s).` : '';
        resetPagination(dashboard);
    };
    fields.forEach((field) => field.addEventListener('input', apply));
    dashboard.querySelectorAll('[data-action="apply-filters"]').forEach((button) => button.addEventListener('click', apply));
    dashboard.querySelectorAll('[data-action="clear-filters"]').forEach((button) => button.addEventListener('click', () => {
        fields.forEach((field) => { field.value = field.tagName === 'SELECT' ? field.querySelector('option')?.value || 'all' : ''; });
        apply();
    }));
}

function initRefresh(dashboard) {
    dashboard.querySelector('[data-action="refresh-dashboard"]')?.addEventListener('click', (event) => {
        const button = event.currentTarget;
        button.classList.add('is-loading');
        button.setAttribute('aria-busy', 'true');
        window.location.reload();
    });
}

function initPagination(dashboard) {
    dashboard.querySelectorAll('[data-pagination]').forEach((pagination) => {
        const section = pagination.dataset.pagination;
        const list = dashboard.querySelector(`[data-list="${section}"]`);
        if (!list) return;
        const rows = [...list.querySelectorAll('.dashboard-data-row')];
        const pageSize = 5;
        let page = 1;
        const render = () => {
            const visibleRows = rows.filter((row) => row.dataset.filtered !== 'true');
            const pages = Math.max(1, Math.ceil(visibleRows.length / pageSize));
            page = Math.min(page, pages);
            const pageRows = new Set(visibleRows.slice((page - 1) * pageSize, page * pageSize));
            rows.forEach((row) => { row.hidden = row.dataset.filtered === 'true' || !pageRows.has(row); });
            pagination.innerHTML = pages <= 1 ? '' : Array.from({ length: pages }, (_, index) => `<button type="button" class="${index + 1 === page ? 'is-active' : ''}" data-page="${index + 1}">${index + 1}</button>`).join('');
            pagination.querySelectorAll('[data-page]').forEach((button) => button.addEventListener('click', () => { page = Number(button.dataset.page); render(); }));
        };
        pagination._render = render;
        rows.forEach((row) => { row.dataset.filtered = 'false'; });
        render();
    });
}

function resetPagination(dashboard) { dashboard.querySelectorAll('[data-pagination]').forEach((pagination) => pagination._render?.()); }

function initExpandButtons(dashboard) {
    dashboard.querySelectorAll('[data-expand]').forEach((button) => button.addEventListener('click', () => {
        const list = dashboard.querySelector(`[data-list="${button.dataset.expand}"]`);
        if (!list) return;
        const expanded = button.dataset.expanded === 'true';
        list.querySelectorAll('.dashboard-data-row').forEach((row) => { row.hidden = expanded; });
        button.dataset.expanded = expanded ? 'false' : 'true';
        button.textContent = expanded ? 'Ver todos os registros →' : 'Recolher lista ↑';
    }));
}

function initChart(dashboard) {
    const chart = dashboard.querySelector('[data-dashboard-line-chart]');
    if (!chart) return;
    const total = Number(dashboard.dataset.totalOccurrences || 1);
    const points = [0, 1, 2, 3, 4, 5, 6, 7].map((index) => Math.max(25, Math.min(195, 180 - total * 2 - index * 12 + ((index * 19) % 27))));
    const path = points.map((y, index) => `${index * 100},${y}`).join(' L');
    chart.querySelector('[data-chart-line]')?.setAttribute('d', `M${path}`);
    chart.querySelector('[data-chart-fill]')?.setAttribute('d', `M0,220 L${path} L700,220 Z`);
}

document.addEventListener('DOMContentLoaded', () => initDashboard());
export { initDashboard };
