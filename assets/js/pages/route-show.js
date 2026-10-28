import { rsT, rsLabel, RS_LOCALE } from './route-show-i18n.js';

/**
 * pages/route-show.js — Página /routes/{id}
 *
 * - Mapa principal (Leaflet): traçado + sub-rotas + alertas + irregularidades
 * - Mini-mapas por trecho problemático (criados só quando entram na tela)
 * - Mapa das últimas horas, com slider/play, pintando cada trecho por % vs histórico
 * - Heatmap dia × hora
 * - Gráficos Chart.js
 * - Dropdowns de exportação CSV e de relatório/link compartilhável
 * - Favoritar rota
 * - Atualização ao vivo (route-live.js, via Mercure com fallback para polling)
 *
 * Dados: um único <script type="application/json" data-route-show-data>
 * emitido por templates/routes/show.html.twig. Datas "cruas" vêm em ISO-8601
 * UTC; `timeline[].time` e `alert.bucket` já vêm no fuso de exibição.
 * Inicializado pelo registry de assets/js/app.js (match: [data-route-show]).
 */

const COLORS = {
    blue: '#2563eb',
    red: '#dc2626',
    purple: '#7c3aed',
    grid: 'rgba(148, 163, 184, .25)',
    text: '#475569',
    muted: 'rgba(148, 163, 184, .35)',
};

const DOW_LABELS = [rsT("Seg"), rsT("Ter"), rsT("Qua"), rsT("Qui"), rsT("Sex"), rsT("Sáb"), rsT("Dom")];

const JAM_LABELS = [rsT("Sem congestionamento"), rsT("Baixo"), rsT("Moderado"), rsT("Alto"), rsT("Muito alto"), rsT("Parado")];
const JAM_COLORS = ['#16a34a', '#84cc16', '#f59e0b', '#f97316', '#ea580c', '#dc2626'];

const ALERT_COLORS = {
    ACCIDENT: '#dc2626',
    JAM: '#ea580c',
    ROAD_CLOSED: '#111827',
    POLICE: '#1d4ed8',
    WEATHERHAZARD: '#0891b2',
    HAZARD: '#f59e0b',
    CONSTRUCTION: '#7c3aed',
};

const MIN_SAMPLES = 2;
const NEUTRAL_LINE = '#94a3b8';

// A CSP do projeto (nelmio_security.yaml → img-src) só libera tiles do OpenStreetMap.
// Qualquer outro provedor de tiles é bloqueado e o mapa fica em branco.
const TILE_URL = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
const DEFAULT_TZ = 'America/Sao_Paulo';

const charts = new Map();
const miniMaps = [];

let mainMap = null;
let bootstrapped = false;

// ─────────────────────────────────────────────────────────────────────────
// Utils
// ─────────────────────────────────────────────────────────────────────────

function safeJson(value, fallback) {
    try {
        const parsed = JSON.parse(value);
        return parsed ?? fallback;
    } catch {
        return fallback;
    }
}

function resolvePage(root) {
    if (!root) return null;
    if (root.matches?.('[data-route-show]')) return root;
    return root.querySelector?.('[data-route-show]') ?? null;
}

/** Lê (e guarda em cache) o payload JSON da página. */
function readData(page) {
    if (page._routeData) return page._routeData;

    const raw = page.querySelector('[data-route-show-data]')?.textContent ?? '{}';
    const data = safeJson(raw, {});

    page._routeData = {
        routePolyline: data.routePolyline ?? [],
        subRoutesPolyline: data.subRoutesPolyline ?? {},
        nearbyAlerts: data.nearbyAlerts ?? [],
        irregularities: data.irregularities ?? [],
        heatmap: data.heatmap ?? [],
        timeline: data.timeline ?? [],
        byHour: data.byHour ?? [],
        byDow: data.byDow ?? [],
        jamDistribution: data.jamDistribution ?? [],
        recentWindow: data.recentWindow ?? { hours: 6, frames: [], avg: {}, hasSubData: false },
        subNames: data.subNames ?? {},
    };

    return page._routeData;
}

function timezoneOf(page) {
    return page.dataset.timezone || DEFAULT_TZ;
}

function whenLeaflet(callback, onFail, attempt = 0) {
    if (typeof L !== 'undefined') {
        callback();
    } else if (attempt < 30) {
        setTimeout(() => whenLeaflet(callback, onFail, attempt + 1), 100);
    } else {
        onFail?.();
    }
}

