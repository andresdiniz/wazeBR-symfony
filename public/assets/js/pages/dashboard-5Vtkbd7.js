/**
 * pages/dashboard.js
 * Dashboard completo: KPIs, gráficos (Chart.js), mapa (Leaflet),
 * filtros com AJAX + fallback de navegação, contadores e clock.
 */

const COLORS = {
    blue:   '#2563eb',
    orange: '#ea580c',
    green:  '#16a34a',
    purple: '#7c3aed',
    gray:   '#94a3b8',
    red:    '#dc2626',
    cyan:   '#0891b2',
    grid:   'rgba(148, 163, 184, .25)',
    text:   '#475569',
};

const PALETTE = [COLORS.blue, COLORS.orange, COLORS.green, COLORS.purple, COLORS.red,
                 '#0891b2', '#7c2d12', '#4d7c0f', '#c026d3', '#0f766e'];

// ─────────────────────────────────────────────────────────────────────────
// Clock
// ─────────────────────────────────────────────────────────────────────────

export function initDashboardClock(doc = document) {
    const clock = doc.querySelector('[data-dashboard-clock]');
    if (!clock) return;

    const fmt = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short' });
    const tick = () => { clock.textContent = fmt.format(new Date()); };

    tick();
    setInterval(tick, 30_000);
}

// ─────────────────────────────────────────────────────────────────────────
// Counters
// ─────────────────────────────────────────────────────────────────────────

export function initDashboardCounters(doc = document) {
    doc.querySelectorAll('[data-count-value]').forEach((el) => {
        const target = Number(el.dataset.countValue ?? 0);
        if (!Number.isFinite(target) || target === 0) return;

        const start = performance.now();
        const tick = (now) => {
            const p = Math.min((now - start) / 700, 1);
            el.textContent = String(Math.round(target * (1 - Math.pow(1 - p, 3))));
            if (p < 1) requestAnimationFrame(tick);
        };
        requestAnimationFrame(tick);
    });
}

// ─────────────────────────────────────────────────────────────────────────
// Refresh
// ─────────────────────────────────────────────────────────────────────────

export function initDashboardRefresh(doc = document) {
    doc.querySelector('[data-action="refresh-dashboard"]')?.addEventListener('click', (e) => {
        const btn = e.currentTarget;
        btn.classList.add('is-loading');
        btn.setAttribute('aria-busy', 'true');
        location.reload();
    });
}

// ─────────────────────────────────────────────────────────────────────────
// Filters
// ─────────────────────────────────────────────────────────────────────────

export function initDashboardFilters(root = document) {
    const form = root.querySelector('[data-filter-form]');
    if (!form) return;

    const endpoint = root.querySelector('[data-dashboard]')?.dataset.apiEndpoint;
    const feedback = root.querySelector('[data-filter-feedback]');

    const setFeedback = (msg, isError = false) => {
        if (!feedback) return;
        feedback.textContent = msg;
        feedback.classList.toggle('is-error', isError);
    };

    const collect = () => {
        const fd = new FormData(form);
        const out = {};
        for (const [k, v] of fd.entries()) {
            if (v !== '' && v !== null) out[k] = String(v).trim();
        }
        return out;
    };

    const applyToUrl = (params) => {
        const url = new URL(window.location.href);
        url.search = '';
        Object.entries(params).forEach(([k, v]) => {
            if (v) url.searchParams.set(k, v);
        });
        window.location.href = url.toString();
    };

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const params = collect();
        setFeedback('Aplicando filtros...');

        if (!endpoint) return applyToUrl(params);

        try {
            const url = new URL(endpoint, window.location.origin);
            Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));

            const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const json = await res.json();

            window.dispatchEvent(new CustomEvent('dashboard:update', { detail: json.data }));
            applyToUrl(params);
        } catch (err) {
            setFeedback('Falha ao aplicar filtros: ' + err.message, true);
        }
    });

    form.querySelectorAll('[data-action="clear-filters"]').forEach((btn) => {
        btn.addEventListener('click', () => {
            form.reset();
            applyToUrl({});
        });
    });

    root.querySelectorAll('[data-action="clear-filters"]').forEach((btn) => {
        if (form.contains(btn)) return;
        btn.addEventListener('click', () => {
            form.reset();
            applyToUrl({});
        });
    });
}

