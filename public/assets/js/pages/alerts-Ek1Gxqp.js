/**
 * pages/alerts.js — Dashboard de alertas.
 * - Mapa "Ao vivo": Leaflet + MarkerCluster com polling a cada 45s.
 * - Mapa "Histórico": clustering por proximidade calculado no servidor.
 * - Filtros com fetch + fallback para navegação.
 * - Charts (Chart.js): tipo, hora, dia, cidade, subtipo.
 * - Tabela com busca local e "carregar mais".
 */

const COLORS = {
    blue:   '#2563eb',
    orange: '#ea580c',
    green:  '#16a34a',
    purple: '#7c3aed',
    red:    '#dc2626',
    gray:   '#94a3b8',
    dark:   '#111827',
    cyan:   '#0891b2',
    grid:   'rgba(148,163,184,.25)',
    text:   '#475569',
};

const TYPE_COLOR = {
    ACCIDENT:         COLORS.red,
    JAM:              COLORS.orange,
    ROAD_CLOSED:      COLORS.dark,
    POLICE:           COLORS.blue,
    WEATHERHAZARD:    COLORS.cyan,
    HAZARD:           '#f59e0b',
    CONSTRUCTION:     '#7c2d12',
};

const PALETTE = [
    COLORS.blue, COLORS.orange, COLORS.green, COLORS.purple,
    COLORS.red, COLORS.cyan, '#0f766e', '#a16207', '#be185d', '#334155',
];

const POLL_INTERVAL = 45_000;

// ─────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────

function safeJson(str, fb) {
    try { return JSON.parse(str); } catch { return fb; }
}

