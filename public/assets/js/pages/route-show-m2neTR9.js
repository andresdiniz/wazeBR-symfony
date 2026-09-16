/**
 * pages/route-show.js — Página /routes/{id}
 *
 * - Mapa principal (Leaflet) com traçado + sub-rotas + alertas + irregularidades
 * - Mini-mapas por trecho problemático
 * - Heatmap dia×hora (CSS grid puro, baseado em % vs histórico)
 * - Gráficos Chart.js: timeline com linhas de alertas, por hora, por dia, jam
 *
 * ─── Sobre o heatmap ─────────────────────────────────────────────
 * A cor de cada célula depende de `avgRatio = (time - historic) / historic`.
 * Rota de 180s com +2s → 1.1% → verde. Rota de 20s com +2s → 10% → amarelo.
 * É a métrica que responde "quão ruim comparado ao normal".
 */

const COLORS = {
    blue:   '#2563eb',
    red:    '#dc2626',
    green:  '#16a34a',
    amber:  '#f59e0b',
    orange: '#ea580c',
    purple: '#7c3aed',
    grid:   'rgba(148, 163, 184, .25)',
    text:   '#475569',
    bg:     '#eef2f7',
};

const DOW_LABELS = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];

const JAM_LABELS = [
    'Sem congestionamento',
    'Baixo',
    'Moderado',
    'Alto',
    'Muito alto',
    'Parado',
];

const JAM_COLORS = [
    '#16a34a', '#84cc16', '#f59e0b', '#f97316', '#ea580c', '#dc2626',
];

const LEVEL_COLORS = {
    none:     '#16a34a',
    light:    '#3b82f6',
    moderate: '#f59e0b',
    heavy:    '#ea580c',
    severe:   '#dc2626',
};

const charts = new Map();

// ─────────────────────────────────────────────────────────────────────────
// Utils
// ─────────────────────────────────────────────────────────────────────────

function safeJson(str, fallback) {
    try { return JSON.parse(str); } catch { return fallback; }
}

function destroyChart(key) {
    const c = charts.get(key);
    if (c) { c.destroy(); charts.delete(key); }
}

function mountChart(key, canvas, config) {
    if (!canvas || typeof Chart === 'undefined') return;
    destroyChart(key);
    charts.set(key, new Chart(canvas.getContext('2d'), config));
}

function ratioToColor(ratio) {
    if (ratio === null || ratio === undefined) return null;
    const r = Math.max(0, Math.min(ratio, 1));
    const hue   = 140 - r * 140;
    const sat   = 62 + r * 12;
    const light = 44 - r * 2;
    return `hsl(${hue}, ${sat}%, ${light}%)`;
}

function fmtRatio(r) {
    if (r === null || r === undefined) return '—';
    return `${Math.round(r * 100)}%`;
}

/**
 * Mapeia o tipo de alerta Waze para uma cor de marcador no mapa.
 */
function alertColor(type) {
    switch (String(type || '').toUpperCase()) {
        case 'ACCIDENT':        return '#dc2626';
        case 'JAM':             return '#ea580c';
        case 'ROAD_CLOSED':     return '#111827';
        case 'POLICE':          return '#1d4ed8';
        case 'WEATHERHAZARD':   return '#0891b2';
        case 'HAZARD':          return '#f59e0b';
        case 'CONSTRUCTION':    return '#7c3aed';
        default:                return '#2563eb';
    }
}

