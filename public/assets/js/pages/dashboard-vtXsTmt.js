/**
 * pages/dashboard.js — v2
 *
 * Dashboard operacional com:
 *  - AJAX granular (6 endpoints parciais em paralelo, não o /data inteiro)
 *  - Auto-refresh a cada 120s alinhado ao cron do backend
 *  - Preservação de seleção hidro/pluvio entre atualizações
 *  - Reutilização de instâncias Chart.js (sem rebuild destrutivo)
 *  - Correções de XSS, recentralização de mapa, a11y e loading state
 *
 * Inicializado pelo registry do `app.js` quando `[data-dashboard]` existe.
 */

// ─────────────────────────────────────────────────────────────────────────
// Constantes
// ─────────────────────────────────────────────────────────────────────────

const AUTO_REFRESH_MS = 120_000;  // 2min — alinhado ao cron do backend
const STALE_THRESHOLD_MS = 30_000; // ao voltar para a aba, refetch se >30s stale

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

/** Retorna URL apenas se for http(s). Bloqueia javascript:, data:, file:, etc. */
function safeUrl(raw) {
    if (!raw) return null;
    try {
        const u = new URL(String(raw), window.location.origin);
        return /^https?:$/.test(u.protocol) ? u.toString() : null;
    } catch { return null; }
}

function safeJson(str, fallback = []) {
    try { return JSON.parse(str); } catch { return fallback; }
}

const rtf = typeof Intl !== 'undefined' && Intl.RelativeTimeFormat
    ? new Intl.RelativeTimeFormat('pt-BR', { numeric: 'auto' })
    : null;

function timeAgo(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    if (Number.isNaN(d.getTime())) return '';
    const diffMin = Math.round((d.getTime() - Date.now()) / 60_000);
    if (!rtf) return d.toLocaleString('pt-BR');
    if (Math.abs(diffMin) < 60) return rtf.format(diffMin, 'minute');
    const diffH = Math.round(diffMin / 60);
    if (Math.abs(diffH) < 24) return rtf.format(diffH, 'hour');
    return rtf.format(Math.round(diffH / 24), 'day');
}

const nfBR = new Intl.NumberFormat('pt-BR');

function formatNumber(v) {
    return nfBR.format(Number(v) || 0);
}

// ─────────────────────────────────────────────────────────────────────────
// Estado global
// ─────────────────────────────────────────────────────────────────────────

const charts = new Map();
let selectedHydroStation = 'all';
let selectedPluvioStation = 'all';
let autoRefreshTimer = null;
let lastAutoRefreshAt = 0;
let isRefreshing = false;
let bootstrapped = false;

// ─────────────────────────────────────────────────────────────────────────
// Clock
// ─────────────────────────────────────────────────────────────────────────

export function initDashboardClock(doc = document) {
    const clock = doc.querySelector('[data-dashboard-clock]');
    if (!clock || clock.dataset.clockInit === '1') return;
    clock.dataset.clockInit = '1';

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
        animateCounter(el, target);
    });
}