/** Executa `callback` uma vez, quando o elemento chega perto da viewport. */
function onVisible(element, callback) {
    if (typeof IntersectionObserver === 'undefined') {
        callback();
        return;
    }

    const observer = new IntersectionObserver(
        (entries) => {
            if (!entries.some((entry) => entry.isIntersecting)) return;
            observer.disconnect();
            callback();
        },
        { rootMargin: '200px' }
    );

    observer.observe(element);
}

function destroyChart(key) {
    const chart = charts.get(key);
    if (chart) {
        chart.destroy();
        charts.delete(key);
    }
}

function mountChart(key, canvas, config) {
    if (!canvas || typeof Chart === 'undefined') return;
    destroyChart(key);
    charts.set(key, new Chart(canvas.getContext('2d'), config));
}

function ratioToColor(ratio) {
    if (ratio === null || ratio === undefined) return null;

    const normalized = Math.max(0, Math.min(ratio, 1));
    const hue = 140 - normalized * 140;
    const saturation = 62 + normalized * 12;
    const lightness = 44 - normalized * 2;

    return `hsl(${hue}, ${saturation}%, ${lightness}%)`;
}

function fmtRatio(ratio) {
    return ratio === null || ratio === undefined ? '—' : `${Math.round(ratio * 100)}%`;
}

/** +12s / −3s */
function fmtSignedSeconds(value) {
    if (value === null || value === undefined || !Number.isFinite(value)) return '—';
    const rounded = Math.round(value);
    return `${rounded >= 0 ? '+' : '−'}${Math.abs(rounded)}s`;
}