// ─────────────────────────────────────────────────────────────────────────
// Charts
// ─────────────────────────────────────────────────────────────────────────

const charts = new Map();

function parseData(canvas) {
    try {
        return JSON.parse(canvas.dataset.chartData || '[]');
    } catch {
        return [];
    }
}

function destroyChart(key) {
    const existing = charts.get(key);
    if (existing) { existing.destroy(); charts.delete(key); }
}

function mountChart(key, canvas, config) {
    if (!canvas || typeof Chart === 'undefined') return;
    destroyChart(key);
    const chart = new Chart(canvas.getContext('2d'), config);
    charts.set(key, chart);
}

export function initDashboardCharts(root = document) {
    if (typeof Chart === 'undefined') return;

    Chart.defaults.color = COLORS.text;
    Chart.defaults.font.family = "Inter, system-ui, -apple-system, 'Segoe UI', sans-serif";
    Chart.defaults.font.size = 12;

    // 1) Hourly activity — alertas vs jams
    root.querySelectorAll('[data-chart="hourly-activity"]').forEach((canvas) => {
        const points = parseData(canvas);
        const labels = points.map((p) => p.hour);
        const alerts = points.map((p) => Number(p.alerts) || 0);
        const jams   = points.map((p) => Number(p.jams) || 0);

        mountChart('hourly', canvas, {
            type: 'line',
            data: {
                labels,
                datasets: [
                    {
                        label: 'Alertas',
                        data: alerts,
                        borderColor: COLORS.blue,
                        backgroundColor: 'rgba(37,99,235,.15)',
                        fill: true,
                        tension: 0.35,
                        pointRadius: 0,
                        borderWidth: 2,
                    },
                    {
                        label: 'Jams',
                        data: jams,
                        borderColor: COLORS.orange,
                        backgroundColor: 'rgba(234,88,12,.10)',
                        fill: true,
                        tension: 0.35,
                        pointRadius: 0,
                        borderWidth: 2,
                    },
                ],
            },
            options: {
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } },
                scales: {
                    x: { grid: { display: false } },
                    y: { grid: { color: COLORS.grid }, beginAtZero: true },
                },
            },
        });
    });

    // 2) Alerts by type — doughnut
    root.querySelectorAll('[data-chart="alerts-by-type"]').forEach((canvas) => {
        const points = parseData(canvas);
        const labels = points.map((p) => p.label);
        const data   = points.map((p) => Number(p.total) || 0);

        mountChart('alertsByType', canvas, {
            type: 'doughnut',
            data: {
                labels,
                datasets: [{
                    data,
                    backgroundColor: labels.map((_, i) => PALETTE[i % PALETTE.length]),
                    borderWidth: 0,
                }],
            },
            options: {
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: { position: 'right', labels: { usePointStyle: true, boxWidth: 8 } },
                },
            },
        });
    });

    // 3) Alerts by city — horizontal bar
    root.querySelectorAll('[data-chart="alerts-by-city"]').forEach((canvas) => {
        const points = parseData(canvas);
        const labels = points.map((p) => p.label);
        const data   = points.map((p) => Number(p.total) || 0);

        mountChart('alertsByCity', canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Alertas',
                    data,
                    backgroundColor: 'rgba(37,99,235,.75)',
                    borderRadius: 6,
                }],
            },
            options: {
                indexAxis: 'y',
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { color: COLORS.grid }, beginAtZero: true },
                    y: { grid: { display: false } },
                },
            },
        });
    });

    // 4) Jams by level — vertical bar
    root.querySelectorAll('[data-chart="jams-by-level"]').forEach((canvas) => {
        const points = parseData(canvas);
        const labels = points.map((p) => p.label);
        const data   = points.map((p) => Number(p.total) || 0);

        mountChart('jamsByLevel', canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Jams',
                    data,
                    backgroundColor: 'rgba(234,88,12,.75)',
                    borderRadius: 6,
                }],
            },
            options: {
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false } },
                    y: { grid: { color: COLORS.grid }, beginAtZero: true },
                },
            },
        });
    });

    // 5) Weather trend — multi-axis line
    root.querySelectorAll('[data-chart="weather-trend"]').forEach((canvas) => {
        const points = parseData(canvas);
        const labels = points.map((p) => (p.time || '').slice(11, 16));
        const temp   = points.map((p) => p.temperature);
        const hum    = points.map((p) => p.humidity);
        const rain   = points.map((p) => p.precipitation);

        mountChart('weatherTrend', canvas, {
            type: 'line',
            data: {
                labels,
                datasets: [
                    {
                        label: 'Temp (°C)',
                        data: temp,
                        borderColor: COLORS.red,
                        backgroundColor: 'rgba(220,38,38,.12)',
                        tension: 0.35,
                        pointRadius: 0,
                        borderWidth: 2,
                        yAxisID: 'y',
                    },
                    {
                        label: 'Umidade (%)',
                        data: hum,
                        borderColor: COLORS.blue,
                        backgroundColor: 'rgba(37,99,235,.10)',
                        tension: 0.35,
                        pointRadius: 0,
                        borderWidth: 2,
                        yAxisID: 'y1',
                    },
                    {
                        label: 'Chuva (mm)',
                        data: rain,
                        borderColor: COLORS.green,
                        backgroundColor: 'rgba(22,163,74,.15)',
                        tension: 0.35,
                        pointRadius: 0,
                        borderWidth: 2,
                        yAxisID: 'y2',
                    },
                ],
            },
            options: {
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } },
                scales: {
                    x: { grid: { display: false } },
                    y:  { position: 'left',  grid: { color: COLORS.grid }, title: { display: true, text: '°C' } },
                    y1: { position: 'right', grid: { display: false }, title: { display: true, text: '%' }, ticks: { max: 100 } },
                    y2: { display: false, beginAtZero: true },
                },
            },
        });
    });

    // 6) Hydro trend — nível do rio + cotas
    root.querySelectorAll('[data-chart="hydro-trend"]').forEach((canvas) => {
        const points = parseData(canvas);
        const labels = points.map((p) => (p.time || '').slice(5, 16)); // "MM-DD HH:00"
        const levels = points.map((p) => p.avg_level);
        const atencao = points.map((p) => p.cota_atencao);
        const alerta  = points.map((p) => p.cota_alerta);

        mountChart('hydroTrend', canvas, {
            type: 'line',
            data: {
                labels,
                datasets: [
                    {
                        label: 'Nível do rio (m)',
                        data: levels,
                        borderColor: COLORS.cyan,
                        backgroundColor: 'rgba(8,145,178,.15)',
                        fill: true,
                        tension: 0.35,
                        pointRadius: 0,
                        borderWidth: 2,
                    },
                    {
                        label: 'Cota de atenção',
                        data: atencao,
                        borderColor: COLORS.orange,
                        borderDash: [6, 4],
                        pointRadius: 0,
                        borderWidth: 1.5,
                        fill: false,
                    },
                    {
                        label: 'Cota de alerta',
                        data: alerta,
                        borderColor: COLORS.red,
                        borderDash: [6, 4],
                        pointRadius: 0,
                        borderWidth: 1.5,
                        fill: false,
                    },
                ],
            },
            options: {
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { grid: { color: COLORS.grid }, title: { display: true, text: 'metros' } },
                },
            },
        });
    });
}