function animateCounter(el, target, duration = 700) {
    const start = performance.now();
    const tick = (now) => {
        const p = Math.min((now - start) / duration, 1);
        const eased = 1 - Math.pow(1 - p, 3);
        el.textContent = formatNumber(Math.round(target * eased));
        if (p < 1) requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
}

// ─────────────────────────────────────────────────────────────────────────
// Loading state (global)
// ─────────────────────────────────────────────────────────────────────────

function setLoading(root, active) {
    const page = root.querySelector('[data-dashboard]');
    if (!page) return;
    page.classList.toggle('is-loading', active);
    page.setAttribute('aria-busy', String(active));
}

// ─────────────────────────────────────────────────────────────────────────
// Charts — reutilização inteligente
// ─────────────────────────────────────────────────────────────────────────

function parseData(canvas) {
    try { return JSON.parse(canvas.dataset.chartData || '[]'); }
    catch { return []; }
}

function destroyChart(key) {
    const existing = charts.get(key);
    if (existing) { existing.destroy(); charts.delete(key); }
}

/**
 * Monta ou atualiza um chart.
 * - Se já existe com o MESMO canvas e MESMO tipo → atualiza dados/options e
 *   chama update('none'). Isso preserva animação, estado do legend, hover etc.
 * - Caso contrário, destrói e recria.
 */
function mountChart(key, canvas, config) {
    if (!canvas || typeof Chart === 'undefined') return;
    const existing = charts.get(key);

    if (existing
        && existing.canvas === canvas
        && existing.config && existing.config.type === config.type) {
        existing.data = config.data;
        existing.options = config.options;
        existing.update('none');
        return;
    }

    destroyChart(key);
    charts.set(key, new Chart(canvas.getContext('2d'), config));
}

/** Gera chave única por canvas (evita colisão quando há 2 charts do mesmo tipo). */
function chartKey(canvas, name) {
    if (!canvas.dataset.__chartUid) {
        canvas.dataset.__chartUid = Math.random().toString(36).slice(2, 10);
    }
    return `${name}:${canvas.dataset.__chartUid}`;
}

export function initDashboardCharts(root = document) {
    if (typeof Chart === 'undefined') return;

    Chart.defaults.color = COLORS.text;
    Chart.defaults.font.family = "Inter, system-ui, -apple-system, 'Segoe UI', sans-serif";
    Chart.defaults.font.size = 12;

    // 1) Atividade por hora
    root.querySelectorAll('[data-chart="hourly-activity"]').forEach((canvas) => {
        const points = parseData(canvas);
        const labels = points.map((p) => p.hour);
        const alerts = points.map((p) => Number(p.alerts) || 0);
        const jams   = points.map((p) => Number(p.jams) || 0);

        mountChart(chartKey(canvas, 'hourly'), canvas, {
            type: 'line',
            data: {
                labels,
                datasets: [
                    {
                        label: 'Alertas', data: alerts,
                        borderColor: COLORS.blue,
                        backgroundColor: 'rgba(37,99,235,.15)',
                        fill: true, tension: 0.35, pointRadius: 0, borderWidth: 2,
                    },
                    {
                        label: 'Jams', data: jams,
                        borderColor: COLORS.orange,
                        backgroundColor: 'rgba(234,88,12,.10)',
                        fill: true, tension: 0.35, pointRadius: 0, borderWidth: 2,
                    },
                ],
            },
            options: {
                maintainAspectRatio: false,
                animation: false,
                plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } },
                scales: {
                    x: { grid: { display: false } },
                    y: { grid: { color: COLORS.grid }, beginAtZero: true },
                },
            },
        });
    });

    // 2) Alertas por tipo
    root.querySelectorAll('[data-chart="alerts-by-type"]').forEach((canvas) => {
        const points = parseData(canvas);
        const labels = points.map((p) => p.label);
        const data   = points.map((p) => Number(p.total) || 0);

        mountChart(chartKey(canvas, 'alertsByType'), canvas, {
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
                animation: false,
                cutout: '62%',
                plugins: { legend: { position: 'right', labels: { usePointStyle: true, boxWidth: 8 } } },
            },
        });
    });

    // 3) Alertas por cidade
    root.querySelectorAll('[data-chart="alerts-by-city"]').forEach((canvas) => {
        const points = parseData(canvas);
        const labels = points.map((p) => p.label);
        const data   = points.map((p) => Number(p.total) || 0);

        mountChart(chartKey(canvas, 'alertsByCity'), canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Alertas', data,
                    backgroundColor: 'rgba(37,99,235,.75)',
                    borderRadius: 6,
                }],
            },
            options: {
                indexAxis: 'y',
                maintainAspectRatio: false,
                animation: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { color: COLORS.grid }, beginAtZero: true },
                    y: { grid: { display: false } },
                },
            },
        });
    });

    // 4) Alertas por rua
    root.querySelectorAll('[data-chart="alerts-by-street"]').forEach((canvas) => {
        const points = parseData(canvas);
        const labels = points.map((p) => p.label);
        const data   = points.map((p) => Number(p.total) || 0);

        mountChart(chartKey(canvas, 'alertsByStreet'), canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Alertas', data,
                    backgroundColor: 'rgba(124,58,237,.75)',
                    borderRadius: 6,
                }],
            },
            options: {
                indexAxis: 'y',
                maintainAspectRatio: false,
                animation: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { color: COLORS.grid }, beginAtZero: true },
                    y: { grid: { display: false } },
                },
            },
        });
    });

    // 5) Jams por nível
    root.querySelectorAll('[data-chart="jams-by-level"]').forEach((canvas) => {
        const points = parseData(canvas);
        const labels = points.map((p) => p.label);
        const data   = points.map((p) => Number(p.total) || 0);

        mountChart(chartKey(canvas, 'jamsByLevel'), canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Jams', data,
                    backgroundColor: 'rgba(234,88,12,.75)',
                    borderRadius: 6,
                }],
            },
            options: {
                maintainAspectRatio: false,
                animation: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false } },
                    y: { grid: { color: COLORS.grid }, beginAtZero: true },
                },
            },
        });
    });

    // 6) Tendência climática
    root.querySelectorAll('[data-chart="weather-trend"]').forEach((canvas) => {
        const points = parseData(canvas);
        const labels = points.map((p) => (p.time || '').slice(11, 16));
        const temp   = points.map((p) => p.temperature);
        const hum    = points.map((p) => p.humidity);
        const rain   = points.map((p) => p.precipitation);

        mountChart(chartKey(canvas, 'weatherTrend'), canvas, {
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
                animation: false,
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

    // 7) Tendência hidrológica
    root.querySelectorAll('[data-chart="hydro-trend"]').forEach((canvas) => {
        const points = parseData(canvas);
        const labels = points.map((p) => (p.time || '').slice(5, 16));
        const levels = points.map((p) => p.avg_level);
        const atencao = points.map((p) => p.cota_atencao);
        const alerta  = points.map((p) => p.cota_alerta);

        mountChart(chartKey(canvas, 'hydroTrend'), canvas, {
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
                animation: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } },
                scales: {
                    x: { grid: { display: false } },
                    y: { grid: { color: COLORS.grid }, title: { display: true, text: 'metros' } },
                },
            },
        });
    });

    // 8) Pluviometria horária
    root.querySelectorAll('[data-chart="pluvio-hourly"]').forEach((canvas) => {
        const points = parseData(canvas);
        const labels = points.map((p) => {
            const t = (p.time || '');
            return t.length >= 16 ? t.slice(11, 16) : t;
        });
        const rain = points.map((p) => Number(p.rain) || 0);
        const wet  = points.map((p) => Number(p.wet_stations) || 0);

        mountChart(chartKey(canvas, 'pluvioHourly'), canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Chuva (mm)', data: rain,
                    backgroundColor: rain.map((v) => v > 0 ? 'rgba(37,99,235,.75)' : 'rgba(148,163,184,.35)'),
                    borderColor: 'rgba(37,99,235,.95)',
                    borderWidth: 1, borderRadius: 4,
                }],
            },
            options: {
                maintainAspectRatio: false,
                animation: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const i = ctx.dataIndex;
                                const v = ctx.parsed.y;
                                if (!v) return 'Sem chuva';
                                return `Chuva: ${Number(v).toFixed(1)} mm · ${wet[i] || 0} ponto(s)`;
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
// Hydro / Pluvio pickers (delegação de eventos)
// ─────────────────────────────────────────────────────────────────────────

/**
 * @param {HTMLElement} panel
 * @param {Document|HTMLElement} root
 * @param {boolean} skipChartInit — true para evitar re-init dentro de hydrate
 */
function applyHydroSelection(panel, root, skipChartInit = false) {
    const all = safeJson(panel.dataset.hydroTrendAll, []);
    const byStation = safeJson(panel.dataset.hydroTrendByStation, {});
    const data = selectedHydroStation === 'all'
        ? all
        : (byStation[selectedHydroStation] || []);

    const canvas = panel.querySelector('[data-chart="hydro-trend"]');
    if (!canvas) return;
    canvas.dataset.chartData = JSON.stringify(data);

    if (!skipChartInit) initDashboardCharts(root);
}

function applyPluvioSelection(panel, root, skipChartInit = false) {
    const all = safeJson(panel.dataset.pluvioHourlyAll, []);
    const byStation = safeJson(panel.dataset.pluvioHourlyByStation, {});
    const data = selectedPluvioStation === 'all'
        ? all
        : (byStation[selectedPluvioStation] || []);

    const canvas = panel.querySelector('[data-chart="pluvio-hourly"]');
    if (!canvas) return;
    canvas.dataset.chartData = JSON.stringify(data);

    if (!skipChartInit) initDashboardCharts(root);
}

export function initHydroPicker(root = document) {
    const panel = root.querySelector('[data-hydro-panel]');
    if (!panel || panel.dataset.hydroDelegated === '1') return;
    panel.dataset.hydroDelegated = '1';

    panel.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-hydro-select]');
        if (!btn || !panel.contains(btn)) return;

        selectedHydroStation = btn.dataset.hydroSelect;

        panel.querySelectorAll('[data-hydro-select]').forEach((b) =>
            b.classList.toggle('is-active', b === btn));

        applyHydroSelection(panel, root);
    });
}