function escapeHtml(s) {
    return String(s ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function typeColor(type) {
    return TYPE_COLOR[(type || '').toUpperCase()] || COLORS.blue;
}

function collectFilters(form) {
    const out = {};
    new FormData(form).forEach((v, k) => {
        if (v instanceof File) return;
        const s = String(v).trim();
        if (s !== '') out[k] = s;
    });
    return out;
}

function filtersToQuery(filters) {
    const sp = new URLSearchParams();
    Object.entries(filters).forEach(([k, v]) => sp.set(k, v));
    return sp.toString();
}

// ─────────────────────────────────────────────────────────────────────────
// Clock / Refresh
// ─────────────────────────────────────────────────────────────────────────

function initClock(root) {
    const el = root.querySelector('[data-live-clock]');
    if (!el) return;
    const tick = () => {
        el.textContent = new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit' })
            .format(new Date());
    };
    tick();
    setInterval(tick, 30_000);
}

// ─────────────────────────────────────────────────────────────────────────
// Charts
// ─────────────────────────────────────────────────────────────────────────

const charts = new Map();

function mountChart(key, canvas, cfg) {
    if (!canvas || typeof Chart === 'undefined') return;
    const existing = charts.get(key);
    if (existing) existing.destroy();
    charts.set(key, new Chart(canvas.getContext('2d'), cfg));
}

function renderCharts(root) {
    if (typeof Chart === 'undefined') return;
    Chart.defaults.color = COLORS.text;
    Chart.defaults.font.family = "Inter, system-ui, -apple-system, 'Segoe UI', sans-serif";
    Chart.defaults.font.size = 12;

    // Por tipo — doughnut
    root.querySelectorAll('[data-chart="by-type"]').forEach((canvas) => {
        const points = safeJson(canvas.dataset.chartData, []);
        const labels = points.map((p) => p.label);
        const data   = points.map((p) => Number(p.total) || 0);
        const bg     = labels.map((l) => typeColor(l) || PALETTE[0]);

        mountChart('byType', canvas, {
            type: 'doughnut',
            data: { labels, datasets: [{ data, backgroundColor: bg, borderWidth: 0 }] },
            options: {
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: { legend: { position: 'right', labels: { usePointStyle: true, boxWidth: 8 } } },
            },
        });
    });

    // Por hora
    root.querySelectorAll('[data-chart="by-hour"]').forEach((canvas) => {
        const points = safeJson(canvas.dataset.chartData, []);
        mountChart('byHour', canvas, {
            type: 'bar',
            data: {
                labels: points.map((p) => p.hour),
                datasets: [{
                    label: 'Alertas',
                    data: points.map((p) => Number(p.total) || 0),
                    backgroundColor: 'rgba(37,99,235,.75)',
                    borderRadius: 5,
                }],
            },
            options: {
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { x: { grid: { display: false } }, y: { grid: { color: COLORS.grid }, beginAtZero: true } },
            },
        });
    });

    // Por dia
    root.querySelectorAll('[data-chart="by-day"]').forEach((canvas) => {
        const points = safeJson(canvas.dataset.chartData, []);
        mountChart('byDay', canvas, {
            type: 'line',
            data: {
                labels: points.map((p) => (p.day || '').slice(5)),
                datasets: [{
                    label: 'Alertas',
                    data: points.map((p) => Number(p.total) || 0),
                    borderColor: COLORS.purple,
                    backgroundColor: 'rgba(124,58,237,.15)',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 0,
                    borderWidth: 2,
                }],
            },
            options: {
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { x: { grid: { display: false } }, y: { grid: { color: COLORS.grid }, beginAtZero: true } },
            },
        });
    });

    // Por cidade — horizontal bar
    root.querySelectorAll('[data-chart="by-city"]').forEach((canvas) => {
        const points = safeJson(canvas.dataset.chartData, []);
        mountChart('byCity', canvas, {
            type: 'bar',
            data: {
                labels: points.map((p) => p.label),
                datasets: [{
                    label: 'Alertas',
                    data: points.map((p) => Number(p.total) || 0),
                    backgroundColor: 'rgba(22,163,74,.75)',
                    borderRadius: 6,
                }],
            },
            options: {
                indexAxis: 'y',
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { x: { grid: { color: COLORS.grid }, beginAtZero: true }, y: { grid: { display: false } } },
            },
        });
    });

    // Por subtipo
    root.querySelectorAll('[data-chart="by-subtype"]').forEach((canvas) => {
        const points = safeJson(canvas.dataset.chartData, []);
        mountChart('bySubtype', canvas, {
            type: 'bar',
            data: {
                labels: points.map((p) => p.label),
                datasets: [{
                    label: 'Ocorrências',
                    data: points.map((p) => Number(p.total) || 0),
                    backgroundColor: 'rgba(234,88,12,.75)',
                    borderRadius: 6,
                }],
            },
            options: {
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { x: { grid: { display: false } }, y: { grid: { color: COLORS.grid }, beginAtZero: true } },
            },
        });
    });
}

// ─────────────────────────────────────────────────────────────────────────
// Map (Leaflet + MarkerCluster)
// ─────────────────────────────────────────────────────────────────────────

const mapState = {
    map: null,
    liveLayer: null,
    historyLayer: null,
    liveData: [],
    historyData: [],
    currentView: 'live',
};

function initMap(root) {
    const container = root.querySelector('[data-alerts-map]');
    if (!container || typeof L === 'undefined') return;

    mapState.liveData    = safeJson(container.dataset.live, []);
    mapState.historyData = safeJson(container.dataset.clusters, []);

    mapState.map = L.map(container, { zoomControl: true, preferCanvas: true })
        .setView([-15.7801, -47.9292], 5);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap',
    }).addTo(mapState.map);

    // Live cluster group
    mapState.liveLayer = L.markerClusterGroup({
        chunkedLoading: true,
        showCoverageOnHover: false,
        spiderfyOnMaxZoom: true,
        maxClusterRadius: 55,
        iconCreateFunction: (cluster) => {
            const n = cluster.getChildCount();
            const size = n > 200 ? 54 : n > 50 ? 48 : n > 10 ? 42 : 36;
            return L.divIcon({
                html: `<div class="alerts-cluster" style="width:${size}px;height:${size}px">${n}</div>`,
                className: '',
                iconSize: [size, size],
            });
        },
    }).addTo(mapState.map);

    mapState.historyLayer = L.layerGroup().addTo(mapState.map);
    mapState.historyLayer.remove();
    mapState.liveLayer.addTo(mapState.map);

    renderLiveMarkers();
    renderHistoryClusters();

    // Auto-fit
    fitToData();

    // Tab switching
    root.querySelectorAll('[data-map-view]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const view = btn.dataset.mapView;
            mapState.currentView = view;
            root.querySelectorAll('[data-map-view]').forEach((b) => b.classList.toggle('is-active', b === btn));

            const precision = root.querySelector('[data-history-precision]');
            if (precision) precision.hidden = view !== 'history';
            const hint = root.querySelector('[data-map-hint]');
            if (hint) hint.textContent = view === 'live'
                ? 'Clique em um agrupamento para expandir'
                : 'Círculos maiores = mais alertas naquela célula';

            if (view === 'live') {
                if (mapState.map.hasLayer(mapState.historyLayer)) mapState.map.removeLayer(mapState.historyLayer);
                mapState.liveLayer.addTo(mapState.map);
            } else {
                if (mapState.map.hasLayer(mapState.liveLayer)) mapState.map.removeLayer(mapState.liveLayer);
                mapState.historyLayer.addTo(mapState.map);
            }
            fitToData();
        });
    });

    // Precision change → refetch history
    root.querySelector('[data-history-precision]')?.addEventListener('change', async (e) => {
        const precision = e.target.value;
        try {
            const endpoint = root.dataset.endpointHistory;
            const filters  = safeJson(root.dataset.currentFilters, {});
            const url      = new URL(endpoint, location.origin);
            url.search = filtersToQuery({ ...filters, precision });

            const res  = await fetch(url, { headers: { 'Accept': 'application/json' } });
            const json = await res.json();
            mapState.historyData = json.clusters || [];
            renderHistoryClusters();
            fitToData();
        } catch (err) {
            console.error('[alerts] precision fetch failed', err);
        }
    });

    window.addEventListener('resize', () => mapState.map && mapState.map.invalidateSize());
}

