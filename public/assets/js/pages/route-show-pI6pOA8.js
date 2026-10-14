/**
 * pages/route-show.js — Página /routes/{id}
 *
 * - Mapa principal (Leaflet) com traçado + sub-rotas + alertas + irregularidades
 * - Mini-mapas por trecho problemático
 * - Heatmap dia × hora
 * - Gráficos Chart.js
 * - Dropdown de exportação CSV
 */

const COLORS = {
    blue: '#2563eb',
    red: '#dc2626',
    green: '#16a34a',
    amber: '#f59e0b',
    orange: '#ea580c',
    purple: '#7c3aed',
    grid: 'rgba(148, 163, 184, .25)',
    text: '#475569',
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
    '#16a34a',
    '#84cc16',
    '#f59e0b',
    '#f97316',
    '#ea580c',
    '#dc2626',
];

const charts = new Map();
const miniMaps = [];

let mainMap = null;
let bootstrapped = false;

// ─────────────────────────────────────────────────────────────────────────
// Utils
// ─────────────────────────────────────────────────────────────────────────

function safeJson(value, fallback) {
    try {
        return JSON.parse(value);
    } catch {
        return fallback;
    }
}

function resolvePage(root) {
    if (!root) {
        return null;
    }

    if (root.matches?.('[data-route-show]')) {
        return root;
    }

    return root.querySelector?.('[data-route-show]') ?? null;
}

function destroyChart(key) {
    const chart = charts.get(key);

    if (chart) {
        chart.destroy();
        charts.delete(key);
    }
}

function mountChart(key, canvas, config) {
    if (!canvas || typeof Chart === 'undefined') {
        return;
    }

    destroyChart(key);
    charts.set(key, new Chart(canvas.getContext('2d'), config));
}

function ratioToColor(ratio) {
    if (ratio === null || ratio === undefined) {
        return null;
    }

    const normalized = Math.max(0, Math.min(ratio, 1));
    const hue = 140 - normalized * 140;
    const saturation = 62 + normalized * 12;
    const lightness = 44 - normalized * 2;

    return `hsl(${hue}, ${saturation}%, ${lightness}%)`;
}

function fmtRatio(ratio) {
    if (ratio === null || ratio === undefined) {
        return '—';
    }

    return `${Math.round(ratio * 100)}%`;
}

