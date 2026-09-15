/**
 * pages/dashboard.js
 * Dashboard completo com AJAX, atualização parcial e filtros por estação.
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
// Utils
// ─────────────────────────────────────────────────────────────────────────

function escapeHtml(str) {
    return String(str ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function safeJson(str, fallback = []) {
    try { return JSON.parse(str); } catch { return fallback; }
}

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
// Filters (AJAX + pushState)
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
            const val = String(v).trim();
            if (val !== '') out[k] = val;
        }
        return out;
    };

    const pushUrl = (params) => {
        const url = new URL(window.location.href);
        url.search = '';
        Object.entries(params).forEach(([k, v]) => {
            if (v) url.searchParams.set(k, v);
        });
        history.pushState({}, '', url.toString());
    };

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const params = collect();
        setFeedback('Aplicando filtros...');

        if (!endpoint) {
            const url = new URL(window.location.href);
            url.search = '';
            Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));
            window.location.href = url.toString();
            return;
        }

        try {
            const url = new URL(endpoint, window.location.origin);
            Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));

            const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const json = await res.json();

            hydrateDashboard(json.data, root);
            pushUrl(params);
            setFeedback(`Filtros aplicados · ${new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })}`);
        } catch (err) {
            setFeedback('Falha ao aplicar filtros: ' + err.message, true);
        }
    });

    const clearAll = () => {
        form.reset();
        pushUrl({});

        // re-renderiza o dashboard com filtros vazios
        if (!endpoint) {
            window.location.href = window.location.pathname;
            return;
        }
        const url = new URL(endpoint, window.location.origin);
        fetch(url, { headers: { 'Accept': 'application/json' } })
            .then((r) => r.json())
            .then((json) => {
                hydrateDashboard(json.data, root);
                setFeedback('Filtros limpos');
            })
            .catch(() => setFeedback('Falha ao limpar filtros', true));
    };

    form.querySelectorAll('[data-action="clear-filters"]').forEach((btn) => {
        btn.addEventListener('click', clearAll);
    });

    root.querySelectorAll('[data-action="clear-filters"]').forEach((btn) => {
        if (form.contains(btn)) return;
        btn.addEventListener('click', clearAll);
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

    // 1) Hourly activity
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
                        fill: true, tension: 0.35, pointRadius: 0, borderWidth: 2,
                    },
                    {
                        label: 'Jams',
                        data: jams,
                        borderColor: COLORS.orange,
                        backgroundColor: 'rgba(234,88,12,.10)',
                        fill: true, tension: 0.35, pointRadius: 0, borderWidth: 2,
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

    // 2) Alerts by type
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
                plugins: { legend: { position: 'right', labels: { usePointStyle: true, boxWidth: 8 } } },
            },
        });
    });

    // 3) Alerts by city
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

    // 3b) Alerts by street
    root.querySelectorAll('[data-chart="alerts-by-street"]').forEach((canvas) => {
        const points = parseData(canvas);
        const labels = points.map((p) => p.label);
        const data   = points.map((p) => Number(p.total) || 0);

        mountChart('alertsByStreet', canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Alertas',
                    data,
                    backgroundColor: 'rgba(124,58,237,.75)',
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

    // 4) Jams by level
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

    // 5) Weather trend
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
                        label: 'Temp (°C)', data: temp,
                        borderColor: COLORS.red, backgroundColor: 'rgba(220,38,38,.12)',
                        tension: 0.35, pointRadius: 0, borderWidth: 2, yAxisID: 'y',
                    },
                    {
                        label: 'Umidade (%)', data: hum,
                        borderColor: COLORS.blue, backgroundColor: 'rgba(37,99,235,.10)',
                        tension: 0.35, pointRadius: 0, borderWidth: 2, yAxisID: 'y1',
                    },
                    {
                        label: 'Chuva (mm)', data: rain,
                        borderColor: COLORS.green, backgroundColor: 'rgba(22,163,74,.15)',
                        tension: 0.35, pointRadius: 0, borderWidth: 2, yAxisID: 'y2',
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

    // 6) Hydro trend (48h)
    root.querySelectorAll('[data-chart="hydro-trend"]').forEach((canvas) => {
        const points = parseData(canvas);
        const labels = points.map((p) => (p.time || '').slice(5, 16));
        const levels = points.map((p) => p.avg_level);
        const atencao = points.map((p) => p.cota_atencao);
        const alerta  = points.map((p) => p.cota_alerta);

        mountChart('hydroTrend', canvas, {
            type: 'line',
            data: {
                labels,
                datasets: [
                    {
                        label: 'Nível do rio (m)', data: levels,
                        borderColor: COLORS.cyan, backgroundColor: 'rgba(8,145,178,.15)',
                        fill: true, tension: 0.35, pointRadius: 0, borderWidth: 2,
                    },
                    {
                        label: 'Cota de atenção', data: atencao,
                        borderColor: COLORS.orange, borderDash: [6, 4],
                        pointRadius: 0, borderWidth: 1.5, fill: false,
                    },
                    {
                        label: 'Cota de alerta', data: alerta,
                        borderColor: COLORS.red, borderDash: [6, 4],
                        pointRadius: 0, borderWidth: 1.5, fill: false,
                    },
                ],
            },
            options: {
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } },
                scales: {
                    x: { grid: { display: false } },
                    y: { grid: { color: COLORS.grid }, title: { display: true, text: 'metros' } },
                },
            },
        });
    });

    // 7) Pluvio hourly
    root.querySelectorAll('[data-chart="pluvio-hourly"]').forEach((canvas) => {
        const points = parseData(canvas);
        const labels = points.map((p) => {
            const t = (p.time || '');
            return t.length >= 16 ? t.slice(11, 16) : t;
        });
        const rain = points.map((p) => Number(p.rain) || 0);
        const wet  = points.map((p) => Number(p.wet_stations) || 0);

        mountChart('pluvioHourly', canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Chuva (mm)',
                    data: rain,
                    backgroundColor: rain.map((v) => v > 0
                        ? 'rgba(37,99,235,.75)'
                        : 'rgba(148,163,184,.35)'),
                    borderColor: 'rgba(37,99,235,.95)',
                    borderWidth: 1, borderRadius: 4,
                }],
            },
            options: {
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const i = ctx.dataIndex;
                                const v = ctx.parsed.y;
                                if (!v) return 'Sem chuva';
                                const wetStations = wet[i] || 0;
                                return `Chuva: ${Number(v).toFixed(1)} mm · ${wetStations} ponto(s)`;
                            },
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false } },
                    y: {
                        grid: { color: COLORS.grid },
                        title: { display: true, text: 'Chuva (mm)' },
                        beginAtZero: true,
                    },
                },
            },
        });
    });
}

// ─────────────────────────────────────────────────────────────────────────
// Station pickers (hydro & pluvio)
// ─────────────────────────────────────────────────────────────────────────

let selectedHydroStation = 'all';
let selectedPluvioStation = 'all';

export function initHydroPicker(root = document) {
    const panel = root.querySelector('[data-hydro-panel]');
    if (!panel) return;

    const buttons = panel.querySelectorAll('[data-hydro-select]');
    buttons.forEach((btn) => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.hydroSelect;
            selectedHydroStation = id;

            buttons.forEach((b) => b.classList.toggle('is-active', b === btn));

            const all = safeJson(panel.dataset.hydroTrendAll, []);
            const byStation = safeJson(panel.dataset.hydroTrendByStation, {});
            const data = id === 'all' ? all : (byStation[id] || []);

            const canvas = panel.querySelector('[data-chart="hydro-trend"]');
            if (canvas) {
                canvas.dataset.chartData = JSON.stringify(data);
                initDashboardCharts(root);
            }
        });
    });
}

export function initPluvioPicker(root = document) {
    const panel = root.querySelector('[data-pluvio-panel]');
    if (!panel) return;

    const buttons = panel.querySelectorAll('[data-pluvio-select]');
    buttons.forEach((btn) => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.pluvioSelect;
            selectedPluvioStation = id;

            buttons.forEach((b) => b.classList.toggle('is-active', b === btn));

            const all = safeJson(panel.dataset.pluvioHourlyAll, []);
            const byStation = safeJson(panel.dataset.pluvioHourlyByStation, {});
            const data = id === 'all' ? all : (byStation[id] || []);

            const canvas = panel.querySelector('[data-chart="pluvio-hourly"]');
            if (canvas) {
                canvas.dataset.chartData = JSON.stringify(data);
                initDashboardCharts(root);
            }
        });
    });
}

// ─────────────────────────────────────────────────────────────────────────
// Map (Leaflet)
// ─────────────────────────────────────────────────────────────────────────

let layerGroups = {};
let leafletMap = null;
let mapContainer = null;

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
    mapContainer = container;

    const center = safeJson(container.dataset.mapCenter, { lat: -15.78, lng: -47.92, zoom: 11 });

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

    populateDashboardMap(container);

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

    window.addEventListener('resize', () => leafletMap && leafletMap.invalidateSize());

    window.dashboardMap = leafletMap;
}

function populateDashboardMap(container) {
    if (!leafletMap || !container) return;

    // limpar
    Object.values(layerGroups).forEach((g) => g.clearLayers());

    const alerts   = safeJson(container.dataset.mapAlerts, []);
    const jams     = safeJson(container.dataset.mapJams, []);
    const cameras  = safeJson(container.dataset.mapCameras, []);
    const weather  = safeJson(container.dataset.mapWeather, []);
    const stations = safeJson(container.dataset.mapStations, []);

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

    jams.forEach((j) => {
        if (!Array.isArray(j.path) || j.path.length < 2) return;
        const color = jamColor(Number(j.level) || 0);
        L.polyline(j.path, { color, weight: 5, opacity: 0.8 })
            .bindPopup(`
                <strong>Congestionamento — nível ${j.level}</strong><br>
                ${escapeHtml(j.street || '')} — ${escapeHtml(j.city || '')}<br>
                Atraso: ${j.delay}s · ${((j.length || 0) / 1000).toFixed(1)} km
            `)
            .addTo(layerGroups.jams);
    });

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

    weather.forEach((w) => {
        if (!Number.isFinite(w.lat) || !Number.isFinite(w.lng)) return;
        L.circleMarker([w.lat, w.lng], {
            radius: 5, color: COLORS.green, weight: 1, fillColor: COLORS.green, fillOpacity: 0.8,
        }).bindPopup(`
            <strong>${escapeHtml(w.name || 'Estação')}</strong><br>
            ${escapeHtml(w.city || '')} · ${escapeHtml(w.provider || '')}
        `).addTo(layerGroups.weather);
    });

    stations.forEach((s) => {
        if (!Number.isFinite(s.lat) || !Number.isFinite(s.lng)) return;

        const rain1h  = Number(s.rain1h ?? 0);
        const rain24h = Number(s.rain24h ?? 0);

        const color = rain24h > 20 ? '#1d4ed8'
                   : rain24h > 5  ? '#2563eb'
                   : rain24h > 0  ? '#0891b2'
                   : '#14b8a6';

        L.circleMarker([s.lat, s.lng], {
            radius: rain24h > 0 ? 6 : 4,
            color: '#0f766e', weight: 1,
            fillColor: color, fillOpacity: 0.85,
        }).bindPopup(`
            <strong>${escapeHtml(s.name || 'Estação CEMADEN')}</strong><br>
            ${escapeHtml(s.city || '')} ${s.state ? '/ ' + escapeHtml(s.state) : ''}<br>
            <small>${escapeHtml(s.type || '')}</small><br>
            <hr style="border:none;border-top:1px solid #e5e7eb;margin:4px 0">
            <small>Chuva 1h: <strong>${rain1h.toFixed(1)} mm</strong></small><br>
            <small>Chuva 24h: <strong>${rain24h.toFixed(1)} mm</strong></small>
        `).addTo(layerGroups.stations);
    });
}

/** Repopula o mapa com dados novos (após AJAX). */
export function refreshDashboardMap(mapData) {
    if (!mapContainer || !mapData) return;
    mapContainer.dataset.mapAlerts   = JSON.stringify(mapData.alerts || []);
    mapContainer.dataset.mapJams     = JSON.stringify(mapData.jams || []);
    mapContainer.dataset.mapCameras  = JSON.stringify(mapData.cameras || []);
    mapContainer.dataset.mapWeather  = JSON.stringify(mapData.weather || []);
    mapContainer.dataset.mapStations = JSON.stringify(mapData.stations || []);
    if (mapData.center) {
        mapContainer.dataset.mapCenter = JSON.stringify(mapData.center);
    }
    populateDashboardMap(mapContainer);
}

