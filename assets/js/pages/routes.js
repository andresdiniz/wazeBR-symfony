/**
 * pages/routes.js — Página /routes
 */

const LEVEL_COLORS = {
    none:     '#16a34a',
    light:    '#3b82f6',
    moderate: '#f59e0b',
    heavy:    '#ea580c',
    severe:   '#dc2626',
};

const STYLE = {
    default: { weight: 5, opacity: 0.85 },
    dimmed:  { weight: 3, opacity: 0.10 },
    active:  { weight: 8, opacity: 1.00 },
};

function escapeHtml(str) {
    return String(str ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function readPayload(root) {
    const node = root.querySelector('[data-routes-payload]');
    if (!node) return null;
    try {
        return JSON.parse(node.textContent.trim() || '{}');
    } catch (err) {
        console.error('[WazeBR routes] payload inválido', err);
        return null;
    }
}

function levelColor(level) {
    return LEVEL_COLORS[level] ?? LEVEL_COLORS.none;
}

let map = null;
let segmentsLayer = null;
let irregularitiesLayer = null;
const polylinesByRoute = new Map();
let activeRouteId = null;

// ─────────────────────────────────────────────────────────────────────────
// Mapa
// ─────────────────────────────────────────────────────────────────────────

function initMap(root, payload) {
    const container = root.querySelector('[data-routes-map]');
    if (!container || typeof L === 'undefined') return;
    if (map) return;

    const center = (() => {
        try { return JSON.parse(container.dataset.routesMapCenter || '{}'); }
        catch { return {}; }
    })();

    map = L.map(container, {
        zoomControl: true,
        preferCanvas: true,
        center: [center.lat ?? -20.66, center.lng ?? -43.78],
        zoom: center.zoom ?? 12,
    });

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap',
    }).addTo(map);

    segmentsLayer       = L.layerGroup().addTo(map);
    irregularitiesLayer = L.layerGroup().addTo(map);

    drawSegments(payload.segments || []);
    drawIrregularities(payload.irregularities || []);

    const allPaths = Array.isArray(payload.segments)
        ? payload.segments.map((s) => s.path).filter((p) => Array.isArray(p) && p.length > 1)
        : [];
    if (allPaths.length > 0) {
        try {
            map.fitBounds(allPaths.flat(), { padding: [24, 24], maxZoom: 15 });
        } catch { /* ignora */ }
    }

    map.on('click', () => clearHighlight(root));
    window.addEventListener('resize', () => map && map.invalidateSize());
}

function drawSegments(segments) {
    if (!segmentsLayer) return;

    polylinesByRoute.clear();
    activeRouteId = null;

    segments.forEach((seg) => {
        const path = Array.isArray(seg.path) ? seg.path : [];
        if (path.length < 2) return;

        const color = levelColor(seg.delayLevel);
        const line = L.polyline(path, {
            color,
            weight: STYLE.default.weight,
            opacity: STYLE.default.opacity,
            lineCap: 'round',
            lineJoin: 'round',
        });

        line.bindPopup(buildSegmentPopup(seg), {
            maxWidth: 300,
            minWidth: 240,
            autoPan: true,
            autoPanPaddingTopLeft:     [20, 80],
            autoPanPaddingBottomRight: [20, 40],
            closeButton: true,
            offset: L.point(0, -8),
        });

        line.addTo(segmentsLayer);

        const bucket = polylinesByRoute.get(seg.routeId) || [];
        bucket.push({ line, level: seg.delayLevel });
        polylinesByRoute.set(seg.routeId, bucket);
    });
}