export function initPluvioPicker(root = document) {
    const panel = root.querySelector('[data-pluvio-panel]');
    if (!panel || panel.dataset.pluvioDelegated === '1') return;
    panel.dataset.pluvioDelegated = '1';

    panel.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-pluvio-select]');
        if (!btn || !panel.contains(btn)) return;

        selectedPluvioStation = btn.dataset.pluvioSelect;

        panel.querySelectorAll('[data-pluvio-select]').forEach((b) =>
            b.classList.toggle('is-active', b === btn));

        applyPluvioSelection(panel, root);
    });
}

// ─────────────────────────────────────────────────────────────────────────
// Mapa (Leaflet)
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

    // Mesmo container e mapa já montado → apenas atualiza as camadas
    if (leafletMap && mapContainer === container) {
        populateDashboardMap(container);
        return;
    }

    if (leafletMap) {
        leafletMap.remove();
        leafletMap = null;
        layerGroups = {};
    }

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
        if (btn.dataset.layerToggleInit === '1') return;
        btn.dataset.layerToggleInit = '1';

        btn.addEventListener('click', () => {
            const key = btn.dataset.layerToggle;
            const group = layerGroups[key];
            if (!group || !leafletMap) return;
            const active = leafletMap.hasLayer(group);
            if (active) leafletMap.removeLayer(group);
            else leafletMap.addLayer(group);
            btn.classList.toggle('is-active', !active);
        });
    });

    if (!window.__dashMapResizeBound) {
        window.__dashMapResizeBound = true;
        window.addEventListener('resize', () => {
            if (leafletMap) leafletMap.invalidateSize();
        });
    }

    window.dashboardMap = leafletMap;
}