function alertColor(type) {
    return ALERT_COLORS[String(type || '').toUpperCase()] ?? COLORS.blue;
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function formatDateTime(iso, timeZone) {
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '—';

    return date.toLocaleString(RS_LOCALE, {
        timeZone,
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function computeBBox(line, padding = 0.0005) {
    if (!Array.isArray(line) || line.length === 0) return null;

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
    return lat >= bbox.minLat && lat <= bbox.maxLat && lng >= bbox.minLng && lng <= bbox.maxLng;
}

function isValidPoint(lat, lng) {
    return Number.isFinite(lat) && Number.isFinite(lng) && !(lat === 0 && lng === 0);
}

// ─────────────────────────────────────────────────────────────────────────
// Heatmap
// ─────────────────────────────────────────────────────────────────────────

function renderHeatmap(page) {
    const container = page.querySelector('[data-heatmap]');
    if (!container) return;

    const lookup = new Map();
    readData(page).heatmap.forEach((cell) => lookup.set(`${cell.dow}:${cell.hour}`, cell));

    const html = ['<div class="rs-heatmap__corner"></div>'];

    for (let hour = 0; hour < 24; hour++) {
        html.push(`<div class="rs-heatmap__hour-label">${String(hour).padStart(2, '0')}</div>`);
    }

    for (let dow = 0; dow < 7; dow++) {
        html.push(`<div class="rs-heatmap__dow-label">${DOW_LABELS[dow]}</div>`);

        for (let hour = 0; hour < 24; hour++) {
            const cell = lookup.get(`${dow}:${hour}`);
            const label = `${DOW_LABELS[dow]} ${String(hour).padStart(2, '0')}h`;

            if (!cell || cell.count < MIN_SAMPLES || cell.avgRatio === null) {
                html.push(
                    `<div class="rs-heatmap__cell rs-heatmap__cell--empty" title="${label} · sem amostras"></div>`
                );
                continue;
            }

            const title = [
                label,
                `Ratio: ${fmtRatio(cell.avgRatio)} ${rsT("vs histórico")}`,
                cell.avgDelay !== null ? `Atraso médio: ${fmtSignedSeconds(cell.avgDelay)}` : null,
                `${cell.count} amostra${cell.count !== 1 ? 's' : ''}`,
            ]
                .filter(Boolean)
                .join(' · ');

            html.push(
                `<div class="rs-heatmap__cell" style="background:${ratioToColor(cell.avgRatio)}" title="${title}"></div>`
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
    if (fallback) fallback.hidden = false;
}

function alertPopup(alert, timeZone) {
    const row = (label, value) =>
        `<div class="rs-popup__row"><span>${label}</span><span>${value}</span></div>`;

    return `
        <div class="rs-popup">
            <strong>${escapeHtml(alert.typeLabel || alert.type || rsT("Alerta"))}</strong>
            ${alert.subtype ? `<small>${escapeHtml(rsLabel(alert.subtype))}</small>` : ''}
            ${alert.street ? row('Via', escapeHtml(alert.street)) : ''}
            ${alert.city ? row('Cidade', escapeHtml(alert.city)) : ''}
            ${row(rsT("Distância"), `${escapeHtml(alert.distanceMeters)} m`)}
            ${alert.pubDateTime ? row(rsT("Quando"), formatDateTime(alert.pubDateTime, timeZone)) : ''}
        </div>`;
}

function irregularityPopup(irr, timeZone) {
    const row = (label, value) =>
        `<div class="rs-popup__row"><span>${label}</span><span>${value}</span></div>`;

    return `
        <div class="rs-popup">
            <strong>${escapeHtml(irr.type || 'Irregularidade')}</strong>
            ${irr.subtype ? `<small>${escapeHtml(rsLabel(irr.subtype))}</small>` : ''}
            ${irr.street ? row('Via', escapeHtml(irr.street)) : ''}
            ${irr.severity ? row('Severidade', escapeHtml(irr.severity)) : ''}
            ${irr.reportedAt ? row(rsT("Quando"), formatDateTime(irr.reportedAt, timeZone)) : ''}
        </div>`;
}

function initMainMap(page) {
    const container = page.querySelector('[data-rs-map]');
    if (!container || mainMap) return;

    const { routePolyline, subRoutesPolyline, nearbyAlerts, irregularities } = readData(page);
    const subLines = Object.values(subRoutesPolyline).filter((l) => Array.isArray(l) && l.length >= 2);

    if (routePolyline.length < 2 && subLines.length === 0) {
        showMapFallback(container);
        return;
    }

    whenLeaflet(
        () => buildMainMap(page, container, routePolyline, subLines, nearbyAlerts, irregularities),
        () => {
            console.warn('[WazeBR route-show] Leaflet não carregou');
            showMapFallback(container);
        }
    );
}

function buildMainMap(page, container, routePolyline, subLines, alerts, irregularities) {
    const timeZone = timezoneOf(page);

    try {
        mainMap = L.map(container, { zoomControl: true, preferCanvas: true });

        L.tileLayer(TILE_URL, {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap',
        }).addTo(mainMap);

        if (routePolyline.length > 1) {
            L.polyline(routePolyline, {
                color: '#94a3b8', weight: 7, opacity: 0.55, lineCap: 'round', lineJoin: 'round',
            }).addTo(mainMap);
        }

        subLines.forEach((line) => {
            L.polyline(line, {
                color: COLORS.blue, weight: 4, opacity: 0.7, lineCap: 'round', lineJoin: 'round',
            }).addTo(mainMap);
        });

        alerts.forEach((alert) => {
            if (!isValidPoint(alert.lat, alert.lng)) return;

            L.circleMarker([alert.lat, alert.lng], {
                radius: 9, color: '#ffffff', weight: 2,
                fillColor: alertColor(alert.type), fillOpacity: 0.95,
            })
                .bindPopup(alertPopup(alert, timeZone))
                .addTo(mainMap);
        });

        irregularities.forEach((irr) => {
            if (!isValidPoint(irr.lat, irr.lng)) return;

            L.circleMarker([irr.lat, irr.lng], {
                radius: 8, color: '#ffffff', weight: 2,
                fillColor: COLORS.purple, fillOpacity: 0.9,
            })
                .bindPopup(irregularityPopup(irr, timeZone))
                .addTo(mainMap);
        });

        const allPoints = [
            ...routePolyline,
            ...subLines.flat(),
            ...alerts.filter((a) => isValidPoint(a.lat, a.lng)).map((a) => [a.lat, a.lng]),
            ...irregularities.filter((i) => isValidPoint(i.lat, i.lng)).map((i) => [i.lat, i.lng]),
        ];

        if (allPoints.length > 0) {
            mainMap.fitBounds(allPoints, { padding: [30, 30], maxZoom: 16 });
        }

        // Mantém o mapa correto quando o container muda de tamanho (sidebar, resize, fontes…).
        if (typeof ResizeObserver !== 'undefined') {
            new ResizeObserver(() => mainMap?.invalidateSize()).observe(container);
        } else {
            window.addEventListener('resize', () => mainMap?.invalidateSize());
        }

        setTimeout(() => mainMap?.invalidateSize(), 100);

        window.routeShowMap = mainMap;
    } catch (error) {
        console.error('[WazeBR route-show] falha ao montar o mapa', error);
        showMapFallback(container);
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Mini-mapas das sub-rotas (lazy: só quando o card entra na viewport)
// ─────────────────────────────────────────────────────────────────────────

function initSubRouteMaps(page) {
    page.querySelectorAll('[data-subroute-map]').forEach((container) => {
        onVisible(container, () => {
            if (container.dataset.miniMapInit === '1') return;
            container.dataset.miniMapInit = '1';

            whenLeaflet(() => buildMiniMap(page, container));
        });
    });
}

function buildMiniMap(page, container) {
    const { routePolyline, subRoutesPolyline, nearbyAlerts } = readData(page);

    const subRouteId = container.dataset.subrouteId;
    const subLine = subRoutesPolyline[subRouteId];

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

        L.tileLayer(TILE_URL, { maxZoom: 19 }).addTo(map);

        if (routePolyline.length > 1) {
            L.polyline(routePolyline, {
                color: '#64748b', weight: 3, opacity: 0.5, lineCap: 'round', lineJoin: 'round',
            }).addTo(map);
        }

        const bbox = computeBBox(subLine, 0.0008);

        if (bbox) {
            nearbyAlerts.forEach((alert) => {
                if (!isValidPoint(alert.lat, alert.lng) || !pointInBBox(alert.lat, alert.lng, bbox)) return;

                L.circleMarker([alert.lat, alert.lng], {
                    radius: 4, color: '#ffffff', weight: 1.5,
                    fillColor: alertColor(alert.type), fillOpacity: 0.95,
                }).addTo(map);
            });
        }

        L.polyline(subLine, {
            color: COLORS.blue, weight: 5, opacity: 1, lineCap: 'round', lineJoin: 'round',
        }).addTo(map);

        [[subLine[0], '#16a34a'], [subLine[subLine.length - 1], '#dc2626']].forEach(([point, color]) => {
            L.circleMarker(point, {
                radius: 4.5, color: '#ffffff', weight: 2, fillColor: color, fillOpacity: 1,
            }).addTo(map);
        });

        map.fitBounds(subLine, { padding: [16, 16], maxZoom: 16 });

        miniMaps.push(map);
        setTimeout(() => map.invalidateSize(), 80);
    } catch (error) {
        console.warn('[WazeBR route-show] mini-mapa falhou', subRouteId, error);
        container.classList.add('is-empty');
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Mapa das últimas horas (slider + play)
// ─────────────────────────────────────────────────────────────────────────

function initRecentMap(page) {
    const container = page.querySelector('[data-recent-map]');
    if (!container) return;

    const { recentWindow } = readData(page);
    if (!recentWindow.frames.length) return;

    onVisible(container, () => whenLeaflet(() => buildRecentMap(page, container, recentWindow)));
}

function buildRecentMap(page, container, win) {
    const { routePolyline, subRoutesPolyline, nearbyAlerts, irregularities, subNames } = readData(page);
    const timeZone = timezoneOf(page);

    const slider = page.querySelector('[data-recent-slider]');
    const timeLabel = page.querySelector('[data-recent-time]');
    const playBtn = page.querySelector('[data-recent-play]');
    const avgBtn = page.querySelector('[data-recent-avg]');

    const frames = win.frames;
    const hasSubLines = win.hasSubData && Object.keys(subRoutesPolyline).length > 0;

    let map;
    try {
        map = L.map(container, { zoomControl: true, preferCanvas: true });
    } catch (error) {
        console.warn('[WazeBR route-show] mapa das últimas horas falhou', error);
        return;
    }

    L.tileLayer(TILE_URL, { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);

    // Traçado principal: base cinza quando há trechos; é ele que recebe a cor quando não há.
    const mainLayer = routePolyline.length > 1
        ? L.polyline(routePolyline, {
            color: NEUTRAL_LINE,
            weight: hasSubLines ? 8 : 7,
            opacity: hasSubLines ? 0.35 : 0.95,
            lineCap: 'round',
            lineJoin: 'round',
        }).addTo(map)
        : null;

    const subLayers = new Map();
    if (hasSubLines) {
        Object.entries(subRoutesPolyline).forEach(([id, line]) => {
            if (!Array.isArray(line) || line.length < 2) return;

            const layer = L.polyline(line, {
                color: NEUTRAL_LINE, weight: 6, opacity: 0.95, lineCap: 'round', lineJoin: 'round',
            }).addTo(map);

            layer.bindTooltip(
                () => `${escapeHtml(subNames[`s${id}`] ?? rsT("Trecho"))} · ${fmtRatio(layer._rsRatio)}`,
                { sticky: true }
            );
            subLayers.set(id, layer);
        });
    }

    const recentAlerts = nearbyAlerts.filter((a) => a.isRecent && isValidPoint(a.lat, a.lng));
    const recentIrr = irregularities.filter((i) => i.isRecent && isValidPoint(i.lat, i.lng));

    recentAlerts.forEach((alert) => {
        L.circleMarker([alert.lat, alert.lng], {
            radius: 8, color: '#ffffff', weight: 2, fillColor: alertColor(alert.type), fillOpacity: 0.95,
        })
            .bindPopup(alertPopup(alert, timeZone))
            .addTo(map);
    });

    recentIrr.forEach((irr) => {
        L.circleMarker([irr.lat, irr.lng], {
            radius: 7, color: '#ffffff', weight: 2, fillColor: COLORS.purple, fillOpacity: 0.9,
        })
            .bindPopup(irregularityPopup(irr, timeZone))
            .addTo(map);
    });

    const bounds = [
        ...routePolyline,
        ...[...subLayers.values()].flatMap((layer) => layer.getLatLngs().map((p) => [p.lat, p.lng])),
        ...recentAlerts.map((a) => [a.lat, a.lng]),
        ...recentIrr.map((i) => [i.lat, i.lng]),
    ];

    if (bounds.length > 0) {
        map.fitBounds(bounds, { padding: [30, 30], maxZoom: 16 });
    } else {
        map.setView([-15.78, -47.93], 4);
    }

    if (typeof ResizeObserver !== 'undefined') {
        new ResizeObserver(() => map.invalidateSize()).observe(container);
    }
    setTimeout(() => map.invalidateSize(), 100);

    // Média da rota inteira no período (usada no modo "Média").
    const routeRatios = frames.map((f) => f.route).filter((r) => r !== null);
    const routeAvg = routeRatios.length ? routeRatios.reduce((a, b) => a + b, 0) / routeRatios.length : null;

    let index = frames.length - 1;
    let averageMode = false;
    let timer = null;

    const paint = (layer, ratio) => {
        layer._rsRatio = ratio;
        layer.setStyle({ color: ratioToColor(ratio) ?? NEUTRAL_LINE });
    };

    function render() {
        const frame = frames[index];

        if (hasSubLines) {
            subLayers.forEach((layer, id) => {
                paint(layer, averageMode ? (win.avg[id] ?? null) : (frame.subs[id] ?? null));
            });
        } else if (mainLayer) {
            paint(mainLayer, averageMode ? routeAvg : frame.route);
        }

        if (timeLabel) {
            timeLabel.textContent = averageMode
                ? `${rsT("Média")} ${win.hours}h`
                : formatDateTime(frame.at, timeZone).replace(',', '');
        }
        if (slider) slider.value = String(index);
        avgBtn?.setAttribute('aria-pressed', averageMode ? 'true' : 'false');
    }

    function stop() {
        if (timer) clearInterval(timer);
        timer = null;
        if (playBtn) {
            playBtn.textContent = rsT("▶ Reproduzir");
            playBtn.setAttribute('aria-pressed', 'false');
        }
    }

    function play() {
        averageMode = false;
        if (index >= frames.length - 1) index = 0;
        render();

        if (playBtn) {
            playBtn.textContent = rsT("⏸ Pausar");
            playBtn.setAttribute('aria-pressed', 'true');
        }

        timer = setInterval(() => {
            if (index >= frames.length - 1) {
                stop();
                return;
            }
            index += 1;
            render();
        }, 800);
    }

    slider?.addEventListener('input', () => {
        stop();
        averageMode = false;
        index = Number(slider.value);
        render();
    });

    playBtn?.addEventListener('click', () => (timer ? stop() : play()));

    avgBtn?.addEventListener('click', () => {
        stop();
        averageMode = !averageMode;
        render();
    });

    window.addEventListener('pagehide', stop, { once: true });

    render();
}

// ─────────────────────────────────────────────────────────────────────────
// Chart.js
// ─────────────────────────────────────────────────────────────────────────

/** Linhas verticais tracejadas nas horas em que houve alertas perto do traçado. */
const alertLinesPlugin = {
    id: 'rsAlertLines',
    afterDatasetsDraw(chart, args, options) {
        const indices = options?.indices || [];
        const xScale = chart.scales.x;

        if (indices.length === 0 || !xScale) return;

        const { ctx, chartArea } = chart;

        ctx.save();
        ctx.setLineDash([3, 3]);
        ctx.lineWidth = 1.5;
        ctx.strokeStyle = 'rgba(234, 88, 12, 0.55)';

        indices.forEach((index) => {
            const x = xScale.getPixelForValue(index);
            if (!Number.isFinite(x) || x < chartArea.left || x > chartArea.right) return;

            ctx.beginPath();
            ctx.moveTo(x, chartArea.top);
            ctx.lineTo(x, chartArea.bottom);
            ctx.stroke();
        });

        ctx.restore();
    },
};

/** Índices do eixo X (buckets da timeline) que têm ao menos um alerta. */
function alertIndices(alerts, buckets) {
    const position = new Map(buckets.map((bucket, index) => [bucket, index]));
    const indices = new Set();

    alerts.forEach((alert) => {
        const index = position.get(alert.bucket);
        if (index !== undefined) indices.add(index);
    });

    return [...indices].sort((a, b) => a - b);
}

function barConfig(points, labelOf) {
    const ratios = points.map((p) => p.avgRatio);
    const delays = points.map((p) => p.avgDelay ?? 0);
    const counts = points.map((p) => p.count ?? 0);
    const insufficient = (i) => counts[i] < MIN_SAMPLES || ratios[i] === null;

    return {
        type: 'bar',
        data: {
            labels: points.map(labelOf),
            datasets: [{
                label: rsT("Atraso médio (s)"),
                data: delays,
                backgroundColor: ratios.map((ratio, i) => (insufficient(i) ? COLORS.muted : ratioToColor(ratio))),
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
                        label(context) {
                            const i = context.dataIndex;
                            if (insufficient(i)) return rsT("Sem amostras suficientes");

                            return `${fmtSignedSeconds(delays[i])} · ${fmtRatio(ratios[i])} ${rsT("vs histórico")} · ${counts[i]} amostras`;
                        },
                    },
                },
            },
            scales: {
                x: { grid: { display: false } },
                y: { grid: { color: COLORS.grid }, beginAtZero: true, title: { display: true, text: 'segundos' } },
            },
        },
    };
}

function timelineConfig(points, alertLineIndices) {
    const labels = points.map(({ time = '' }) =>
        time.length >= 16 ? `${time.slice(8, 10)}/${time.slice(5, 7)} ${time.slice(11, 16)}` : time
    );

    return {
        type: 'line',
        data: {
            labels,
            datasets: [
                {
                    label: rsT("Atraso (s)"),
                    data: points.map((p) => p.avgDelay),
                    borderColor: COLORS.blue,
                    backgroundColor: 'rgba(37,99,235,.10)',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 0,
                    borderWidth: 2,
                    yAxisID: 'y',
                },
                {
                    label: rsT("% vs histórico"),
                    data: points.map((p) => (p.avgRatio !== null ? p.avgRatio * 100 : null)),
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
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label(context) {
                            return context.dataset.yAxisID === 'y1'
                                ? `${context.parsed.y?.toFixed(0)}%`
                                : fmtSignedSeconds(context.parsed.y);
                        },
                    },
                },
                rsAlertLines: { indices: alertLineIndices },
            },
            scales: {
                x: { grid: { display: false } },
                y: { position: 'left', grid: { color: COLORS.grid }, beginAtZero: true, title: { display: true, text: 'segundos' } },
                y1: {
                    position: 'right',
                    grid: { display: false },
                    beginAtZero: true,
                    ticks: { callback: (value) => `${value}%` },
                },
            },
        },
    };
}

function jamConfig(points) {
    const filtered = points.filter((p) => p.count > 0);

    return {
        type: 'doughnut',
        data: {
            labels: filtered.map((p) => JAM_LABELS[p.level] ?? `Nível ${p.level}`),
            datasets: [{
                data: filtered.map((p) => p.count),
                backgroundColor: filtered.map((p) => JAM_COLORS[p.level] ?? '#94a3b8'),
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
                        label(context) {
                            const total = context.dataset.data.reduce((sum, v) => sum + v, 0);
                            const pct = total > 0 ? Math.round((context.parsed / total) * 100) : 0;

                            return `${context.parsed} amostras (${pct}%)`;
                        },
                    },
                },
            },
        },
    };
}

function initCharts(page) {
    if (typeof Chart === 'undefined') {
        console.warn('[WazeBR route-show] Chart.js não carregou');
        return;
    }

    if (!Chart.registry.plugins.get('rsAlertLines')) {
        Chart.register(alertLinesPlugin);
    }

    Chart.defaults.color = COLORS.text;
    Chart.defaults.font.family = "Inter, system-ui, -apple-system, 'Segoe UI', sans-serif";
    Chart.defaults.font.size = 12;

    const data = readData(page);
    const canvas = (name) => page.querySelector(`[data-chart="${name}"]`);

    mountChart('routeTimeline', canvas('route-timeline'), timelineConfig(
        data.timeline,
        alertIndices(data.nearbyAlerts, data.timeline.map((p) => p.time))
    ));
    mountChart('routeByHour', canvas('route-by-hour'), barConfig(data.byHour, (p) => `${String(p.hour).padStart(2, '0')}h`));
    mountChart('routeByDow', canvas('route-by-dow'), barConfig(data.byDow, (p) => DOW_LABELS[p.dow] ?? '?'));
    mountChart('routeJamDist', canvas('route-jam-dist'), jamConfig(data.jamDistribution));
}

// ─────────────────────────────────────────────────────────────────────────
// Menu de exportação
// ─────────────────────────────────────────────────────────────────────────

/** Dropdown acessível: abre/fecha no clique, fecha com Esc/clique fora, setas navegam pelos itens. */
function bindMenu(page, rootSel, toggleSel, panelSel) {
    const wrapper = page.querySelector(rootSel);
    const toggle = page.querySelector(toggleSel);
    const panel = page.querySelector(panelSel);

    if (!wrapper || !toggle || !panel) return;

    const items = () => [...panel.querySelectorAll('.rs-export__item')];

    const open = () => {
        panel.hidden = false;
        toggle.setAttribute('aria-expanded', 'true');
        items()[0]?.focus();
    };

    const close = () => {
        panel.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
    };

    toggle.addEventListener('click', () => (panel.hidden ? open() : close()));

    document.addEventListener('click', (event) => {
        if (!wrapper.contains(event.target)) close();
    });

    wrapper.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            close();
            toggle.focus();
            return;
        }

        if (panel.hidden || (event.key !== 'ArrowDown' && event.key !== 'ArrowUp')) return;
        if (!document.activeElement?.classList.contains('rs-export__item')) return;

        event.preventDefault();
        const list = items();
        const current = list.indexOf(document.activeElement);
        const step = event.key === 'ArrowDown' ? 1 : -1;
        list[(current + step + list.length) % list.length]?.focus();
    });

    panel.addEventListener('click', (event) => {
        if (event.target.closest('.rs-export__item')) setTimeout(close, 200);
    });
}

function initExportMenu(page) {
    bindMenu(page, '[data-export-menu]', '[data-export-toggle]', '[data-export-dropdown]');
}

// ─────────────────────────────────────────────────────────────────────────
// Favoritar · compartilhar relatório
// ─────────────────────────────────────────────────────────────────────────

function initFavorite(page) {
    const form = page.querySelector('[data-favorite-form]');
    const button = form?.querySelector('[data-favorite-toggle]');
    const token = form?.querySelector('input[name="_token"]')?.value;

    if (!form || !button || !token) return;

    // Sem JavaScript o formulário já funciona (POST + redirecionamento). Com JavaScript,
    // interceptamos o envio para atualizar a estrela sem recarregar a página.
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        button.disabled = true;

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-CSRF-Token': token },
            });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(`HTTP ${response.status}`);

            button.setAttribute('aria-pressed', data.favorited ? 'true' : 'false');
            const label = data.favorited ? rsT("Remover dos favoritos") : rsT("Adicionar aos favoritos");
            button.title = label;
            const icon = button.querySelector('[data-favorite-icon]');
            if (icon) icon.textContent = data.favorited ? '★' : '☆';
            const sr = button.querySelector('.rs-sr');
            if (sr) sr.textContent = label;
        } catch (error) {
            console.warn('[WazeBR route-show] favoritar via fetch falhou; enviando o formulário', error);
            form.submit(); // fallback: POST normal + redirecionamento
        } finally {
            button.disabled = false;
        }
    });
}