function renderLiveMarkers() {
    if (!mapState.liveLayer) return;
    mapState.liveLayer.clearLayers();

    const markers = mapState.liveData.map((a) => {
        const color = typeColor(a.type);
        const m = L.circleMarker([a.lat, a.lng], {
            radius: 6, color, weight: 1, fillColor: color, fillOpacity: 0.85,
        });
        m.bindPopup(`
            <div class="alerts-popup">
                <strong style="color:${color}">${escapeHtml(a.type || 'Alerta')}</strong>
                ${a.subtype ? `<br><small>${escapeHtml(a.subtype)}</small>` : ''}
                <hr>
                ${a.street ? `${escapeHtml(a.street)}<br>` : ''}
                ${a.city ? `<small>${escapeHtml(a.city)}</small><br>` : ''}
                <small>Confiança: ${a.confidence}%</small><br>
                <small>${escapeHtml(a.when || '')}</small>
            </div>
        `);
        return m;
    });

    mapState.liveLayer.addLayers(markers);
}

function renderHistoryClusters() {
    if (!mapState.historyLayer) return;
    mapState.historyLayer.clearLayers();

    if (!Array.isArray(mapState.historyData) || mapState.historyData.length === 0) return;

    // Raio proporcional à contagem (usa sqrt para escala perceptual)
    const maxTotal = Math.max(...mapState.historyData.map((c) => c.total), 1);
    const base     = 18;

    mapState.historyData.forEach((c) => {
        const scale = Math.sqrt(c.total / maxTotal);
        const radius = Math.max(6, base * scale);
        const color = typeColor(c.type);

        const circle = L.circleMarker([c.lat, c.lng], {
            radius,
            color,
            weight: 1,
            fillColor: color,
            fillOpacity: 0.35,
        });

        circle.bindPopup(`
            <div class="alerts-popup">
                <strong>${c.total} alerta${c.total > 1 ? 's' : ''}</strong><br>
                <small>Tipo predominante: ${escapeHtml(c.type || '—')}</small><br>
                <small>${escapeHtml(c.firstSeen || '')} → ${escapeHtml(c.lastSeen || '')}</small>
            </div>
        `);
        mapState.historyLayer.addLayer(circle);
    });
}