function drawIrregularities(items) {
    if (!irregularitiesLayer) return;

    items.forEach((irr) => {
        if (!Number.isFinite(irr.lat) || !Number.isFinite(irr.lng)) return;

        const marker = L.circleMarker([irr.lat, irr.lng], {
            radius: 7,
            color: '#7c3aed',
            weight: 2,
            fillColor: '#a78bfa',
            fillOpacity: 0.9,
        });

        marker.bindPopup(`
            <div class="routes-popup">
                <strong>${escapeHtml(irr.type || 'Irregularidade')}</strong>
                ${irr.subtype ? `<small>${escapeHtml(irr.subtype)}</small>` : ''}
                ${irr.street ? `<div class="routes-popup-row"><span>Via</span><span>${escapeHtml(irr.street)}</span></div>` : ''}
                ${irr.city ? `<div class="routes-popup-row"><span>Cidade</span><span>${escapeHtml(irr.city)}</span></div>` : ''}
                ${irr.severity ? `<div class="routes-popup-row"><span>Severidade</span><span>${escapeHtml(irr.severity)}</span></div>` : ''}
            </div>
        `);

        marker.addTo(irregularitiesLayer);
    });
}

/**
 * Popup com duplo contexto:
 *  - "Este trecho"  → dados da sub-rota clicada
 *  - "Rota completa" → dados do snapshot da rota inteira
 *
 * Se for o mesmo (segmento == rota), mostra só uma vez.
 */
function buildSegmentPopup(seg) {
    const isSubRoute = seg.source === 'subroute';

    const fmtDelay = (s) => (s != null && s > 0) ? `+${s}s` : 'no prazo';
    const fmtRatio = (r) => (r != null ? `${Math.round(r * 100)}%` : '—');
    const fmtLevel = (lbl, lvl) => escapeHtml(lbl || lvl || '—');

    const segDelay   = fmtDelay(seg.delaySeconds);
    const segRatio   = fmtRatio(seg.delayRatio);
    const segTime    = seg.time          != null ? `${Math.round(seg.time)}s`          : '—';
    const segHist    = seg.historicTime  != null ? `${Math.round(seg.historicTime)}s`  : '—';
    const segLevel   = fmtLevel(seg.delayLabel, seg.delayLevel);
    const segJam     = seg.jamLevel != null ? `Nível ${seg.jamLevel}` : '—';
    const segTrecho  = (seg.from && seg.to)
        ? `${escapeHtml(seg.from)} → ${escapeHtml(seg.to)}`
        : '';

    const routeDelay = fmtDelay(seg.routeDelaySeconds);
    const routeRatio = fmtRatio(seg.routeDelayRatio);
    const routeTime  = seg.routeTime         != null ? `${Math.round(seg.routeTime)}s`         : '—';
    const routeHist  = seg.routeHistoricTime != null ? `${Math.round(seg.routeHistoricTime)}s` : '—';
    const routeLevel = fmtLevel(seg.routeDelayLabel, seg.routeDelayLevel);
    const routeTrecho = (seg.routeFrom && seg.routeTo)
        ? `${escapeHtml(seg.routeFrom)} → ${escapeHtml(seg.routeTo)}`
        : '';

    // Se for rota inteira, não duplica
    if (!isSubRoute) {
        return `
            <div class="routes-popup">
                <strong>${escapeHtml(seg.routeName || 'Rota')}</strong>
                ${routeTrecho ? `<small>${routeTrecho}</small>` : ''}
                <div class="routes-popup-row"><span>Atraso</span><span>${routeDelay}</span></div>
                <div class="routes-popup-row"><span>Vs. histórico</span><span>${routeRatio}</span></div>
                <div class="routes-popup-row"><span>Tempo atual</span><span>${routeTime}</span></div>
                <div class="routes-popup-row"><span>Histórico</span><span>${routeHist}</span></div>
                <div class="routes-popup-row"><span>Nível</span><span class="routes-popup-level routes-popup-level--${escapeHtml(seg.routeDelayLevel || 'none')}">${routeLevel}</span></div>
            </div>
        `;
    }

    // Rota com sub-rotas: mostra trecho + rota completa
    return `
        <div class="routes-popup">
            <strong>${escapeHtml(seg.routeName || 'Rota')}</strong>
            ${routeTrecho ? `<small>${routeTrecho}</small>` : ''}

            <div class="routes-popup-section">
                <span class="routes-popup-section-label">Este trecho</span>
                ${segTrecho ? `<small class="routes-popup-trecho">${segTrecho}</small>` : ''}
                <div class="routes-popup-row"><span>Atraso</span><span>${segDelay}</span></div>
                <div class="routes-popup-row"><span>Vs. histórico</span><span>${segRatio}</span></div>
                <div class="routes-popup-row"><span>Tempo atual</span><span>${segTime}</span></div>
                <div class="routes-popup-row"><span>Histórico</span><span>${segHist}</span></div>
                ${seg.jamLevel != null ? `<div class="routes-popup-row"><span>Jam</span><span>${segJam}</span></div>` : ''}
                <div class="routes-popup-row"><span>Nível</span><span class="routes-popup-level routes-popup-level--${escapeHtml(seg.delayLevel || 'none')}">${segLevel}</span></div>
            </div>

            <div class="routes-popup-section routes-popup-section--route">
                <span class="routes-popup-section-label">Rota completa</span>
                <div class="routes-popup-row"><span>Atraso</span><span>${routeDelay}</span></div>
                <div class="routes-popup-row"><span>Vs. histórico</span><span>${routeRatio}</span></div>
                <div class="routes-popup-row"><span>Tempo atual</span><span>${routeTime}</span></div>
                <div class="routes-popup-row"><span>Histórico</span><span>${routeHist}</span></div>
                <div class="routes-popup-row"><span>Nível</span><span class="routes-popup-level routes-popup-level--${escapeHtml(seg.routeDelayLevel || 'none')}">${routeLevel}</span></div>
            </div>
        </div>
    `;
}