// ─────────────────────────────────────────────────────────────────────────
// Live update — hidrata DOM a partir do payload do endpoint
// ─────────────────────────────────────────────────────────────────────────

export function attachDashboardLiveUpdates(root = document) {
    window.addEventListener('dashboard:update', (e) => {
        hydrateDashboard(e.detail, root);
    });
}

function setChartData(root, chartName, data) {
    const canvas = root.querySelector(`[data-chart="${chartName}"]`);
    if (canvas) canvas.dataset.chartData = JSON.stringify(data);
}

function renderKPIs(root, kpis, totals) {
    if (!kpis) return;

    const cards = [
        { sel: '.kpi-blue   .dashboard-kpi-value', val: kpis.alerts },
        { sel: '.kpi-orange .dashboard-kpi-value', val: kpis.jams },
        { sel: '.kpi-gold   .dashboard-kpi-value', val: kpis.routes_total },
        { sel: '.kpi-green  .dashboard-kpi-value', val: kpis.weather },
        { sel: '.kpi-purple .dashboard-kpi-value', val: kpis.cameras },
        { sel: '.kpi-cyan   .dashboard-kpi-value', val: kpis.hydro_stations },
        { sel: '.kpi-rain   .dashboard-kpi-value', val: kpis.pluvio_stations },
    ];
    cards.forEach(({ sel, val }) => {
        if (val === undefined) return;
        const el = root.querySelector(sel);
        if (el) {
            el.dataset.countValue = String(val);
            el.textContent = String(val);
        }
    });

    // trends
    const setTrend = (name, text, extraClass = '') => {
        const el = root.querySelector(`[data-kpi-trend="${name}"]`);
        if (!el) return;
        el.textContent = text;
        el.classList.remove('is-warning', 'is-positive');
        if (extraClass) el.classList.add(extraClass);
    };

    if (totals) {
        setTrend('alerts_active', `${totals.alerts_active ?? 0} ativos`);
        setTrend('jams_active', `${totals.jams_active ?? 0} ativos`, (totals.jams_active ?? 0) > 10 ? 'is-warning' : '');
    }

    if (kpis.routes_delayed !== undefined) {
        setTrend('routes_delayed', `${kpis.routes_delayed} atrasadas`, kpis.routes_delayed > 0 ? 'is-warning' : '');
    }
    if (kpis.hydro_at_risk !== undefined) {
        setTrend('hydro_at_risk', `${kpis.hydro_at_risk} em atenção`, kpis.hydro_at_risk > 0 ? 'is-warning' : '');
    }
    if (kpis.pluvio_rain_24h !== undefined) {
        const v = Number(kpis.pluvio_rain_24h);
        setTrend('pluvio_rain_24h', `${v.toFixed(1).replace('.', ',')} mm · 24h`, v > 0 ? 'is-positive' : '');
    }
}