// ─────────────────────────────────────────────────────────────────────────
// Map (Leaflet)
// ─────────────────────────────────────────────────────────────────────────

const layerGroups = {};
let leafletMap = null;

function safeJson(str, fallback = []) {
    try { return JSON.parse(str); } catch { return fallback; }
}

function alertColor(type) {
    switch ((type || '').toUpperCase()) {
        case 'ACCIDENT':        return COLORS.red;
        case 'JAM':             return COLORS.orange;
        case 'ROAD_CLOSED':     return '#111827';
        case 'POLICE':          return '#1d4ed8';
        case 'WEATHERHAZARD':   return '#0891b2';
        case 'HAZARD':          return '#f59e0b';
        default:                return COLORS.blue;
    }
}

function jamColor(level) {
    return [COLORS.gray, '#22c55e', '#84cc16', '#f59e0b', '#f97316', COLORS.red][level] ?? COLORS.orange;
}

export function initDashboardMap(root = document) {
    const container = root.querySelector('[data-dashboard-map]');
    if (!container || typeof L === 'undefined') return;

    const center = safeJson(container.dataset.mapCenter, { lat: -15.78, lng: -47.92, zoom: 11 });
    const alerts   = safeJson(container.dataset.mapAlerts, []);
    const jams     = safeJson(container.dataset.mapJams, []);
    const cameras  = safeJson(container.dataset.mapCameras, []);
    const weather  = safeJson(container.dataset.mapWeather, []);
    const stations = safeJson(container.dataset.mapStations, []);

    leafletMap = L.map(container, { zoomControl: true, preferCanvas: true })
        .setView([center.lat, center.lng], center.zoom ?? 11);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap',
    }).addTo(leafletMap);

    layerGroups.alerts   = L.layerGroup().addTo(leafletMap);
    layerGroups.jams     = L.layerGroup().addTo(leafletMap);
    layerGroups.cameras  = L.layerGroup().addTo(leafletMap);
    layerGroups.weather  = L.layerGroup().addTo(leafletMap);
    layerGroups.stations = L.layerGroup().addTo(leafletMap);

    // Alerts
    alerts.forEach((a) => {
        if (!Number.isFinite(a.lat) || !Number.isFinite(a.lng)) return;
        const color = alertColor(a.type);
        L.circleMarker([a.lat, a.lng], {
            radius: 6, color, weight: 1, fillColor: color, fillOpacity: 0.75,
        }).bindPopup(`
            <strong>${escapeHtml(a.type || 'Alerta')}</strong><br>
            <small>${escapeHtml(a.subtype || '')}</small><br>
            ${escapeHtml(a.street || '')} — ${escapeHtml(a.city || '')}<br>
            <em>${escapeHtml(String(a.when || ''))}</em>
        `).addTo(layerGroups.alerts);
    });

    // Jams (polylines)
    jams.forEach((j) => {
        if (!Array.isArray(j.path) || j.path.length < 2) return;
        const color = jamColor(Number(j.level) || 0);
        L.polyline(j.path, { color, weight: 5, opacity: 0.8 })
            .bindPopup(`
                <strong>Congestionamento — nível ${j.level}</strong><br>
                ${escapeHtml(j.street || '')} — ${escapeHtml(j.city || '')}<br>
                Atraso: ${j.delay}s · ${(j.length / 1000).toFixed(1)} km
            `)
            .addTo(layerGroups.jams);
    });

    // Cameras
    cameras.forEach((c) => {
        if (!Number.isFinite(c.lat) || !Number.isFinite(c.lng)) return;
        L.circleMarker([c.lat, c.lng], {
            radius: 5, color: COLORS.purple, weight: 1, fillColor: COLORS.purple, fillOpacity: 0.85,
        }).bindPopup(`
            <strong>${escapeHtml(c.name || 'Câmera')}</strong><br>
            ${escapeHtml(c.city || '')} ${c.state ? '/ ' + escapeHtml(c.state) : ''}<br>
            ${c.url ? `<a href="${escapeHtml(c.url)}" target="_blank" rel="noopener">Abrir stream</a>` : ''}
        `).addTo(layerGroups.cameras);
    });

    // Weather locations
    weather.forEach((w) => {
        if (!Number.isFinite(w.lat) || !Number.isFinite(w.lng)) return;
        L.circleMarker([w.lat, w.lng], {
            radius: 5, color: COLORS.green, weight: 1, fillColor: COLORS.green, fillOpacity: 0.8,
        }).bindPopup(`
            <strong>${escapeHtml(w.name || 'Estação')}</strong><br>
            ${escapeHtml(w.city || '')} · ${escapeHtml(w.provider || '')}
        `).addTo(layerGroups.weather);
    });

    // CEMADEN stations
    stations.forEach((s) => {
        if (!Number.isFinite(s.lat) || !Number.isFinite(s.lng)) return;
        L.circleMarker([s.lat, s.lng], {
            radius: 4, color: '#0f766e', weight: 1, fillColor: '#14b8a6', fillOpacity: 0.8,
        }).bindPopup(`
            <strong>${escapeHtml(s.name || 'Estação CEMADEN')}</strong><br>
            ${escapeHtml(s.city || '')} ${s.state ? '/ ' + escapeHtml(s.state) : ''}<br>
            <small>${escapeHtml(s.type || '')}</small>
        `).addTo(layerGroups.stations);
    });

    // Layer toggles
    root.querySelectorAll('[data-layer-toggle]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const key = btn.dataset.layerToggle;
            const group = layerGroups[key];
            if (!group) return;
            const active = leafletMap.hasLayer(group);
            if (active) leafletMap.removeLayer(group);
            else leafletMap.addLayer(group);
            btn.classList.toggle('is-active', !active);
        });
    });

    // Resize handling
    window.addEventListener('resize', () => leafletMap && leafletMap.invalidateSize());

    // Expose globally for debugging
    window.dashboardMap = leafletMap;
}