// ─────────────────────────────────────────────────────────────────────────
// Highlight / Dim
// ─────────────────────────────────────────────────────────────────────────

function applyHighlightStyles() {
    polylinesByRoute.forEach((items, routeId) => {
        const isActive = activeRouteId !== null && activeRouteId === routeId;
        const dimmed   = activeRouteId !== null && !isActive;

        items.forEach(({ line, level }) => {
            const color = levelColor(level);
            if (isActive) {
                line.setStyle({ color, weight: STYLE.active.weight, opacity: STYLE.active.opacity });
                line.bringToFront();
            } else if (dimmed) {
                line.setStyle({ color, weight: STYLE.dimmed.weight, opacity: STYLE.dimmed.opacity });
            } else {
                line.setStyle({ color, weight: STYLE.default.weight, opacity: STYLE.default.opacity });
            }
        });
    });
}

function setActiveRoute(routeId, root) {
    activeRouteId = routeId;

    if (root) {
        const list = root.querySelector('[data-routes-list]');
        if (list) {
            list.querySelectorAll('.routes-list-item.is-active')
                .forEach((el) => el.classList.remove('is-active'));

            if (routeId !== null) {
                const el = list.querySelector(`[data-route-id="${routeId}"]`);
                if (el) {
                    el.classList.add('is-active');
                    el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                }
            }
        }
    }

    applyHighlightStyles();
}

function clearHighlight(root) {
    setActiveRoute(null, root);
}

// ─────────────────────────────────────────────────────────────────────────
// Filtros
// ─────────────────────────────────────────────────────────────────────────