function popupCard({ color, title, subtitle, body, footer }) {
    const dot = color
        ? `<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:${color};margin-right:6px"></span>`
        : '';
    return `<div style="font-family:Inter,system-ui,sans-serif;min-width:200px;max-width:280px">
        <div style="display:flex;align-items:center;gap:2px;margin-bottom:4px">
            ${dot}<strong style="font-size:13px">${escapeHtml(title)}</strong>
        </div>
        ${subtitle ? `<div style="color:#64748b;font-size:11.5px;margin-bottom:4px">${escapeHtml(subtitle)}</div>` : ''}
        <div style="font-size:12.5px;line-height:1.5">${body || ''}</div>
        ${footer ? `<div style="margin-top:6px;color:#94a3b8;font-size:11px">${escapeHtml(footer)}</div>` : ''}
    </div>`;
}

function populateDashboardMap(container) {
    if (!leafletMap || !container) return;

    // Recentraliza SEMPRE que repopula (só acontece em filtro manual / refresh manual)
    const center = safeJson(container.dataset.mapCenter, null);
    if (center && Number.isFinite(center.lat) && Number.isFinite(center.lng)) {
        leafletMap.setView([center.lat, center.lng], center.zoom ?? 11, { animate: false });
    }

    Object.values(layerGroups).forEach((g) => g && g.clearLayers());

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
        }).bindPopup(popupCard({
            color,
            title: a.type || 'Alerta',
            subtitle: a.subtype || '',
            body: `${escapeHtml(a.street || '')}${a.street && a.city ? ' — ' : ''}${escapeHtml(a.city || '')}`,
            footer: timeAgo(a.when),
        })).addTo(layerGroups.alerts);
    });

    jams.forEach((j) => {
        if (!Array.isArray(j.path) || j.path.length < 2) return;
        const color = jamColor(Number(j.level) || 0);
        // Padrão tracejado em níveis críticos (daltonismo)
        const dashArray = (Number(j.level) || 0) >= 4 ? '8 5' : null;
        L.polyline(j.path, {
            color, weight: 5, opacity: 0.85,
            dashArray,
        }).bindPopup(popupCard({
            color,
            title: `Congestionamento — nível ${j.level}`,
            subtitle: `${escapeHtml(j.street || '')}${j.city ? ' — ' + escapeHtml(j.city) : ''}`,
            body: `Atraso: <strong>${formatNumber(j.delay || 0)}s</strong><br>Extensão: ${((j.length || 0) / 1000).toFixed(1)} km`,
        })).addTo(layerGroups.jams);
    });

    cameras.forEach((c) => {
        if (!Number.isFinite(c.lat) || !Number.isFinite(c.lng)) return;
        const camUrl = safeUrl(c.url); // XSS-safe
        const body = [
            camUrl
                ? `<a href="${escapeHtml(camUrl)}" target="_blank" rel="noopener noreferrer">Abrir stream ↗</a>`
                : (c.url ? '<em>URL inválida</em>' : ''),
        ].filter(Boolean).join('<br>');

        L.circleMarker([c.lat, c.lng], {
            radius: 5, color: COLORS.purple, weight: 1,
            fillColor: COLORS.purple, fillOpacity: 0.85,
        }).bindPopup(popupCard({
            color: COLORS.purple,
            title: c.name || 'Câmera',
            subtitle: `${c.city || ''}${c.state ? ' / ' + c.state : ''}`,
            body,
        })).addTo(layerGroups.cameras);
    });

    weather.forEach((w) => {
        if (!Number.isFinite(w.lat) || !Number.isFinite(w.lng)) return;
        L.circleMarker([w.lat, w.lng], {
            radius: 5, color: COLORS.green, weight: 1,
            fillColor: COLORS.green, fillOpacity: 0.8,
        }).bindPopup(popupCard({
            color: COLORS.green,
            title: w.name || 'Estação',
            subtitle: `${w.city || ''} · ${w.provider || ''}`,
            body: '',
        })).addTo(layerGroups.weather);
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
        }).bindPopup(popupCard({
            color,
            title: s.name || 'Estação CEMADEN',
            subtitle: `${s.city || ''}${s.state ? ' / ' + s.state : ''} · ${s.type || ''}`,
            body: `Chuva 1h: <strong>${rain1h.toFixed(1)} mm</strong><br>Chuva 24h: <strong>${rain24h.toFixed(1)} mm</strong>`,
            footer: timeAgo(s.lastReading),
        })).addTo(layerGroups.stations);
    });
}