function alertColor(type) {
    switch (String(type || '').toUpperCase()) {
        case 'ACCIDENT':
            return '#dc2626';
        case 'JAM':
            return '#ea580c';
        case 'ROAD_CLOSED':
            return '#111827';
        case 'POLICE':
            return '#1d4ed8';
        case 'WEATHERHAZARD':
            return '#0891b2';
        case 'HAZARD':
            return '#f59e0b';
        case 'CONSTRUCTION':
            return '#7c3aed';
        default:
            return '#2563eb';
    }
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function computeBBox(line, padding = 0.0005) {
    if (!Array.isArray(line) || line.length === 0) {
        return null;
    }

    let minLat = line[0][0];
    let maxLat = line[0][0];
    let minLng = line[0][1];
    let maxLng = line[0][1];

    for (const [lat, lng] of line) {
        minLat = Math.min(minLat, lat);
        maxLat = Math.max(maxLat, lat);
        minLng = Math.min(minLng, lng);
        maxLng = Math.max(maxLng, lng);
    }

    return {
        minLat: minLat - padding,
        maxLat: maxLat + padding,
        minLng: minLng - padding,
        maxLng: maxLng + padding,
    };
}

function pointInBBox(lat, lng, bbox) {
    return (
        lat >= bbox.minLat &&
        lat <= bbox.maxLat &&
        lng >= bbox.minLng &&
        lng <= bbox.maxLng
    );
}

// ─────────────────────────────────────────────────────────────────────────
// Heatmap
// ─────────────────────────────────────────────────────────────────────────

function renderHeatmap(root) {
    const page = resolvePage(root);

    if (!page) {
        return;
    }

    const container = page.querySelector('[data-heatmap]');

    if (!container) {
        return;
    }

    const data = safeJson(container.dataset.heatmapData, []) || [];
    const lookup = new Map();

    data.forEach((cell) => {
        lookup.set(`${cell.dow}:${cell.hour}`, cell);
    });

    const html = [];

    html.push('<div class="rs-heatmap__corner"></div>');

    for (let hour = 0; hour < 24; hour++) {
        html.push(
            `<div class="rs-heatmap__hour-label">${String(hour).padStart(2, '0')}</div>`
        );
    }

    for (let dow = 0; dow < 7; dow++) {
        html.push(`<div class="rs-heatmap__dow-label">${DOW_LABELS[dow]}</div>`);

        for (let hour = 0; hour < 24; hour++) {
            const cell = lookup.get(`${dow}:${hour}`);
            const hasData = cell && cell.count >= 2 && cell.avgRatio !== null;

            if (!hasData) {
                html.push(
                    `<div class="rs-heatmap__cell rs-heatmap__cell--empty" title="${DOW_LABELS[dow]} ${String(hour).padStart(2, '0')}h · sem amostras"></div>`
                );
                continue;
            }

            const color = ratioToColor(cell.avgRatio);
            const title = [
                `${DOW_LABELS[dow]} ${String(hour).padStart(2, '0')}h`,
                `Ratio: ${fmtRatio(cell.avgRatio)} vs histórico`,
                cell.avgDelay !== null
                    ? `Atraso médio: +${Math.round(cell.avgDelay)}s`
                    : null,
                `${cell.count} amostra${cell.count !== 1 ? 's' : ''}`,
            ]
                .filter(Boolean)
                .join(' · ');

            html.push(
                `<div class="rs-heatmap__cell" style="background:${color}" title="${title}"></div>`
            );
        }
    }

    container.innerHTML = html.join('');
}

// ─────────────────────────────────────────────────────────────────────────
// Mapa principal
// ─────────────────────────────────────────────────────────────────────────

function showMapFallback(container) {
    const fallback = container.querySelector('[data-rs-map-fallback]');

    if (fallback) {
        fallback.hidden = false;
    }
}

function initMainMap(root, attempt = 0) {
    const page = resolvePage(root);

    if (!page) {
        return;
    }

    const container = page.querySelector('[data-rs-map]');

    if (!container || mainMap) {
        return;
    }

    if (typeof L === 'undefined') {
        if (attempt < 20) {
            setTimeout(() => initMainMap(root, attempt + 1), 100);
        } else {
            console.warn('[WazeBR route-show] Leaflet não carregou');
            showMapFallback(container);
        }

        return;
    }

    const routePolyline = safeJson(page.dataset.routePolyline, []) || [];
    const alerts = safeJson(page.dataset.nearbyAlerts, []) || [];
    const irregularities = safeJson(page.dataset.irregularities, []) || [];
    const subPolylines = safeJson(page.dataset.subroutesPolyline, {}) || {};

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

        if (routePolyline.length > 1) {
            L.polyline(routePolyline, {
                color: '#94a3b8',
                weight: 7,
                opacity: 0.55,
                lineCap: 'round',
                lineJoin: 'round',
            }).addTo(mainMap);
        }

        Object.values(subPolylines).forEach((line) => {
            if (!Array.isArray(line) || line.length < 2) {
                return;
            }

            L.polyline(line, {
                color: COLORS.blue,
                weight: 4,
                opacity: 0.7,
                lineCap: 'round',
                lineJoin: 'round',
            }).addTo(mainMap);
        });

        alerts.forEach((alert) => {
            if (!Number.isFinite(alert.lat) || !Number.isFinite(alert.lng)) {
                return;
            }

            L.circleMarker([alert.lat, alert.lng], {
                radius: 9,
                color: '#ffffff',
                weight: 2,
                fillColor: alertColor(alert.type),
                fillOpacity: 0.95,
            })
                .bindPopup(`
                    <div class="rs-popup">
                        <strong>${escapeHtml(alert.typeLabel || alert.type || 'Alerta')}</strong>
                        ${alert.subtype ? `<small>${escapeHtml(alert.subtype)}</small>` : ''}
                        ${alert.street ? `<div class="rs-popup__row"><span>Via</span><span>${escapeHtml(alert.street)}</span></div>` : ''}
                        ${alert.city ? `<div class="rs-popup__row"><span>Cidade</span><span>${escapeHtml(alert.city)}</span></div>` : ''}
                        <div class="rs-popup__row"><span>Distância</span><span>${alert.distanceMeters} m</span></div>
                        ${alert.pubDateTime ? `<div class="rs-popup__row"><span>Quando</span><span>${new Date(alert.pubDateTime).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })}</span></div>` : ''}
                    </div>
                `)
                .addTo(mainMap);
        });

        irregularities.forEach((irregularity) => {
            if (
                !Number.isFinite(irregularity.lat) ||
                !Number.isFinite(irregularity.lng)
            ) {
                return;
            }

            L.circleMarker([irregularity.lat, irregularity.lng], {
                radius: 8,
                color: '#ffffff',
                weight: 2,
                fillColor: COLORS.purple,
                fillOpacity: 0.9,
            })
                .bindPopup(`
                    <div class="rs-popup">
                        <strong>${escapeHtml(irregularity.type || 'Irregularidade')}</strong>
                        ${irregularity.subtype ? `<small>${escapeHtml(irregularity.subtype)}</small>` : ''}
                        ${irregularity.street ? `<div class="rs-popup__row"><span>Via</span><span>${escapeHtml(irregularity.street)}</span></div>` : ''}
                        ${irregularity.severity ? `<div class="rs-popup__row"><span>Severidade</span><span>${escapeHtml(irregularity.severity)}</span></div>` : ''}
                    </div>
                `)
                .addTo(mainMap);
        });

        const allPoints = [
            ...routePolyline,
            ...alerts
                .map((alert) => [alert.lat, alert.lng])
                .filter((point) => point[0] && point[1]),
            ...irregularities
                .map((irregularity) => [irregularity.lat, irregularity.lng])
                .filter((point) => point[0] && point[1]),
        ];

        if (allPoints.length > 0) {
            try {
                mainMap.fitBounds(allPoints, {
                    padding: [30, 30],
                    maxZoom: 16,
                });
            } catch {
                // Ignora falha de enquadramento.
            }
        }

        setTimeout(() => mainMap && mainMap.invalidateSize(), 100);
        setTimeout(() => mainMap && mainMap.invalidateSize(), 400);

        window.addEventListener('resize', () => {
            mainMap && mainMap.invalidateSize();
        });

        window.routeShowMap = mainMap;
    } catch (error) {
        console.error('[WazeBR route-show] falha ao montar o mapa', error);
        showMapFallback(container);
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Mini-mapas das sub-rotas
// ─────────────────────────────────────────────────────────────────────────

function initSubRouteMaps(root, attempt = 0) {
    const page = resolvePage(root);

    if (!page) {
        return;
    }

    if (typeof L === 'undefined') {
        if (attempt < 20) {
            setTimeout(() => initSubRouteMaps(root, attempt + 1), 100);
        }

        return;
    }

    const routePolyline = safeJson(page.dataset.routePolyline, []) || [];
    const subPolylines = safeJson(page.dataset.subroutesPolyline, {}) || {};
    const alerts = safeJson(page.dataset.nearbyAlerts, []) || [];

    page.querySelectorAll('[data-subroute-map]').forEach((container) => {
        if (container.dataset.miniMapInit === '1') {
            return;
        }

        container.dataset.miniMapInit = '1';

        const subRouteId = Number(container.dataset.subrouteId);
        const subLine =
            subPolylines[subRouteId] || subPolylines[String(subRouteId)];

        if (!Array.isArray(subLine) || subLine.length < 2) {
            container.classList.add('is-empty');
            return;
        }

        try {
            const map = L.map(container, {
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

            L.tileLayer(
                'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png',
                {
                    maxZoom: 19,
                    subdomains: 'abcd',
                }
            ).addTo(map);

            if (routePolyline.length > 1) {
                L.polyline(routePolyline, {
                    color: '#64748b',
                    weight: 3,
                    opacity: 0.5,
                    lineCap: 'round',
                    lineJoin: 'round',
                }).addTo(map);
            }

            const bbox = computeBBox(subLine, 0.0008);

            if (bbox) {
                alerts.forEach((alert) => {
                    if (
                        !Number.isFinite(alert.lat) ||
                        !Number.isFinite(alert.lng)
                    ) {
                        return;
                    }

                    if (!pointInBBox(alert.lat, alert.lng, bbox)) {
                        return;
                    }

                    L.circleMarker([alert.lat, alert.lng], {
                        radius: 4,
                        color: '#ffffff',
                        weight: 1.5,
                        fillColor: alertColor(alert.type),
                        fillOpacity: 0.95,
                    }).addTo(map);
                });
            }

            L.polyline(subLine, {
                color: COLORS.blue,
                weight: 5,
                opacity: 1,
                lineCap: 'round',
                lineJoin: 'round',
            }).addTo(map);

            const start = subLine[0];
            const end = subLine[subLine.length - 1];

            L.circleMarker(start, {
                radius: 4.5,
                color: '#ffffff',
                weight: 2,
                fillColor: '#16a34a',
                fillOpacity: 1,
            }).addTo(map);

            L.circleMarker(end, {
                radius: 4.5,
                color: '#ffffff',
                weight: 2,
                fillColor: '#dc2626',
                fillOpacity: 1,
            }).addTo(map);

            try {
                map.fitBounds(subLine, {
                    padding: [16, 16],
                    maxZoom: 16,
                });
            } catch {
                // Ignora falha de enquadramento.
            }

            miniMaps.push(map);
            setTimeout(() => map.invalidateSize(), 80);
        } catch (error) {
            console.warn(
                '[WazeBR route-show] mini-mapa falhou',
                subRouteId,
                error
            );
            container.classList.add('is-empty');
        }
    });
}

// ─────────────────────────────────────────────────────────────────────────
// Chart.js
// ─────────────────────────────────────────────────────────────────────────

const alertLinesPlugin = {
    id: 'rsAlertLines',
    afterDatasetsDraw(chart, args, options) {
        const indices = options?.indices || [];

        if (indices.length === 0) {
            return;
        }

        const { ctx, chartArea, scales } = chart;
        const xScale = scales.x;

        if (!xScale) {
            return;
        }

        ctx.save();
        ctx.setLineDash([3, 3]);
        ctx.lineWidth = 1.5;
        ctx.strokeStyle = 'rgba(234, 88, 12, 0.55)';

        indices.forEach((index) => {
            const x = xScale.getPixelForValue(index);

            if (!Number.isFinite(x)) {
                return;
            }

            if (x < chartArea.left || x > chartArea.right) {
                return;
            }

            ctx.beginPath();
            ctx.moveTo(x, chartArea.top);
            ctx.lineTo(x, chartArea.bottom);
            ctx.stroke();
        });

        ctx.restore();
    },
};

if (typeof Chart !== 'undefined') {
    Chart.register(alertLinesPlugin);
}

function bucketFor(dateString) {
    const match = String(dateString).match(
        /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):/
    );

    if (!match) {
        return null;
    }

    let [, year, month, day, hour] = match;

    hour = parseInt(hour, 10) - 3;

    if (hour < 0) {
        hour += 24;

        const date = new Date(`${year}-${month}-${day}T00:00:00Z`);
        date.setUTCDate(date.getUTCDate() - 1);

        year = date.getUTCFullYear();
        month = String(date.getUTCMonth() + 1).padStart(2, '0');
        day = String(date.getUTCDate()).padStart(2, '0');
    }

    return `${year}-${month}-${day} ${String(hour).padStart(2, '0')}:00:00`;
}

function alertIndices(alerts, labels) {
    const indices = new Set();

    alerts.forEach((alert) => {
        const bucket = bucketFor(alert.collectedAt || '');

        if (!bucket) {
            return;
        }

        const index = labels.indexOf(bucket);

        if (index >= 0) {
            indices.add(index);
        }
    });

    return [...indices].sort((a, b) => a - b);
}

function initCharts(root) {
    const page = resolvePage(root);

    if (!page || typeof Chart === 'undefined') {
        return;
    }

    Chart.defaults.color = COLORS.text;
    Chart.defaults.font.family =
        "Inter, system-ui, -apple-system, 'Segoe UI', sans-serif";
    Chart.defaults.font.size = 12;

    const alerts = safeJson(page.dataset.nearbyAlerts, []) || [];

    page.querySelectorAll('[data-chart="route-timeline"]').forEach((canvas) => {
        const points = safeJson(canvas.dataset.chartData, []) || [];

        const labels = points.map((point) => {
            const time = point.time || '';

            return time.length >= 16
                ? `${time.slice(8, 10)}/${time.slice(5, 7)} ${time.slice(11, 16)}`
                : time;
        });

        const delay = points.map((point) => point.avgDelay);
        const ratio = points.map((point) =>
            point.avgRatio !== null ? point.avgRatio * 100 : null
        );
        const alertLineIndices = alertIndices(
            alerts,
            points.map((point) => point.time)
        );

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
                        fill: true,
                        tension: 0.35,
                        pointRadius: 0,
                        borderWidth: 2,
                        yAxisID: 'y',
                    },
                    {
                        label: '% vs histórico',
                        data: ratio,
                        borderColor: COLORS.red,
                        backgroundColor: 'rgba(220,38,38,.08)',
                        fill: false,
                        tension: 0.35,
                        pointRadius: 0,
                        borderWidth: 2,
                        borderDash: [4, 3],
                        yAxisID: 'y1',
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                resizeDelay: 120,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        display: false,
                    },
                    tooltip: {
                        callbacks: {
                            label(context) {
                                return context.dataset.label ===
                                    '% vs histórico'
                                    ? `${context.parsed.y?.toFixed(0)}%`
                                    : `+${context.parsed.y?.toFixed(0)}s`;
                            },
                        },
                    },
                    rsAlertLines: {
                        indices: alertLineIndices,
                    },
                },
                scales: {
                    x: {
                        grid: {
                            display: false,
                        },
                    },
                    y: {
                        position: 'left',
                        grid: {
                            color: COLORS.grid,
                        },
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'segundos',
                        },
                    },
                    y1: {
                        position: 'right',
                        grid: {
                            display: false,
                        },
                        beginAtZero: true,
                        ticks: {
                            callback(value) {
                                return `${value}%`;
                            },
                        },
                    },
                },
            },
        });
    });

    page.querySelectorAll('[data-chart="route-by-hour"]').forEach((canvas) => {
        const points = safeJson(canvas.dataset.chartData, []) || [];

        const labels = points.map(
            (point) => `${String(point.hour).padStart(2, '0')}h`
        );
        const ratios = points.map((point) => point.avgRatio);
        const delays = points.map((point) => point.avgDelay ?? 0);
        const counts = points.map((point) => point.count ?? 0);

        const backgrounds = ratios.map((ratio, index) => {
            if (counts[index] < 2 || ratio === null) {
                return 'rgba(148,163,184,.35)';
            }

            return ratioToColor(ratio);
        });

        mountChart('routeByHour', canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [
                    {
                        label: 'Atraso médio (s)',
                        data: delays,
                        backgroundColor: backgrounds,
                        borderRadius: 4,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                resizeDelay: 120,
                plugins: {
                    legend: {
                        display: false,
                    },
                    tooltip: {
                        callbacks: {
                            label(context) {
                                const index = context.dataIndex;

                                if (counts[index] < 2 || ratios[index] === null) {
                                    return 'Sem amostras suficientes';
                                }

                                return `+${Math.round(delays[index])}s · ${Math.round(ratios[index] * 100)}% vs histórico · ${counts[index]} amostras`;
                            },
                        },
                    },
                },
                scales: {
                    x: {
                        grid: {
                            display: false,
                        },
                    },
                    y: {
                        grid: {
                            color: COLORS.grid,
                        },
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'segundos',
                        },
                    },
                },
            },
        });
    });

    page.querySelectorAll('[data-chart="route-by-dow"]').forEach((canvas) => {
        const points = safeJson(canvas.dataset.chartData, []) || [];

        const labels = points.map(
            (point) => DOW_LABELS[point.dow] ?? '?'
        );
        const ratios = points.map((point) => point.avgRatio);
        const delays = points.map((point) => point.avgDelay ?? 0);
        const counts = points.map((point) => point.count ?? 0);

        const backgrounds = ratios.map((ratio, index) => {
            if (counts[index] < 2 || ratio === null) {
                return 'rgba(148,163,184,.35)';
            }

            return ratioToColor(ratio);
        });

        mountChart('routeByDow', canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [
                    {
                        label: 'Atraso médio (s)',
                        data: delays,
                        backgroundColor: backgrounds,
                        borderRadius: 4,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                resizeDelay: 120,
                plugins: {
                    legend: {
                        display: false,
                    },
                    tooltip: {
                        callbacks: {
                            label(context) {
                                const index = context.dataIndex;

                                if (counts[index] < 2 || ratios[index] === null) {
                                    return 'Sem amostras suficientes';
                                }

                                return `+${Math.round(delays[index])}s · ${Math.round(ratios[index] * 100)}% vs histórico · ${counts[index]} amostras`;
                            },
                        },
                    },
                },
                scales: {
                    x: {
                        grid: {
                            display: false,
                        },
                    },
                    y: {
                        grid: {
                            color: COLORS.grid,
                        },
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'segundos',
                        },
                    },
                },
            },
        });
    });

    page.querySelectorAll('[data-chart="route-jam-dist"]').forEach((canvas) => {
        const points = safeJson(canvas.dataset.chartData, []) || [];
        const filtered = points.filter((point) => point.count > 0);

        const labels = filtered.map(
            (point) => JAM_LABELS[point.level] ?? `Nível ${point.level}`
        );
        const data = filtered.map((point) => point.count);
        const backgrounds = filtered.map(
            (point) => JAM_COLORS[point.level] ?? '#94a3b8'
        );

        mountChart('routeJamDist', canvas, {
            type: 'doughnut',
            data: {
                labels,
                datasets: [
                    {
                        data,
                        backgroundColor: backgrounds,
                        borderWidth: 0,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                resizeDelay: 120,
                cutout: '62%',
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            usePointStyle: true,
                            boxWidth: 8,
                        },
                    },
                    tooltip: {
                        callbacks: {
                            label(context) {
                                const total = context.dataset.data.reduce(
                                    (accumulator, value) => accumulator + value,
                                    0
                                );
                                const percentage =
                                    total > 0
                                        ? Math.round(
                                              (context.parsed / total) * 100
                                          )
                                        : 0;

                                return `${context.parsed} amostras (${percentage}%)`;
                            },
                        },
                    },
                },
            },
        });
    });
}