function renderRecentAlerts(root, alerts) {
    const list = root.querySelector('[data-list="alerts"]');
    if (!list) return;

    if (!alerts || alerts.length === 0) {
        list.innerHTML = `<div class="dashboard-empty"><span aria-hidden="true">✓</span><strong>Nenhum alerta recente</strong><small>Ajuste os filtros ou verifique as fontes.</small></div>`;
    } else {
        list.innerHTML = alerts.map((a) => {
            const date = a.pubDateTime ? new Date(a.pubDateTime) : null;
            const dateStr = date
                ? `${String(date.getDate()).padStart(2,'0')}/${String(date.getMonth()+1).padStart(2,'0')} ${String(date.getHours()).padStart(2,'0')}:${String(date.getMinutes()).padStart(2,'0')}`
                : '—';
            return `
                <div class="dashboard-data-row">
                    <span class="dashboard-row-icon row-orange" aria-hidden="true">⚠</span>
                    <div>
                        <strong>${escapeHtml(a.typeLabel || a.type || 'Alerta')}</strong>
                        <small>#${a.id} · ${escapeHtml(a.subtype || '—')} · ${escapeHtml(a.city || 'Local não informado')}${a.street ? ' · ' + escapeHtml(a.street) : ''}</small>
                    </div>
                    <time>${dateStr}</time>
                    <span class="dashboard-severity">Confiança ${a.confidence ?? 0}</span>
                </div>`;
        }).join('');
    }

    const counter = root.querySelector('[data-count="recent-alerts"]');
    if (counter) counter.textContent = String(alerts?.length ?? 0);
}

