function parseJson(element, attribute) {
    try { return JSON.parse(element.dataset[attribute] || '{}'); } catch { return {}; }
}

export function initDashboard(root = document) {
    const dashboard = root.querySelector('[data-dashboard]');
    if (!dashboard || dashboard.dataset.initialized === 'true') return;
    dashboard.dataset.initialized = 'true';
    initCounters(dashboard);
    initClock(dashboard);
    initRefreshButton(dashboard);
    initDashboardMap(dashboard);
    initDashboardCharts(dashboard);
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

function initClock(dashboard) {
    const clock = dashboard.querySelector('[data-dashboard-clock]');
    if (!clock) return;
    const update = () => { clock.textContent = new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit' }).format(new Date()); };
    update(); window.setInterval(update, 30000);
}

function initRefreshButton(dashboard) {
    dashboard.querySelector('[data-action="refresh-dashboard"]')?.addEventListener('click', (event) => {
        event.currentTarget.classList.add('is-loading');
        window.location.reload();
    });
}

function initDashboardMap(dashboard) {
    const element = dashboard.querySelector('[data-dashboard-map]');
    if (!element || !window.L) return;
    const map = window.L.map(element).setView([-19.92, -43.94], 7);
    window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap' }).addTo(map);
    const points = parseJson(element, 'points');
    points.forEach((point) => {
        if (point.lat === undefined || point.lng === undefined) return;
        window.L.marker([point.lat, point.lng]).addTo(map).bindPopup(point.label || 'Ocorrência');
    });
}

function initDashboardCharts(dashboard) {
    const canvas = dashboard.querySelector('[data-dashboard-chart="alerts"]');
    if (!canvas || !window.Chart) return;
    const values = parseJson(canvas, 'chartValues');
    new window.Chart(canvas, { type: 'doughnut', data: { labels: Object.keys(values), datasets: [{ data: Object.values(values), backgroundColor: ['#ef4444', '#f59e0b', '#2563eb', '#10b981', '#8b5cf6'] }] }, options: { responsive: true, plugins: { legend: { position: 'bottom' } } } });
}

document.addEventListener('DOMContentLoaded', () => initDashboard());