/** Repopula o mapa após AJAX. */
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
// Fetch — endpoints granulares em paralelo
// ─────────────────────────────────────────────────────────────────────────

function getApiBase(root) {
    const el = root.querySelector('[data-dashboard]');
    const endpoint = el?.dataset.apiEndpoint || '/dashboard/api/data';
    return endpoint.replace(/\/data$/, '');
}

function currentFilterQs() {
    const form = document.querySelector('[data-filter-form]');
    if (!form) return '';
    const fd = new FormData(form);
    const params = new URLSearchParams();
    for (const [k, v] of fd.entries()) {
        const val = String(v).trim();
        if (val !== '') params.set(k, val);
    }
    return params.toString();
}

async function fetchJson(url, signal) {
    const res = await fetch(url, {
        headers: { 'Accept': 'application/json' },
        cache: 'no-store',
        signal,
    });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return res.json();
}

/**
 * Busca o payload completo usando 6 endpoints parciais em paralelo.
 * Cada um é cacheável independentemente e o payload combinado é o mesmo
 * que o /api/data retornaria, mas com round-trips paralelos.
 */
async function fetchAllPartial(root, params, signal) {
    const base = getApiBase(root);
    const qs = new URLSearchParams(params).toString();

    const [summary, charts, map, recent, hydro, pluvio] = await Promise.all([
        fetchJson(`${base}/summary?${qs}`, signal),
        fetchJson(`${base}/charts?${qs}`,  signal),
        fetchJson(`${base}/map?${qs}`,     signal),
        fetchJson(`${base}/recent?${qs}`,  signal),
        fetchJson(`${base}/hydro?${qs}`,   signal),
        fetchJson(`${base}/pluvio?${qs}`,  signal),
    ]);

    return {
        ...(summary?.data || {}),
        ...(charts?.data  || {}),
        ...(map?.data     || {}),
        ...(recent?.data  || {}),
        ...(hydro?.data   || {}),
        ...(pluvio?.data  || {}),
    };
}

// ─────────────────────────────────────────────────────────────────────────
// Filters (AJAX + pushState + popstate + loading + feedback)
// ─────────────────────────────────────────────────────────────────────────

export function initDashboardFilters(root = document) {
    const form = root.querySelector('[data-filter-form]');
    if (!form || form.dataset.filterInit === '1') return;
    form.dataset.filterInit = '1';

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
        history.pushState({ filters: params }, '', url.toString());
    };

    const applyFilters = async (params) => {
        if (isRefreshing) return;
        setLoading(root, true);
        setFeedback('Aplicando filtros...');

        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 30_000);

        try {
            const data = await fetchAllPartial(root, params, controller.signal);
            hydrateDashboard(data, root);
            pushUrl(params);
            setFeedback(`Filtros aplicados · ${new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })}`);
            lastAutoRefreshAt = Date.now();
        } catch (err) {
            if (err.name === 'AbortError') {
                setFeedback('A requisição demorou demais. Tente novamente.', true);
            } else {
                setFeedback('Falha ao aplicar filtros: ' + err.message, true);
            }
        } finally {
            clearTimeout(timeoutId);
            setLoading(root, false);
        }
    };

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        applyFilters(collect());
    });

    const clearAll = () => {
        form.reset();
        applyFilters({});
    };

    form.querySelectorAll('[data-action="clear-filters"]').forEach((btn) => {
        btn.addEventListener('click', clearAll);
    });

    root.querySelectorAll('[data-action="clear-filters"]').forEach((btn) => {
        if (form.contains(btn)) return;
        btn.addEventListener('click', clearAll);
    });

    // Botão "Atualizar" do header → full refresh com mesmos filtros
    const refreshBtn = root.querySelector('[data-action="refresh-dashboard"]');
    if (refreshBtn && refreshBtn.dataset.refreshInit !== '1') {
        refreshBtn.dataset.refreshInit = '1';
        refreshBtn.addEventListener('click', async () => {
            refreshBtn.classList.add('is-loading');
            refreshBtn.setAttribute('aria-busy', 'true');
            try {
                await applyFilters(collect());
            } finally {
                refreshBtn.classList.remove('is-loading');
                refreshBtn.removeAttribute('aria-busy');
            }
        });
    }

    // popstate — botão voltar/avançar do navegador
    window.addEventListener('popstate', () => {
        const params = new URLSearchParams(window.location.search);
        for (const [k, v] of params) {
            const f = form.elements.namedItem(k);
            if (f) f.value = v;
        }
        // Limpa campos que saíram da URL
        Array.from(form.elements).forEach((f) => {
            if (!f.name) return;
            if (!params.has(f.name)) f.value = '';
        });
        applyFilters(collect());
    });
}