function renderRecentJams(root, jams) {
    const list = root.querySelector('[data-list="jams"]');
    if (!list) return;

    if (!jams || jams.length === 0) {
        list.innerHTML = `<div class="dashboard-empty"><span aria-hidden="true">✓</span><strong>Nenhum congestionamento</strong><small>Não há ocorrências viárias no período.</small></div>`;
    } else {
        list.innerHTML = jams.map((j) => {
            const speed = j.speedKmh !== null && j.speedKmh !== undefined ? `${Number(j.speedKmh).toFixed(1)} km/h` : '';
            const len = j.length ? `${(j.length / 1000).toFixed(1)} km` : '';
            return `
                <div class="dashboard-data-row">
                    <span class="dashboard-row-icon row-orange" aria-hidden="true">≋</span>
                    <div>
                        <strong>${escapeHtml(j.street || 'Via não informada')}</strong>
                        <small>${escapeHtml(j.city || '—')}${speed ? ' · ' + speed : ''}${len ? ' · ' + len : ''}</small>
                    </div>
                    <span class="dashboard-severity dashboard-jam-level-${j.level}">Nível ${j.level}</span>
                </div>`;
        }).join('');
    }

    const counter = root.querySelector('[data-count="recent-jams"]');
    if (counter) counter.textContent = String(jams?.length ?? 0);
}