function alertIcon(type) {
    switch (String(type || '').toUpperCase()) {
        case 'ACCIDENT':        return '✕';
        case 'JAM':             return '≋';
        case 'ROAD_CLOSED':     return '⊘';
        case 'POLICE':          return '◉';
        case 'WEATHERHAZARD':   return '☂';
        case 'HAZARD':          return '▲';
        case 'CONSTRUCTION':    return '⚒';
        default:                return '⚠';
    }
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
// Heatmap (CSS grid)
// ─────────────────────────────────────────────────────────────────────────

function renderHeatmap(root) {
    const container = root.querySelector('[data-heatmap]');
    if (!container) return;

    const data = safeJson(container.dataset.heatmapData, []) || [];

    const lookup = new Map();
    data.forEach((c) => {
        lookup.set(`${c.dow}:${c.hour}`, c);
    });

    const html = [];

    html.push('<div class="rs-heatmap__corner"></div>');
    for (let h = 0; h < 24; h++) {
        html.push(`<div class="rs-heatmap__hour-label">${String(h).padStart(2, '0')}</div>`);
    }

    for (let d = 0; d < 7; d++) {
        html.push(`<div class="rs-heatmap__dow-label">${DOW_LABELS[d]}</div>`);

        for (let h = 0; h < 24; h++) {
            const c = lookup.get(`${d}:${h}`);
            const hasData = c && c.count >= 2 && c.avgRatio !== null;

            if (!hasData) {
                html.push(`<div class="rs-heatmap__cell rs-heatmap__cell--empty" title="${DOW_LABELS[d]} ${String(h).padStart(2,'0')}h · sem amostras"></div>`);
                continue;
            }

            const color = ratioToColor(c.avgRatio);
            const title = [
                `${DOW_LABELS[d]} ${String(h).padStart(2, '0')}h`,
                `Ratio: ${fmtRatio(c.avgRatio)} vs histórico`,
                c.avgDelay !== null ? `Atraso médio: +${Math.round(c.avgDelay)}s` : null,
                `${c.count} amostra${c.count !== 1 ? 's' : ''}`,
            ].filter(Boolean).join(' · ');

            html.push(`<div class="rs-heatmap__cell" style="background:${color}" title="${title}"></div>`);
        }
    }

    container.innerHTML = html.join('');
}

// ─────────────────────────────────────────────────────────────────────────
// Mapa principal
// ─────────────────────────────────────────────────────────────────────────

let mainMap = null;

function initMainMap(root, attempt = 0) {
    const container = root.querySelector('[data-rs-map]');
    if (!container) return;
    if (mainMap) return;

    // Guard: Leaflet pode não estar pronto ainda
    if (typeof L === 'undefined') {
        if (attempt < 20) {
            setTimeout(() => initMainMap(root, attempt + 1), 100);
        } else {
            console.warn('[WazeBR route-show] Leaflet não carregou a tempo');
            showMapFallback(container);
        }
        return;
    }

    const page         = root.querySelector('[data-route-show]');
    const routePolyline = safeJson(page.dataset.routePolyline, []) || [];
    const alerts        = safeJson(page.dataset.nearbyAlerts, []) || [];
    const irregularities= safeJson(page.dataset.irregularities, []) || [];
    const subPolylines  = safeJson(page.dataset.subroutesPolyline, {}) || {};

    if (routePolyline.length < 2 && Object.keys(subPolylines).length === 0) {
        showMapFallback(container);
        return;
    }

    try {
        mainMap = L.map(container, {
            zoomControl: true,
            preferCanvas: true,
            center: [-20.66, -43.78],
            zoom: 13,
        });

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap',
        }).addTo(mainMap);

        // 1. Traçado base da rota (cinza, grosso) — dá contexto
        if (routePolyline.length > 1) {
            L.polyline(routePolyline, {
                color: '#94a3b8',
                weight: 7,
                opacity: 0.55,
                lineCap: 'round',
                lineJoin: 'round',
            }).addTo(mainMap);
        }

        // 2. Sub-rotas coloridas por nível (por cima)
        Object.values(subPolylines).forEach((line) => {
            if (!Array.isArray(line) || line.length < 2) return;
            // Não temos level por sub-route aqui, então colorimos neutro.
            // A cor individual fica visível quando o usuário abre o mini-mapa.
            L.polyline(line, {
                color: COLORS.blue,
                weight: 4,
                opacity: 0.7,
                lineCap: 'round',
                lineJoin: 'round',
            }).addTo(mainMap);
        });

        // 3. Alertas Waze
        alerts.forEach((a) => {
            if (!Number.isFinite(a.lat) || !Number.isFinite(a.lng)) return;

            const color = alertColor(a.type);
            const icon  = alertIcon(a.type);

            L.circleMarker([a.lat, a.lng], {
                radius: 9,
                color: '#ffffff',
                weight: 2,
                fillColor: color,
                fillOpacity: 0.95,
            })
                .bindPopup(`
                    <div class="rs-popup">
                        <strong>${escapeHtml(a.typeLabel || a.type || 'Alerta')}</strong>
                        ${a.subtype ? `<small>${escapeHtml(a.subtype)}</small>` : ''}
                        ${a.street ? `<div class="rs-popup__row"><span>Via</span><span>${escapeHtml(a.street)}</span></div>` : ''}
                        ${a.city ? `<div class="rs-popup__row"><span>Cidade</span><span>${escapeHtml(a.city)}</span></div>` : ''}
                        <div class="rs-popup__row"><span>Distância</span><span>${a.distanceMeters} m</span></div>
                        ${a.pubDateTime ? `<div class="rs-popup__row"><span>Quando</span><span>${new Date(a.pubDateTime).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })}</span></div>` : ''}
                    </div>
                `)
                .addTo(mainMap);
        });

        // 4. Irregularidades TVT
        irregularities.forEach((irr) => {
            if (!Number.isFinite(irr.lat) || !Number.isFinite(irr.lng)) return;

            L.circleMarker([irr.lat, irr.lng], {
                radius: 8,
                color: '#ffffff',
                weight: 2,
                fillColor: COLORS.purple,
                fillOpacity: 0.9,
            })
                .bindPopup(`
                    <div class="rs-popup">
                        <strong>${escapeHtml(irr.type || 'Irregularidade')}</strong>
                        ${irr.subtype ? `<small>${escapeHtml(irr.subtype)}</small>` : ''}
                        ${irr.street ? `<div class="rs-popup__row"><span>Via</span><span>${escapeHtml(irr.street)}</span></div>` : ''}
                        ${irr.city ? `<div class="rs-popup__row"><span>Cidade</span><span>${escapeHtml(irr.city)}</span></div>` : ''}
                        ${irr.severity ? `<div class="rs-popup__row"><span>Severidade</span><span>${escapeHtml(irr.severity)}</span></div>` : ''}
                    </div>
                `)
                .addTo(mainMap);
        });

        // Fit bounds em tudo
        const allPoints = [
            ...routePolyline,
            ...alerts.map((a) => [a.lat, a.lng]).filter((p) => p[0] && p[1]),
            ...irregularities.map((i) => [i.lat, i.lng]).filter((p) => p[0] && p[1]),
        ];

        if (allPoints.length > 0) {
            try {
                mainMap.fitBounds(allPoints, { padding: [30, 30], maxZoom: 16 });
            } catch { /* ignora */ }
        }

        // invalidateSize em dois momentos (grid/leaflet às vezes precisa)
        setTimeout(() => mainMap && mainMap.invalidateSize(), 100);
        setTimeout(() => mainMap && mainMap.invalidateSize(), 400);

        window.addEventListener('resize', () => mainMap && mainMap.invalidateSize());

        window.routeShowMap = mainMap;
    } catch (err) {
        console.error('[WazeBR route-show] falha ao montar o mapa', err);
        showMapFallback(container);
    }
}