// ─────────────────────────────────────────────────────────────────────────
// Auto-refresh (120s, /summary)
// ─────────────────────────────────────────────────────────────────────────

async function refreshSummary(root) {
    if (isRefreshing) return;
    const base = getApiBase(root);
    const qs = currentFilterQs();
    isRefreshing = true;

    try {
        const json = await fetchJson(`${base}/summary?${qs}`);
        const data = json?.data;
        if (data) {
            renderKPIs(root, data.kpis, data.totals);
            renderFetchStatus(root, data.fetch_status);
            if (data.health_score !== undefined) {
                updateHealthRing(root, data.health_score);
            }
            lastAutoRefreshAt = Date.now();

            const tsEl = root.querySelector('[data-auto-refresh-ts]');
            if (tsEl) {
                tsEl.textContent = new Date().toLocaleTimeString('pt-BR', {
                    hour: '2-digit', minute: '2-digit',
                });
            }
        }
    } catch (err) {
        console.warn('[dashboard] auto-refresh falhou:', err.message);
    } finally {
        isRefreshing = false;
    }
}

export function startAutoRefresh(root = document) {
    stopAutoRefresh();
    lastAutoRefreshAt = Date.now();

    autoRefreshTimer = window.setInterval(() => {
        if (!document.hidden) refreshSummary(root);
    }, AUTO_REFRESH_MS);

    const onVisibility = () => {
        if (document.hidden) return;
        if (Date.now() - lastAutoRefreshAt >= STALE_THRESHOLD_MS) {
            refreshSummary(root);
        }
    };

    if (!window.__dashVisibilityBound) {
        window.__dashVisibilityBound = true;
        document.addEventListener('visibilitychange', onVisibility);
    }
}