function renderRecentRoutes(root, routes) {
    const list = root.querySelector('[data-list="routes"]');
    if (!list) return;

    if (!routes || routes.length === 0) {
        list.innerHTML = `<div class="dashboard-empty"><span aria-hidden="true">✓</span><strong>Nenhuma rota encontrada</strong><small>Sem snapshots vinculados às rotas.</small></div>`;
    } else {
        list.innerHTML = routes.map((r) => {
            const lvl = (r.delay_level && r.delay_level.level) || 'none';
            const iconClass = (lvl === 'severe' || lvl === 'heavy') ? 'row-orange'
                            : (lvl === 'none' ? 'row-green' : 'row-blue');
            const ratio = r.delay_ratio != null ? Math.round(r.delay_ratio * 100) : 0;

            let badge;
            if (r.delay_seconds !== null && r.delay_seconds !== undefined && r.delay_seconds > 0) {
                badge = `+${r.delay_seconds}s <small>(${ratio}%)</small>`;
            } else if (r.jam_level !== null && r.jam_level !== undefined) {
                badge = `Nível ${r.jam_level}`;
            } else {
                badge = 'No prazo';
            }

            return `
                <div class="dashboard-data-row">
                    <span class="dashboard-row-icon ${iconClass}" aria-hidden="true">→</span>
                    <div>
                        <strong>${escapeHtml(r.name || 'Rota')}</strong>
                        <small>
                            ${r.from_name && r.to_name ? escapeHtml(r.from_name) + ' → ' + escapeHtml(r.to_name) + ' · ' : ''}
                            Atual ${r.time ?? '—'}s · Hist. ${r.historic_time ?? '—'}s
                        </small>
                    </div>
                    <span class="dashboard-severity delay-badge delay-badge--${lvl}">${badge}</span>
                </div>`;
        }).join('');
    }

    const counter = root.querySelector('[data-count="recent-routes"]');
    if (counter) counter.textContent = String(routes?.length ?? 0);
}