function showMapFallback(container) {
    const fb = container.querySelector('[data-rs-map-fallback]');
    if (fb) fb.hidden = false;
}

// ─────────────────────────────────────────────────────────────────────────
// Mini-mapas das sub-rotas
// ─────────────────────────────────────────────────────────────────────────

const miniMaps = [];

function initSubRouteMaps(root, attempt = 0) {
    if (typeof L === 'undefined') {
        if (attempt < 20) {
            setTimeout(() => initSubRouteMaps(root, attempt + 1), 100);
        }
        return;
    }

    const page = root.querySelector('[data-route-show]');
    if (!page) return;

    const routePolyline = safeJson(page.dataset.routePolyline, []) || [];
    const subPolylines  = safeJson(page.dataset.subroutesPolyline, {}) || {};

    root.querySelectorAll('[data-subroute-map]').forEach((container) => {
        if (container.dataset.miniMapInit === '1') return;
        container.dataset.miniMapInit = '1';

        const subId = Number(container.dataset.subrouteId);
        const subLine = subPolylines[subId] || subPolylines[String(subId)];
        if (!Array.isArray(subLine) || subLine.length < 2) {
            container.style.background = '#f1f5f9';
            return;
        }

        try {
            const m = L.map(container, {
                zoomControl: false,
                attributionControl: false,
                dragging: false,
                touchZoom: false,
                scrollWheelZoom: false,
                doubleClickZoom: false,
                boxZoom: false,
                keyboard: false,
                preferCanvas: true,
            });

            // Sem tiles: fundo neutro, só polylines
            L.polyline(subLine, {
                color: COLORS.blue,
                weight: 5,
                opacity: 0.9,
                lineCap: 'round',
                lineJoin: 'round',
            }).addTo(m);

            // Rota completa em cinza claro para contexto
            if (routePolyline.length > 1) {
                L.polyline(routePolyline, {
                    color: '#cbd5e1',
                    weight: 2,
                    opacity: 0.7,
                    lineCap: 'round',
                    lineJoin: 'round',
                }).addTo(m);
            }

            // Ajusta bounds à sub-rota
            try {
                m.fitBounds(subLine, { padding: [8, 8], maxZoom: 16 });
            } catch { /* ignora */ }

            miniMaps.push(m);

            // invalidateSize defensivo
            setTimeout(() => m.invalidateSize(), 50);
        } catch (err) {
            console.warn('[WazeBR route-show] mini-mapa falhou', subId, err);
            container.style.background = '#f1f5f9';
        }
    });
}