export function stopAutoRefresh() {
    if (autoRefreshTimer) {
        clearInterval(autoRefreshTimer);
        autoRefreshTimer = null;
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Keyboard shortcuts
// ─────────────────────────────────────────────────────────────────────────

export function initDashboardShortcuts(root = document) {
    if (window.__dashKeysBound) return;
    window.__dashKeysBound = true;

    document.addEventListener('keydown', (e) => {
        // Ignora quando digitando
        if (e.target.closest('input, textarea, select, [contenteditable="true"]')) {
            if (e.key === 'Escape') {
                e.target.blur();
            }
            return;
        }
        if (e.ctrlKey || e.metaKey || e.altKey) return;

        switch (e.key.toLowerCase()) {
            case 'r':
                e.preventDefault();
                root.querySelector('[data-action="refresh-dashboard"]')?.click();
                break;
            case 'f':
            case '/':
                e.preventDefault();
                root.querySelector('[data-filter="query"]')?.focus();
                break;
            case 'escape':
                e.preventDefault();
                root.querySelector('[data-action="clear-filters"]')?.click();
                break;
            case '?':
                e.preventDefault();
                showShortcutsHelp();
                break;
        }
    });
}

function showShortcutsHelp() {
    // Silencioso se já houver — só toast simples via console + alert acessível
    const existing = document.querySelector('[data-shortcuts-help]');
    if (existing) return;

    const dialog = document.createElement('div');
    dialog.setAttribute('data-shortcuts-help', '');
    dialog.setAttribute('role', 'dialog');
    dialog.setAttribute('aria-modal', 'true');
    dialog.setAttribute('aria-label', 'Atalhos de teclado');
    dialog.style.cssText = `
        position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:1000;
        display:flex;align-items:center;justify-content:center;padding:20px;
    `;
    dialog.innerHTML = `
        <div style="background:#fff;border-radius:14px;padding:24px;max-width:400px;width:100%;box-shadow:0 24px 60px rgba(15,23,42,.25)">
            <h3 style="margin:0 0 14px;font-size:16px">Atalhos de teclado</h3>
            <table style="width:100%;font-size:13px;border-collapse:collapse">
                <tr><td style="padding:6px 0"><kbd>R</kbd></td><td>Atualizar dados</td></tr>
                <tr><td style="padding:6px 0"><kbd>F</kbd> ou <kbd>/</kbd></td><td>Ir para busca</td></tr>
                <tr><td style="padding:6px 0"><kbd>Esc</kbd></td><td>Limpar filtros</td></tr>
                <tr><td style="padding:6px 0"><kbd>?</kbd></td><td>Mostrar esta ajuda</td></tr>
            </table>
            <button type="button" style="margin-top:14px;padding:8px 14px;border:0;border-radius:8px;background:#2563eb;color:#fff;font-weight:600;cursor:pointer;font-family:inherit">
                Fechar
            </button>
        </div>
    `;
    dialog.querySelector('button').addEventListener('click', () => dialog.remove());
    dialog.addEventListener('click', (e) => { if (e.target === dialog) dialog.remove(); });
    document.body.appendChild(dialog);
    dialog.querySelector('button').focus();
}

// ─────────────────────────────────────────────────────────────────────────
// Live updates via CustomEvent
// ─────────────────────────────────────────────────────────────────────────

export function attachDashboardLiveUpdates(root = document) {
    if (window.__dashLiveBound) return;
    window.__dashLiveBound = true;

    window.addEventListener('dashboard:update', (e) => {
        hydrateDashboard(e.detail, root);
    });
}

// ─────────────────────────────────────────────────────────────────────────
// Renderizadores
// ─────────────────────────────────────────────────────────────────────────

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
        if (!el) return;
        el.dataset.countValue = String(val);
        el.textContent = formatNumber(val);
    });

    const setTrend = (name, text, extraClass = '') => {
        const el = root.querySelector(`[data-kpi-trend="${name}"]`);
        if (!el) return;
        el.textContent = text;
        el.classList.remove('is-warning', 'is-positive');
        if (extraClass) el.classList.add(extraClass);
    };

    if (totals) {
        setTrend('alerts_active', `${totals.alerts_active ?? 0} ativos`);
        setTrend('jams_active', `${totals.jams_active ?? 0} ativos`,
            (totals.jams_active ?? 0) > 10 ? 'is-warning' : '');
    }

    if (kpis.routes_delayed !== undefined) {
        setTrend('routes_delayed', `${kpis.routes_delayed} atrasadas`,
            kpis.routes_delayed > 0 ? 'is-warning' : '');
    }
    if (kpis.hydro_at_risk !== undefined) {
        setTrend('hydro_at_risk', `${kpis.hydro_at_risk} em atenção`,
            kpis.hydro_at_risk > 0 ? 'is-warning' : '');
    }
    if (kpis.pluvio_rain_24h !== undefined) {
        const v = Number(kpis.pluvio_rain_24h);
        setTrend('pluvio_rain_24h',
            `${v.toFixed(1).replace('.', ',')} mm · 24h`,
            v > 0 ? 'is-positive' : '');
    }
}

function updateHealthRing(root, score) {
    const ring = root.querySelector('.dashboard-health-ring');
    if (!ring) return;
    const s = Math.max(0, Math.min(100, Number(score) || 0));
    ring.style.setProperty('--health-score', String(s));
    const span = ring.querySelector('span');
    if (span) span.innerHTML = `${s}<small>%</small>`;
}