function escapeHtml(str) {
    return String(str ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

// ─────────────────────────────────────────────────────────────────────────
// Hot-swap on AJAX update
// ─────────────────────────────────────────────────────────────────────────

export function attachDashboardLiveUpdates(root = document) {
    window.addEventListener('dashboard:update', (e) => {
        const data = e.detail;
        if (!data) return;

        // Contadores KPI
        if (data.kpis) {
            const cardSelectors = [
                { sel: '.kpi-blue   .dashboard-kpi-value', val: data.kpis.alerts },
                { sel: '.kpi-orange .dashboard-kpi-value', val: data.kpis.jams },
                { sel: '.kpi-green  .dashboard-kpi-value', val: data.kpis.weather },
                { sel: '.kpi-purple .dashboard-kpi-value', val: data.kpis.cameras },
                { sel: '.kpi-cyan   .dashboard-kpi-value', val: data.kpis.hydro_stations },
            ];
            cardSelectors.forEach(({ sel, val }) => {
                if (val === undefined) return;
                const el = root.querySelector(sel);
                if (el) el.textContent = String(val);
            });

            // Trend "em atenção" do KPI cyan
            const atRisk = data.kpis.hydro_at_risk;
            if (atRisk !== undefined) {
                const trend = root.querySelector('.kpi-cyan .dashboard-kpi-trend');
                if (trend) {
                    trend.textContent = `${atRisk} em atenção`;
                    trend.classList.toggle('is-warning', atRisk > 0);
                }
            }
        }

        // Charts
        if (data.charts) {
            const refresh = (key, selector) => {
                const canvas = root.querySelector(selector);
                if (!canvas || !data.charts[key]) return;
                canvas.dataset.chartData = JSON.stringify(data.charts[key]);
            };
            refresh('hourly_activity', '[data-chart="hourly-activity"]');
            refresh('alerts_by_type',  '[data-chart="alerts-by-type"]');
            refresh('alerts_by_city',  '[data-chart="alerts-by-city"]');
            refresh('jams_by_level',   '[data-chart="jams-by-level"]');
            refresh('weather_trend',   '[data-chart="weather-trend"]');
        }

        // Hydro trend
        if (data.hydro && data.hydro.trend) {
            const canvas = root.querySelector('[data-chart="hydro-trend"]');
            if (canvas) {
                canvas.dataset.chartData = JSON.stringify(data.hydro.trend);
            }
        }

        initDashboardCharts(root);
    });
}

// ─────────────────────────────────────────────────────────────────────────
// Bootstrap
// ─────────────────────────────────────────────────────────────────────────

export function initDashboard(root = document) {
    if (!root.querySelector('[data-dashboard]')) return;
    initDashboardClock(root);
    initDashboardCounters(root);
    initDashboardRefresh(root);
    initDashboardFilters(root);
    initDashboardCharts(root);
    initDashboardMap(root);
    attachDashboardLiveUpdates(root);
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => initDashboard());
    } else {
        initDashboard();
    }
}