function renderRecentWeather(root, weather) {
    const list = root.querySelector('[data-list="weather"]');
    if (!list) return;

    if (!weather || weather.length === 0) {
        list.innerHTML = `<div class="dashboard-empty"><span aria-hidden="true">✓</span><strong>Sem leituras climáticas</strong><small>Cadastre uma localização meteorológica.</small></div>`;
        return;
    }

    list.innerHTML = weather.map((obs) => {
        const date = obs.observedAt ? new Date(obs.observedAt) : null;
        const dateStr = date
            ? `${String(date.getDate()).padStart(2,'0')}/${String(date.getMonth()+1).padStart(2,'0')} ${String(date.getHours()).padStart(2,'0')}:${String(date.getMinutes()).padStart(2,'0')}`
            : '';
        let detail = '';
        if (obs.temperature != null) detail += `${Number(obs.temperature).toFixed(1)}°C`;
        if (obs.humidity != null)   detail += `${detail ? ' · ' : ''}${obs.humidity}%`;
        return `
            <div class="dashboard-data-row">
                <span class="dashboard-row-icon row-green" aria-hidden="true">☁</span>
                <div>
                    <strong>${escapeHtml(obs.locationName || 'Estação')}</strong>
                    <small>${escapeHtml(obs.city || '—')} · ${dateStr}</small>
                </div>
                <span class="dashboard-severity">${detail}</span>
            </div>`;
    }).join('');
}

function renderFetchStatus(root, status) {
    if (!status) return;
    const fmt = (iso) => {
        if (!iso) return '—';
        try {
            const d = new Date(iso);
            return d.toLocaleString('pt-BR', {
                day: '2-digit', month: '2-digit', year: 'numeric',
                hour: '2-digit', minute: '2-digit',
            });
        } catch { return '—'; }
    };
    const keys = ['waze_alerts','waze_jams','waze_tvt','weather','cemaden_hydro','cemaden_pluvio','cameras'];
    keys.forEach((k) => {
        const el = root.querySelector(`[data-fetch="${k}"]`);
        if (el) el.textContent = fmt(status[k]);
    });
}