function initFilters(root) {
    const form = root.querySelector('[data-routes-filter-form]');
    if (!form || form.dataset.routesFilterInit === '1') return;
    form.dataset.routesFilterInit = '1';

    const search  = form.querySelector('[data-routes-search]');
    const level   = form.querySelector('[data-routes-level]');
    const clear   = root.querySelector('[data-routes-clear]');
    const list    = root.querySelector('[data-routes-list]');
    const counter = root.querySelector('[data-routes-count]');

    const items = () => Array.from(list?.querySelectorAll('.routes-list-item') || []);

    const applyFilter = () => {
        const q  = (search?.value || '').trim().toLowerCase();
        const lv = level?.value || 'all';

        let visible = 0;

        items().forEach((el) => {
            const matchesQ  = q === '' || (el.dataset.routeSearch || '').includes(q);
            const matchesLv = lv === 'all' || el.dataset.routeLevel === lv;
            const show = matchesQ && matchesLv;

            el.classList.toggle('is-hidden', !show);
            if (show) visible++;
        });

        if (counter) {
            counter.textContent = `${visible} rota${visible !== 1 ? 's' : ''}`;
        }

        polylinesByRoute.forEach((bucket, routeId) => {
            const el = list?.querySelector(`[data-route-id="${routeId}"]`);
            const show = el && !el.classList.contains('is-hidden');

            bucket.forEach(({ line }) => {
                if (!map) return;
                if (show) {
                    if (!map.hasLayer(line)) segmentsLayer.addLayer(line);
                } else {
                    if (map.hasLayer(line)) segmentsLayer.removeLayer(line);
                }
            });
        });

        if (activeRouteId !== null) {
            const el = list?.querySelector(`[data-route-id="${activeRouteId}"]`);
            if (!el || el.classList.contains('is-hidden')) {
                clearHighlight(root);
            }
        }
    };

    let debounce;
    search?.addEventListener('input', () => {
        clearTimeout(debounce);
        debounce = setTimeout(applyFilter, 120);
    });

    level?.addEventListener('change', applyFilter);

    clear?.addEventListener('click', (e) => {
        e.preventDefault();
        if (search) search.value = '';
        if (level)  level.value  = 'all';
        applyFilter();
    });

    form.addEventListener('submit', (e) => e.preventDefault());
}

// ─────────────────────────────────────────────────────────────────────────
// Click na lista → foca + destaca
// ─────────────────────────────────────────────────────────────────────────

function initListInteraction(root) {
    const list = root.querySelector('[data-routes-list]');
    if (!list || list.dataset.routesClickInit === '1') return;
    list.dataset.routesClickInit = '1';

    list.addEventListener('click', (e) => {
        if (e.target.closest('[data-route-detail]')) return; // deixa o link navegar
        const item = e.target.closest('[data-route-id]');
        if (!item) return;

        const routeId = Number(item.dataset.routeId);
        if (!Number.isFinite(routeId)) return;

        if (activeRouteId === routeId) {
            clearHighlight(root);
            return;
        }

        setActiveRoute(routeId, root);

        const bucket = polylinesByRoute.get(routeId) || [];
        if (bucket.length === 0 || !map) return;

        const bounds = L.latLngBounds([]);
        bucket.forEach(({ line }) => bounds.extend(line.getBounds()));

        if (bounds.isValid()) {
            map.fitBounds(bounds, { padding: [40, 40], maxZoom: 16 });
            const first = bucket.find(({ line }) => map.hasLayer(line))?.line;
            if (first) first.openPopup();
        }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && activeRouteId !== null) {
            clearHighlight(root);
        }
    });
}

// ─────────────────────────────────────────────────────────────────────────
// Relógio + refresh
// ─────────────────────────────────────────────────────────────────────────

function initClock(root) {
    const clock = root.querySelector('[data-routes-clock]');
    if (!clock || clock.dataset.routesClockInit === '1') return;
    clock.dataset.routesClockInit = '1';

    const fmt = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short' });
    const tick = () => { clock.textContent = fmt.format(new Date()); };
    tick();
    setInterval(tick, 30_000);
}

function initRefresh(root) {
    const btn = root.querySelector('[data-action="refresh-routes"]');
    if (!btn || btn.dataset.routesRefreshInit === '1') return;
    btn.dataset.routesRefreshInit = '1';

    btn.addEventListener('click', () => {
        btn.classList.add('is-loading');
        btn.setAttribute('aria-busy', 'true');
        location.reload();
    });
}

// ─────────────────────────────────────────────────────────────────────────
// Bootstrap
// ─────────────────────────────────────────────────────────────────────────

export function initRoutesPage(root = document) {
    const page = root.querySelector('[data-routes-page]');
    if (!page || page.dataset.routesInit === '1') return;
    page.dataset.routesInit = '1';

    const payload = readPayload(page);
    if (!payload) return;

    initMap(page, payload);
    initFilters(page);
    initListInteraction(page);
    initClock(page);
    initRefresh(page);
}

export default initRoutesPage;