function initShareMenu(page) {
    bindMenu(page, '[data-share-menu]', '[data-share-toggle]', '[data-share-panel]');

    const url = page.dataset.shareUrl;
    const token = page.dataset.shareToken;
    const generate = page.querySelector('[data-share-generate]');
    const days = page.querySelector('[data-share-days]');
    const result = page.querySelector('[data-share-result]');
    const link = page.querySelector('[data-share-link]');
    const copy = page.querySelector('[data-share-copy]');
    const expires = page.querySelector('[data-share-expires]');

    if (!generate || !url || !token || !result || !link) return;

    generate.addEventListener('click', async () => {
        generate.disabled = true;

        try {
            const body = new URLSearchParams({ days: days?.value ?? '7' });
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-CSRF-Token': token },
                body,
            });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(`HTTP ${response.status}`);

            link.value = data.url;
            result.hidden = false;
            if (expires) {
                expires.textContent = `${rsT("Válido até")} ${formatDateTime(data.expiresAt, timezoneOf(page))}`;
            }
            link.focus();
            link.select();
        } catch (error) {
            console.warn('[WazeBR route-show] gerar link falhou', error);
            result.hidden = false;
            link.value = '';
            if (expires) expires.textContent = rsT("Não foi possível gerar o link. Tente novamente.");
        } finally {
            generate.disabled = false;
        }
    });

    copy?.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(link.value);
        } catch {
            link.select();
            document.execCommand?.('copy');
        }
        const original = copy.textContent;
        copy.textContent = 'Copiado!';
        setTimeout(() => (copy.textContent = original), 1500);
    });
}