function renderHydroStations(root, hydro) {
    if (!hydro) return;
    const panel = root.querySelector('[data-hydro-panel]');
    if (!panel) return;

    // dataset dos trends
    panel.dataset.hydroTrendAll = JSON.stringify(hydro.trend || []);
    panel.dataset.hydroTrendByStation = JSON.stringify(hydro.trend_by_station || {});

    // strip
    const risk = hydro.stats?.risk || {};
    const map = {
        stations: hydro.stats?.stations ?? 0,
        alert: (risk.overflow || 0) + (risk.alert || 0),
        attention: risk.attention || 0,
        normal: risk.normal || 0,
    };
    Object.entries(map).forEach(([k, v]) => {
        const el = panel.querySelector(`[data-hydro-stat="${k}"]`);
        if (el) el.textContent = String(v);
    });

    // lista de seleção
    const picker = panel.querySelector('[data-hydro-picker]');
    const list = hydro.stations_list || [];
    if (!picker) return;

    if (list.length <= 1) {
        picker.innerHTML = '';
        return;
    }

    const riskClass = (r) => `hydro-station-btn--${r || 'unknown'}`;
    const active = (id) => (String(selectedHydroStation) === String(id) ? 'is-active' : '');

    picker.innerHTML = `
        <div class="dashboard-station-picker-head">
            <strong>Rios monitorados</strong>
            <small>${list.length} estações</small>
        </div>
        <button type="button"
                class="hydro-station-btn hydro-station-btn--all ${active('all')}"
                data-hydro-select="all">
            <span class="hydro-station-btn-name">Todas as estações</span>
            <small>Média agregada</small>
        </button>
        ${list.map((st) => `
            <button type="button"
                    class="hydro-station-btn ${riskClass(st.risk)} ${active(st.id)}"
                    data-hydro-select="${st.id}">
                <span class="hydro-station-btn-name">${escapeHtml(st.name)}</span>
                <small>${escapeHtml(st.city || '—')}${st.state ? '/' + escapeHtml(st.state) : ''}</small>
                <span class="hydro-station-btn-level">${st.level != null ? Number(st.level).toFixed(2).replace('.', ',') + ' m' : '—'}</span>
            </button>
        `).join('')}
    `;

    // reattach handlers
    picker.querySelectorAll('[data-hydro-select]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.hydroSelect;
            selectedHydroStation = id;
            picker.querySelectorAll('[data-hydro-select]').forEach((b) =>
                b.classList.toggle('is-active', b === btn));

            const all = safeJson(panel.dataset.hydroTrendAll, []);
            const byStation = safeJson(panel.dataset.hydroTrendByStation, {});
            const data = id === 'all' ? all : (byStation[id] || []);
            const canvas = panel.querySelector('[data-chart="hydro-trend"]');
            if (canvas) {
                canvas.dataset.chartData = JSON.stringify(data);
                initDashboardCharts(root);
            }
        });
    });
}