function renderRecentAlerts(root, alerts) {
    const list = root.querySelector('[data-list="alerts"]');
    if (!list) return;

    if (!alerts || alerts.length === 0) {
        list.innerHTML = emptyState('Nenhum alerta recente', 'Ajuste os filtros ou verifique as fontes.');
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
        list.innerHTML = emptyState('Nenhum congestionamento', 'Não há ocorrências viárias no período.');
    } else {
        list.innerHTML = jams.map((j) => {
            const speed = j.speedKmh !== null && j.speedKmh !== undefined
                ? `${Number(j.speedKmh).toFixed(1)} km/h` : '';
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
        list.innerHTML = emptyState('Nenhuma rota encontrada', 'Sem snapshots vinculados às rotas.');
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
        list.innerHTML = emptyState('Sem leituras climáticas', 'Cadastre uma localização meteorológica.');
        return;
    }

    list.innerHTML = weather.map((obs) => {
        const date = obs.observedAt ? new Date(obs.observedAt) : null;
        const dateStr = date
            ? `${String(date.getDate()).padStart(2,'0')}/${String(date.getMonth()+1).padStart(2,'0')} ${String(date.getHours()).padStart(2,'0')}:${String(date.getMinutes()).padStart(2,'0')}`
            : '';
        let detail = '';
        if (obs.temperature != null) detail += `${Number(obs.temperature).toFixed(1)}°C`;
        if (obs.humidity != null)    detail += `${detail ? ' · ' : ''}${obs.humidity}%`;
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

function emptyState(title, subtitle) {
    return `<div class="dashboard-empty">
        <span aria-hidden="true">✓</span>
        <strong>${escapeHtml(title)}</strong>
        <small>${escapeHtml(subtitle)}</small>
    </div>`;
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
                timeZone: 'America/Sao_Paulo',
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

    panel.dataset.hydroTrendAll = JSON.stringify(hydro.trend || []);
    panel.dataset.hydroTrendByStation = JSON.stringify(hydro.trend_by_station || {});

    const risk = hydro.stats?.risk || {};
    const stripMap = {
        stations: hydro.stats?.stations ?? 0,
        alert: (risk.overflow || 0) + (risk.alert || 0),
        attention: risk.attention || 0,
        normal: risk.normal || 0,
    };
    Object.entries(stripMap).forEach(([k, v]) => {
        const el = panel.querySelector(`[data-hydro-stat="${k}"]`);
        if (el) el.textContent = String(v);
    });

    const picker = panel.querySelector('[data-hydro-picker]');
    if (!picker) return;

    const list = hydro.stations_list || [];

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
}

function renderPluvioStations(root, pluvio) {
    if (!pluvio) return;
    const panel = root.querySelector('[data-pluvio-panel]');
    if (!panel) return;

    panel.dataset.pluvioHourlyAll = JSON.stringify(pluvio.hourly || []);
    panel.dataset.pluvioHourlyByStation = JSON.stringify(pluvio.hourly_by_station || {});

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
            ? emptyState('Sem leituras de chuva', 'Cadastre um pluviômetro ou estação hidro com dados de chuva.')
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
}

// ─────────────────────────────────────────────────────────────────────────
// Hidratação principal
// ─────────────────────────────────────────────────────────────────────────

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

    // Preserva seleção atual do usuário nos gráficos hidro/pluvio
    const hydroPanel = root.querySelector('[data-hydro-panel]');
    if (hydroPanel) applyHydroSelection(hydroPanel, root, true);
    const pluvioPanel = root.querySelector('[data-pluvio-panel]');
    if (pluvioPanel) applyPluvioSelection(pluvioPanel, root, true);

    refreshDashboardMap(data.map);

    renderRecentAlerts(root, data.recent?.alerts);
    renderRecentJams(root, data.recent?.jams);
    renderRecentRoutes(root, data.recent?.routes);
    renderRecentWeather(root, data.recent?.weather);

    renderFetchStatus(root, data.fetch_status);

    if (data.health_score !== undefined) {
        updateHealthRing(root, data.health_score);
    }

    // Um único init de charts após todos os canvases atualizados
    initDashboardCharts(root);

    // Atualiza textos numéricos sem animar (já são os valores corretos)
    root.querySelectorAll('[data-count-value]').forEach((el) => {
        const v = Number(el.dataset.countValue ?? 0);
        if (Number.isFinite(v)) el.textContent = formatNumber(v);
    });

    // Annuncia mudança para leitores de tela
    const live = root.querySelector('[data-filter-feedback]');
    if (live && live.getAttribute('aria-live') === 'polite') {
        // já atualizado por applyFilters
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Bootstrap
// ─────────────────────────────────────────────────────────────────────────

export function initDashboard(root = document) {
    const page = root.querySelector('[data-dashboard]');
    if (!page) return;

    // Idempotente: chamadas repetidas do registry não re-executam
    if (bootstrapped) return;
    bootstrapped = true;

    initDashboardClock(root);
    initDashboardCounters(root);
    initDashboardFilters(root);
    initDashboardCharts(root);
    initDashboardMap(root);
    initHydroPicker(root);
    initPluvioPicker(root);
    attachDashboardLiveUpdates(root);
    initDashboardShortcuts(root);
    startAutoRefresh(root);
}

// O registry chama `mod.default()` — exportamos também como default.
export default initDashboard;