// ─────────────────────────────────────────────────────────────────────────
// Menu de exportação
// ─────────────────────────────────────────────────────────────────────────

function initExportMenu(root) {
    const page = resolvePage(root);

    if (!page) {
        return;
    }

    const wrapper = page.querySelector('[data-export-menu]');
    const toggle = page.querySelector('[data-export-toggle]');
    const dropdown = page.querySelector('[data-export-dropdown]');

    if (!wrapper || !toggle || !dropdown) {
        return;
    }

    const open = () => {
        dropdown.hidden = false;
        toggle.setAttribute('aria-expanded', 'true');
        dropdown.querySelector('.rs-export__item')?.focus();
    };

    const close = () => {
        dropdown.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
    };

    toggle.addEventListener('click', (event) => {
        event.stopPropagation();
        dropdown.hidden ? open() : close();
    });

    document.addEventListener('click', (event) => {
        if (!wrapper.contains(event.target)) {
            close();
        }
    });

    wrapper.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            close();
            toggle.focus();
        }
    });

    dropdown.querySelectorAll('.rs-export__item').forEach((link) => {
        link.addEventListener('click', () => {
            setTimeout(close, 200);
        });
    });
}

// ─────────────────────────────────────────────────────────────────────────
// Bootstrap
// ─────────────────────────────────────────────────────────────────────────

export function initRouteShow(root = document) {
    const page = resolvePage(root);

    if (!page || bootstrapped) {
        return;
    }

    bootstrapped = true;

    renderHeatmap(page);
    initCharts(page);
    initMainMap(page);
    initSubRouteMaps(page);
    initExportMenu(page);
}

export default initRouteShow;