// ─────────────────────────────────────────────────────────────────────────
// Atualização ao vivo
// ─────────────────────────────────────────────────────────────────────────

/** Marca no mapa principal os alertas que chegaram depois do carregamento da página. */
function addLiveAlertMarkers(page, alerts) {
    const data = readData(page);
    const timeZone = timezoneOf(page);

    alerts.forEach((alert) => {
        data.nearbyAlerts.push(alert);

        if (!mainMap || typeof L === 'undefined' || !isValidPoint(alert.lat, alert.lng)) return;

        L.circleMarker([alert.lat, alert.lng], {
            radius: 9, color: '#ffffff', weight: 2,
            fillColor: alertColor(alert.type), fillOpacity: 0.95,
        })
            .bindPopup(alertPopup(alert, timeZone))
            .addTo(mainMap);
    });
}

function initLive(page) {
    import('./route-live.js')
        .then((module) =>
            module.initRouteLive(page, {
                knownAlertIds: new Set(readData(page).nearbyAlerts.map((a) => a.id)),
                onNewAlerts: (alerts) => addLiveAlertMarkers(page, alerts),
            })
        )
        .catch((error) => console.warn('[WazeBR route-show] modo ao vivo indisponível', error));
}

// ─────────────────────────────────────────────────────────────────────────
// Bootstrap
// ─────────────────────────────────────────────────────────────────────────

/** Executa um passo de inicialização isolado: se um falhar, os demais continuam. */
function step(name, fn) {
    try {
        fn();
    } catch (error) {
        console.error(`[WazeBR route-show] falha em "${name}"`, error);
    }
}

export function initRouteShow(root = document) {
    const page = resolvePage(root);

    if (!page || bootstrapped) return;
    bootstrapped = true;

    // Controles de interface primeiro: nada que dependa de Leaflet/Chart.js pode impedi-los.
    step(rsT("menu de exportação"), () => initExportMenu(page));
    step(rsT("menu de compartilhar"), () => initShareMenu(page));
    step('favoritar', () => initFavorite(page));

    step('heatmap', () => renderHeatmap(page));
    step(rsT("gráficos"), () => initCharts(page));
    step(rsT("mapa principal"), () => initMainMap(page));
    step(rsT("mapa das últimas horas"), () => initRecentMap(page));
    step('mini-mapas', () => initSubRouteMaps(page));
    step(rsT("ao vivo"), () => initLive(page));
}

export default initRouteShow;
