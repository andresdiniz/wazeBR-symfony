function safeJson(value, fallback = []) {
    try { return JSON.parse(value || ''); } catch { return fallback; }
}

export function initDashboard(root = document) {
    const dashboard = root.querySelector('[data-dashboard]');
    if (!dashboard || dashboard.dataset.initialized === 'true') return;
    dashboard.dataset.initialized = 'true';
    initReveal(dashboard);
    initCounters(dashboard);
    initClock(dashboard);
    initPeriods(dashboard);
    initRefresh(dashboard);
    initLineChart(dashboard);
    initMap(dashboard);
}

function initReveal(dashboard) {
    const elements = dashboard.querySelectorAll('.dashboard-reveal');
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || !('IntersectionObserver' in window)) {
        elements.forEach((element) => element.classList.add('is-visible'));
        return;
    }
    const observer = new IntersectionObserver((entries, current) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) { entry.target.classList.add('is-visible'); current.unobserve(entry.target); }
        });
    }, { threshold: .08 });
    elements.forEach((element, index) => { element.style.transitionDelay = `${Math.min(index * 45, 260)}ms`; observer.observe(element); });
}

function initCounters(dashboard) {
    dashboard.querySelectorAll('[data-count-value]').forEach((element) => {
        const target = Number(element.dataset.countValue || 0);
        if (!Number.isFinite(target)) return;
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { element.textContent = String(target); return; }
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

function initPeriods(dashboard) {
    dashboard.querySelectorAll('[data-dashboard-period]').forEach((button) => {
        button.addEventListener('click', () => {
            dashboard.querySelectorAll('[data-dashboard-period]').forEach((item) => item.classList.remove('is-active'));
            button.classList.add('is-active');
            dashboard.dataset.period = button.dataset.dashboardPeriod;
        });
    });
}

function initRefresh(dashboard) {
    dashboard.querySelector('[data-action="refresh-dashboard"]')?.addEventListener('click', (event) => {
        const button = event.currentTarget;
        button.classList.add('is-loading');
        button.setAttribute('aria-busy', 'true');
        window.location.reload();
    });
}

function initLineChart(dashboard) {
    const chart = dashboard.querySelector('[data-dashboard-line-chart]');
    if (!chart) return;
    const alerts = safeJson(dashboard.dataset.alerts, []);
    const jams = safeJson(dashboard.dataset.jams, []);
    const total = Math.max(alerts.length + jams.length, 1);
    const line = chart.querySelector('[data-chart-line]');
    const fill = chart.querySelector('[data-chart-fill]');
    if (!line || !fill) return;
    const points = [0, 1, 2, 3, 4, 5, 6, 7].map((index) => {
        const variation = ((index * 17 + total * 13) % 35) - 17;
        return Math.max(22, Math.min(195, 175 - (total * 4) - (index * 11) + variation));
    });
    const linePath = points.map((y, index) => `${index * 100},${y}`).join(' L');
    line.setAttribute('d', `M${linePath}`);
    fill.setAttribute('d', `M0,220 L${linePath} L700,220 Z`);
}

function initMap(dashboard) {
    const element = dashboard.querySelector('[data-dashboard-map]');
    if (!element || !window.L) return;
    const map = window.L.map(element).setView([-19.92, -43.94], 7);
    window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap' }).addTo(map);
    const points = safeJson(element.dataset.points, []);
    points.forEach((point) => {
        if (point.lat === undefined || point.lng === undefined) return;
        window.L.marker([point.lat, point.lng]).addTo(map).bindPopup(point.label || 'Ocorrência');
    });
}

document.addEventListener('DOMContentLoaded', () => initDashboard());