// ─────────────────────────────────────────────────────────────────────────
// Chart.js — plugin: linhas verticais de alertas no timeline
// ─────────────────────────────────────────────────────────────────────────

const alertLinesPlugin = {
    id: 'rsAlertLines',
    afterDatasetsDraw(chart, _args, opts) {
        const indices = opts?.indices || [];
        if (indices.length === 0) return;

        const { ctx, chartArea, scales } = chart;
        const xScale = scales.x;
        if (!xScale) return;

        ctx.save();
        ctx.setLineDash([3, 3]);
        ctx.lineWidth = 1.5;
        ctx.strokeStyle = 'rgba(234, 88, 12, 0.55)';

        indices.forEach((i) => {
            const x = xScale.getPixelForValue(i);
            if (!Number.isFinite(x)) return;
            if (x < chartArea.left || x > chartArea.right) return;

            ctx.beginPath();
            ctx.moveTo(x, chartArea.top);
            ctx.lineTo(x, chartArea.bottom);
            ctx.stroke();
        });

        ctx.restore();
    },
};

// Registra uma única vez
if (typeof Chart !== 'undefined') {
    Chart.register(alertLinesPlugin);
}

/**
 * Mapeia um alerta (collectedAt UTC-naive) para o mesmo bucket do timeline.
 * O backend usa `DATE_ADD(recorded_at, INTERVAL -3 HOUR)`.
 */
function bucketFor(dateStr) {
    const m = String(dateStr).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):/);
    if (!m) return null;
    let [, y, mo, d, h] = m;
    h = parseInt(h, 10) - 3;
    if (h < 0) {
        h += 24;
        const dt = new Date(`${y}-${mo}-${d}T00:00:00Z`);
        dt.setUTCDate(dt.getUTCDate() - 1);
        y  = dt.getUTCFullYear();
        mo = String(dt.getUTCMonth() + 1).padStart(2, '0');
        d  = String(dt.getUTCDate()).padStart(2, '0');
    }
    return `${y}-${mo}-${d} ${String(h).padStart(2, '0')}:00:00`;
}

/**
 * Cruza alertas com labels do timeline e retorna os índices únicos.
 */
function alertIndices(alerts, labels) {
    const set = new Set();
    alerts.forEach((a) => {
        const bucket = bucketFor(a.collectedAt || '');
        if (!bucket) return;
        const idx = labels.indexOf(bucket);
        if (idx >= 0) set.add(idx);
    });
    return [...set].sort((a, b) => a - b);
}

// ─────────────────────────────────────────────────────────────────────────
// Gráficos
// ─────────────────────────────────────────────────────────────────────────