function fitToData() {
    if (!mapState.map) return;
    const data = mapState.currentView === 'live' ? mapState.liveData : mapState.historyData;
    if (!data || data.length === 0) return;

    const latLngs = data.map((p) => [p.lat, p.lng]).filter((p) => Number.isFinite(p[0]) && Number.isFinite(p[1]));
    if (latLngs.length === 0) return;

    try {
        mapState.map.fitBounds(L.latLngBounds(latLngs).pad(0.15), { maxZoom: 13 });
    } catch {/* ignore */}
}

// ─────────────────────────────────────────────────────────────────────────
// Filters, export link and navigation
// ─────────────────────────────────────────────────────────────────────────

function updateUrl(filters) {
    const url = new URL(location.href);
    url.search = filtersToQuery(filters);
    history.replaceState({}, '', url.toString());
}

function updateExportLinks(root, filters) {
    const qs = filtersToQuery(filters);
    const endpoint = root.dataset.endpointExport;
    root.querySelectorAll('[data-export-link]').forEach((a) => {
        a.href = `${endpoint}${qs ? '?' + qs : ''}`;
    });
}

function applyFiltersToUi(root, filters) {
    // Cabeçalho — dataset usado pelas próximas requisições
    root.dataset.currentFilters = JSON.stringify(filters);

    // URL & export
    updateUrl(filters);
    updateExportLinks(root, filters);
}

async function fetchLive(root) {
    const endpoint = root.dataset.endpointLive;
    const filters  = safeJson(root.dataset.currentFilters, {});
    const url      = new URL(endpoint, location.origin);
    url.search = filtersToQuery(filters);

    const res  = await fetch(url, { headers: { 'Accept': 'application/json' } });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const json = await res.json();

    // KPIs
    const stats = json.stats || {};
    setText(root, '[data-kpi="active"]', stats.active ?? '—');
    setText(root, '[data-kpi="total"]', stats.total ?? '—');
    setText(root, '[data-kpi="top_type"]', stats.top_type ?? '—');
    setText(root, '[data-kpi="recent"]', `${stats.recent ?? 0} na última hora`);
    setText(root, '[data-kpi="last_seen"]', stats.last_seen ? stats.last_seen.slice(11, 16) : '—');

    // Map
    mapState.liveData = json.map?.live || [];
    renderLiveMarkers();

    // Charts
    const byType = root.querySelector('[data-chart="by-type"]');
    if (byType && json.charts?.by_type) {
        byType.dataset.chartData = JSON.stringify(json.charts.by_type);
    }
    const byHour = root.querySelector('[data-chart="by-hour"]');
    if (byHour && json.charts?.by_hour) {
        byHour.dataset.chartData = JSON.stringify(json.charts.by_hour);
    }
    renderCharts(root);
}

function setText(root, selector, value) {
    const el = root.querySelector(selector);
    if (el) el.textContent = String(value);
}

function initFilters(root) {
    const form = root.querySelector('[data-filter-form]');
    if (!form) return;

    // Aplica os filtros iniciais no estado da página
    const initial = safeJson(root.dataset.currentFilters, {});
    applyFiltersToUi(root, initial);

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const filters = collectFilters(form);
        applyFiltersToUi(root, filters);

        try {
            await fetchLive(root);
        } catch (err) {
            console.error('[alerts] refresh after filter failed', err);
            // Fallback: recarrega a página com a querystring atual
            location.href = location.href;
        }
    });

    form.querySelectorAll('[data-action="clear-filters"]').forEach((b) =>
        b.addEventListener('click', () => {
            form.reset();
            applyFiltersToUi(root, {});
            location.href = root.dataset.endpointLive.replace('/api/live', '');
        })
    );

    root.querySelectorAll('[data-action="clear-filters"]').forEach((b) => {
        if (form.contains(b)) return;
        b.addEventListener('click', () => {
            form.reset();
            applyFiltersToUi(root, {});
            location.href = root.dataset.endpointLive.replace('/api/live', '');
        });
    });
}