function renderPluvioStations(root, pluvio) {
    if (!pluvio) return;
    const panel = root.querySelector('[data-pluvio-panel]');
    if (!panel) return;

    panel.dataset.pluvioHourlyAll = JSON.stringify(pluvio.hourly || []);
    panel.dataset.pluvioHourlyByStation = JSON.stringify(pluvio.hourly_by_station || {});

    // strip
    const s = pluvio.stats || {};
    const stripMap = {
        stations: s.stations ?? 0,
        rain_1h: Number(s.rain_1h ?? 0).toFixed(1).replace('.', ',') + '<small style="font-size:11px;font-weight:600;color:var(--dash-muted);margin-left:4px">mm</small>',
        rain_24h: Number(s.rain_24h ?? 0).toFixed(1).replace('.', ',') + '<small style="font-size:11px;font-weight:600;color:var(--dash-muted);margin-left:4px">mm</small>',
        peak_24h: Number(s.peak_24h ?? 0).toFixed(1).replace('.', ',') + '<small style="font-size:11px;font-weight:600;color:var(--dash-muted);margin-left:4px">mm/h</small>',
    };
    Object.entries(stripMap).forEach(([k, v]) => {
        const el = panel.querySelector(`[data-pluvio-stat="${k}"]`);
        if (el) el.innerHTML = String(v);
    });

    // list
    const picker = panel.querySelector('[data-pluvio-picker]');
    if (!picker) return;

    const stations = pluvio.stations || [];
    const peak = Number(s.peak_24h ?? 0);
    const active = (id) => (String(selectedPluvioStation) === String(id) ? 'is-active' : '');

    picker.innerHTML = `
        <div class="dashboard-station-picker-head">
            <strong>Chuva por estação · 24h</strong>
            <small>${stations.length} pontos</small>
        </div>
        <button type="button"
                class="hydro-station-btn hydro-station-btn--all ${active('all')}"
                data-pluvio-select="all">
            <span class="hydro-station-btn-name">Todas as estações</span>
            <small>Média agregada</small>
        </button>
        ${stations.length === 0
            ? `<div class="dashboard-empty"><span aria-hidden="true">✓</span><strong>Sem leituras de chuva</strong><small>Cadastre um pluviômetro ou estação hidro com dados de chuva.</small></div>`
            : stations.map((st) => {
                const pct = peak > 0 ? Math.min(100, (st.rain24h / peak) * 100) : 0;
                return `
                    <button type="button"
                            class="hydro-station-btn pluvio-station-btn pluvio-station-btn--${st.source} ${active(st.stationId)}"
                            data-pluvio-select="${escapeHtml(st.stationId)}"
                            style="--pct:${pct}">
                        <span class="hydro-station-btn-name">
                            ${escapeHtml(st.stationName)}
                            <span class="pluvio-source-badge pluvio-source-badge--${st.source}">
                                ${st.source === 'hydro' ? 'Hidro' : 'Auto'}
                            </span>
                        </span>
                        <small>${escapeHtml(st.city || '—')}${st.state ? '/' + escapeHtml(st.state) : ''}</small>
                        <span class="hydro-station-btn-level">${Number(st.rain24h).toFixed(1).replace('.', ',')} mm</span>
                    </button>`;
            }).join('')
        }
    `;

    picker.querySelectorAll('[data-pluvio-select]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.pluvioSelect;
            selectedPluvioStation = id;
            picker.querySelectorAll('[data-pluvio-select]').forEach((b) =>
                b.classList.toggle('is-active', b === btn));

            const all = safeJson(panel.dataset.pluvioHourlyAll, []);
            const byStation = safeJson(panel.dataset.pluvioHourlyByStation, {});
            const data = id === 'all' ? all : (byStation[id] || []);
            const canvas = panel.querySelector('[data-chart="pluvio-hourly"]');
            if (canvas) {
                canvas.dataset.chartData = JSON.stringify(data);
                initDashboardCharts(root);
            }
        });
    });
}

function hydrateDashboard(data, root) {
    if (!data) return;

    renderKPIs(root, data.kpis, data.totals);

    if (data.charts) {
        setChartData(root, 'hourly-activity', data.charts.hourly_activity || []);
        setChartData(root, 'alerts-by-type',  data.charts.alerts_by_type || []);
        setChartData(root, 'alerts-by-city',  data.charts.alerts_by_city || []);
        setChartData(root, 'alerts-by-street',data.charts.alerts_by_street || []);
        setChartData(root, 'jams-by-level',   data.charts.jams_by_level || []);
        setChartData(root, 'weather-trend',   data.charts.weather_trend || []);
    }

    renderHydroStations(root, data.hydro);
    renderPluvioStations(root, data.pluvio);
    refreshDashboardMap(data.map);

    renderRecentAlerts(root, data.recent?.alerts);
    renderRecentJams(root, data.recent?.jams);
    renderRecentRoutes(root, data.recent?.routes);
    renderRecentWeather(root, data.recent?.weather);

    renderFetchStatus(root, data.fetch_status);

    // re-renderiza charts com os dados atualizados
    initDashboardCharts(root);

    // reinicializa counters
    root.querySelectorAll('[data-count-value]').forEach((el) => {
        const v = Number(el.dataset.countValue ?? 0);
        if (Number.isFinite(v)) el.textContent = String(v);
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
    initHydroPicker(root);
    initPluvioPicker(root);
    attachDashboardLiveUpdates(root);
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => initDashboard());
    } else {
        initDashboard();
    }
}