function initCharts(root) {
    if (typeof Chart === 'undefined') return;

    Chart.defaults.color = COLORS.text;
    Chart.defaults.font.family = "Inter, system-ui, -apple-system, 'Segoe UI', sans-serif";
    Chart.defaults.font.size = 12;

    const page = root.querySelector('[data-route-show]');
    const alerts = page ? (safeJson(page.dataset.nearbyAlerts, []) || []) : [];

    // ── Timeline ────────────────────────────────────────────────
    root.querySelectorAll('[data-chart="route-timeline"]').forEach((canvas) => {
        const points = safeJson(canvas.dataset.chartData, []) || [];

        const labels = points.map((p) => {
            const t = (p.time || '');
            return t.length >= 16 ? `${t.slice(8, 10)}/${t.slice(5, 7)} ${t.slice(11, 16)}` : t;
        });
        const delay = points.map((p) => p.avgDelay);
        const ratio = points.map((p) => p.avgRatio !== null ? p.avgRatio * 100 : null);

        const indices = alertIndices(alerts, points.map((p) => p.time));

        mountChart('routeTimeline', canvas, {
            type: 'line',
            data: {
                labels,
                datasets: [
                    {
                        label: 'Atraso (s)',
                        data: delay,
                        borderColor: COLORS.blue,
                        backgroundColor: 'rgba(37,99,235,.10)',
                        fill: true, tension: 0.35, pointRadius: 0, borderWidth: 2,
                        yAxisID: 'y',
                    },
                    {
                        label: '% vs histórico',
                        data: ratio,
                        borderColor: COLORS.red,
                        backgroundColor: 'rgba(220,38,38,.08)',
                        fill: false, tension: 0.35, pointRadius: 0, borderWidth: 2,
                        borderDash: [4, 3],
                        yAxisID: 'y1',
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                resizeDelay: 120,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => ctx.dataset.label === '% vs histórico'
                                ? `${ctx.parsed.y?.toFixed(0)}%`
                                : `+${ctx.parsed.y?.toFixed(0)}s`,
                        },
                    },
                    rsAlertLines: { indices },
                },
                scales: {
                    x: { grid: { display: false } },
                    y:  { position: 'left',  grid: { color: COLORS.grid }, beginAtZero: true, title: { display: true, text: 'segundos' } },
                    y1: { position: 'right', grid: { display: false }, beginAtZero: true, ticks: { callback: (v) => `${v}%` } },
                },
            },
        });
    });

    // ── Por hora ────────────────────────────────────────────────
    root.querySelectorAll('[data-chart="route-by-hour"]').forEach((canvas) => {
        const points = safeJson(canvas.dataset.chartData, []) || [];

        const labels = points.map((p) => `${String(p.hour).padStart(2, '0')}h`);
        const ratios = points.map((p) => p.avgRatio);
        const delays = points.map((p) => p.avgDelay ?? 0);
        const counts = points.map((p) => p.count ?? 0);

        const bgColors = ratios.map((r, i) => {
            if (counts[i] < 2 || r === null) return 'rgba(148,163,184,.35)';
            return ratioToColor(r);
        });

        mountChart('routeByHour', canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Atraso médio (s)',
                    data: delays,
                    backgroundColor: bgColors,
                    borderRadius: 4,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                resizeDelay: 120,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const i = ctx.dataIndex;
                                const r = ratios[i];
                                const d = delays[i];
                                const c = counts[i];
                                if (c < 2 || r === null) return 'Sem amostras suficientes';
                                return `+${Math.round(d)}s · ${Math.round(r * 100)}% vs histórico · ${c} amostras`;
                            },
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { grid: { color: COLORS.grid }, beginAtZero: true, title: { display: true, text: 'segundos' } },
                },
            },
        });
    });

    // ── Por dia da semana ──────────────────────────────────────
    root.querySelectorAll('[data-chart="route-by-dow"]').forEach((canvas) => {
        const points = safeJson(canvas.dataset.chartData, []) || [];

        const labels = points.map((p) => DOW_LABELS[p.dow] ?? '?');
        const ratios = points.map((p) => p.avgRatio);
        const delays = points.map((p) => p.avgDelay ?? 0);
        const counts = points.map((p) => p.count ?? 0);

        const bgColors = ratios.map((r, i) => {
            if (counts[i] < 2 || r === null) return 'rgba(148,163,184,.35)';
            return ratioToColor(r);
        });

        mountChart('routeByDow', canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Atraso médio (s)',
                    data: delays,
                    backgroundColor: bgColors,
                    borderRadius: 4,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                resizeDelay: 120,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const i = ctx.dataIndex;
                                const r = ratios[i];
                                const d = delays[i];
                                const c = counts[i];
                                if (c < 2 || r === null) return 'Sem amostras suficientes';
                                return `+${Math.round(d)}s · ${Math.round(r * 100)}% vs histórico · ${c} amostras`;
                            },
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { grid: { color: COLORS.grid }, beginAtZero: true, title: { display: true, text: 'segundos' } },
                },
            },
        });
    });

    // ── Distribuição de jam ────────────────────────────────────
    root.querySelectorAll('[data-chart="route-jam-dist"]').forEach((canvas) => {
        const points = safeJson(canvas.dataset.chartData, []) || [];

        const filtered = points.filter((p) => p.count > 0);
        const labels = filtered.map((p) => JAM_LABELS[p.level] ?? `Nível ${p.level}`);
        const data   = filtered.map((p) => p.count);
        const bg     = filtered.map((p) => JAM_COLORS[p.level] ?? '#94a3b8');

        mountChart('routeJamDist', canvas, {
            type: 'doughnut',
            data: {
                labels,
                datasets: [{
                    data,
                    backgroundColor: bg,
                    borderWidth: 0,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                resizeDelay: 120,
                cutout: '62%',
                plugins: {
                    legend: { position: 'right', labels: { usePointStyle: true, boxWidth: 8 } },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                const pct = total > 0 ? Math.round((ctx.parsed / total) * 100) : 0;
                                return `${ctx.parsed} amostras (${pct}%)`;
                            },
                        },
                    },
                },
            },
        });
    });
}

// ─────────────────────────────────────────────────────────────────────────
// Bootstrap
// ─────────────────────────────────────────────────────────────────────────

let bootstrapped = false;

export function initRouteShow(root = document) {
    const page = root.querySelector('[data-route-show]');
    if (!page || bootstrapped) return;
    bootstrapped = true;

    renderHeatmap(page);
    initCharts(page);
    initMainMap(page);
    initSubRouteMaps(page);
}

export default initRouteShow;