// ─────────────────────────────────────────────────────────────────────────
// Table
// ─────────────────────────────────────────────────────────────────────────

function initTable(root) {
    const search = root.querySelector('[data-table-search]');
    const body   = root.querySelector('[data-table-body]');
    const count  = root.querySelector('[data-table-count]');
    const more   = root.querySelector('[data-action="load-more"]');

    if (search && body) {
        search.addEventListener('input', () => {
            const q = search.value.trim().toLowerCase();
            let visible = 0;
            body.querySelectorAll('tr[data-search-text]').forEach((tr) => {
                const hay = tr.dataset.searchText || '';
                const show = q === '' || hay.includes(q);
                tr.hidden = !show;
                if (show) visible++;
            });
            if (count) count.textContent = `${visible} registros visíveis`;
        });
    }

    if (more) {
        let offset = body ? body.querySelectorAll('tr').length : 0;

        more.addEventListener('click', async () => {
            more.disabled = true;
            more.textContent = 'Carregando...';

            const endpoint = root.dataset.endpointList;
            const filters  = safeJson(root.dataset.currentFilters, {});
            const url      = new URL(endpoint, location.origin);
            url.search = filtersToQuery({ ...filters, limit: 30, offset });

            try {
                const res  = await fetch(url, { headers: { 'Accept': 'application/json' } });
                const json = await res.json();
                const rows = json.rows || [];

                if (rows.length === 0) {
                    more.textContent = 'Sem mais registros';
                    more.disabled = true;
                    return;
                }

                const html = rows.map(rowHtml).join('');
                body.insertAdjacentHTML('beforeend', html);
                offset += rows.length;

                if (count) count.textContent = `${offset} registros exibidos`;
                more.disabled = false;
                more.textContent = 'Carregar mais';
            } catch (err) {
                console.error('[alerts] load more failed', err);
                more.disabled = false;
                more.textContent = 'Tentar novamente';
            }
        });
    }
}

function rowHtml(a) {
    const search = escapeHtml([a.type, a.subtype, a.city, a.street, a.uuid].join(' ').toLowerCase());
    const typeLc = escapeHtml((a.type || '').toLowerCase());
    const when   = a.when ? escapeHtml(a.when.slice(0, 16).replace('T', ' ')) : '—';
    const state  = a.isActive ? 'Ativo' : 'Encerrado';
    const stateClass = a.isActive ? 'is-active' : 'is-off';
    return `
        <tr data-search-text="${search}">
            <td>${a.id}</td>
            <td><span class="alerts-badge type-${typeLc}">${escapeHtml(a.type || '—')}</span></td>
            <td>${escapeHtml(a.subtype || '—')}</td>
            <td>${escapeHtml(a.city || '—')}</td>
            <td>${escapeHtml(a.street || '—')}</td>
            <td>
                <span class="alerts-conf">
                    <span class="alerts-conf-bar" style="--v:${a.confidence}"></span>
                    ${a.confidence}%
                </span>
            </td>
            <td><span class="alerts-state ${stateClass}">${state}</span></td>
            <td><time datetime="${escapeHtml(a.when || '')}">${when}</time></td>
        </tr>`;
}

// ─────────────────────────────────────────────────────────────────────────
// Bootstrap
// ─────────────────────────────────────────────────────────────────────────

export function initAlertsPage(root = document) {
    const page = root.querySelector('[data-alerts-page]');
    if (!page) return;

    initClock(page);
    renderCharts(page);
    initMap(page);
    initFilters(page);
    initTable(page);

    // Refresh manual
    page.querySelector('[data-action="refresh"]')?.addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        btn.classList.add('is-loading');
        try { await fetchLive(page); } finally {
            btn.classList.remove('is-loading');
        }
    });

    // Polling
    setInterval(() => { fetchLive(page).catch(() => {}); }, POLL_INTERVAL);

    // Re-render charts on window resize
    window.addEventListener('resize', () => { /* Chart.js já é responsivo */ });
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => initAlertsPage());
    } else {
        initAlertsPage();
    }
}
