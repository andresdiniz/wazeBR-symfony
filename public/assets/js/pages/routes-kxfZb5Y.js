/**
 * pages/routes.js — Página /routes
 *
 * - Lê o payload JSON inline (`<script data-routes-payload>`)
 * - Renderiza polylines no Leaflet, coloridas por nível de atraso
 * - Filtros client-side (search + level)
 * - Click na lista → foca rota no mapa
 * - Markers de irregularidades
 */

const LEVEL_COLORS = {
    none:     '#16a34a',
    light:    '#3b82f6',
    moderate: '#f59e0b',
    heavy:    '#ea580c',
    severe:   '#dc2626',
};

const LEVEL_ORDER = ['none', 'light', 'moderate', 'heavy', 'severe'];

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

// ─────────────────────────────────────────────────────────────────────────
// Mapa
// ─────────────────────────────────────────────────────────────────────────

let map = null;
let segmentsLayer = null;
let irregularitiesLayer = null;
const polylinesByRoute = new Map(); // routeId -> Leaflet polyline[]

function initMap(root, payload) {
    const container = root.querySelector('[data-routes-map]');
    if (!container || typeof L === 'undefined') return;

    if (map) return; // idempotente

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

    // Ajusta o zoom pra mostrar todas as rotas (se houver alguma)
    const allPaths = Array.isArray(payload.segments)
        ? payload.segments.map((s) => s.path).filter((p) => Array.isArray(p) && p.length > 1)
        : [];
    if (allPaths.length > 0) {
        const allPoints = allPaths.flat();
        try {
            map.fitBounds(allPoints, { padding: [24, 24], maxZoom: 15 });
        } catch { /* geometria inválida, ignora */ }
    }

    window.addEventListener('resize', () => map && map.invalidateSize());
}

function drawSegments(segments) {
    if (!segmentsLayer) return;

    polylinesByRoute.clear();

    segments.forEach((seg) => {
        const path = Array.isArray(seg.path) ? seg.path : [];
        if (path.length < 2) return;

        const color = levelColor(seg.delayLevel);
        const line = L.polyline(path, {
            color,
            weight: 5,
            opacity: 0.85,
            lineCap: 'round',
            lineJoin: 'round',
        });

        line.bindPopup(buildSegmentPopup(seg));
        line.addTo(segmentsLayer);

        const list = polylinesByRoute.get(seg.routeId) || [];
        list.push(line);
        polylinesByRoute.set(seg.routeId, list);
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

function buildSegmentPopup(seg) {
    const delay = seg.delaySeconds != null && seg.delaySeconds > 0
        ? `+${seg.delaySeconds}s`
        : 'No prazo';

    const ratio = seg.delayRatio != null
        ? `${Math.round(seg.delayRatio * 100)}%`
        : '—';

    const trecho = seg.from && seg.to
        ? `${escapeHtml(seg.from)} → ${escapeHtml(seg.to)}`
        : (seg.routeName ? escapeHtml(seg.routeName) : '');

    return `
        <div class="routes-popup">
            <strong>${escapeHtml(seg.routeName || 'Rota')}</strong>
            ${trecho ? `<small>${trecho}</small>` : ''}
            <div class="routes-popup-row"><span>Atraso</span><span>${delay}</span></div>
            <div class="routes-popup-row"><span>Vs. histórico</span><span>${ratio}</span></div>
            ${seg.jamLevel != null ? `<div class="routes-popup-row"><span>Jam</span><span>Nível ${seg.jamLevel}</span></div>` : ''}
            ${seg.time != null && seg.historicTime != null
                ? `<div class="routes-popup-row"><span>Tempo atual</span><span>${seg.time}s</span></div>
                   <div class="routes-popup-row"><span>Histórico</span><span>${seg.historicTime}s</span></div>`
                : ''}
            <div class="routes-popup-row"><span>Nível</span><span>${escapeHtml(seg.delayLabel || seg.delayLevel || '—')}</span></div>
        </div>
    `;
}

// ─────────────────────────────────────────────────────────────────────────
// Filtros client-side
// ─────────────────────────────────────────────────────────────────────────

function initFilters(root) {
    const form = root.querySelector('[data-routes-filter-form]');
    if (!form || form.dataset.routesFilterInit === '1') return;
    form.dataset.routesFilterInit = '1';

    const search  = form.querySelector('[data-routes-search]');
    const level   = form.querySelector('[data-routes-level]');
    const clear   = root.querySelector('[data-routes-clear]');
    const items   = () => Array.from(root.querySelectorAll('[data-routes-list] .routes-list-item'));
    const counter = root.querySelector('[data-routes-count]');

    const applyFilter = () => {
        const q = (search?.value || '').trim().toLowerCase();
        const lv = level?.value || 'all';

        let visible = 0;

        items().forEach((el) => {
            const matchesQ = q === '' || (el.dataset.routeSearch || '').includes(q);
            const matchesLv = lv === 'all' || el.dataset.routeLevel === lv;
            const show = matchesQ && matchesLv;

            el.classList.toggle('is-hidden', !show);
            if (show) visible++;
        });

        if (counter) {
            counter.textContent = `${visible} rota${visible !== 1 ? 's' : ''}`;
        }

        // Mostra/esconde polylines no mapa
        polylinesByRoute.forEach((lines, routeId) => {
            const el = root.querySelector(`[data-route-id="${routeId}"]`);
            const show = el && !el.classList.contains('is-hidden');
            lines.forEach((line) => {
                if (show) {
                    if (map && !map.hasLayer(line)) segmentsLayer.addLayer(line);
                } else {
                    if (map && map.hasLayer(line)) segmentsLayer.removeLayer(line);
                }
            });
        });
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
// Click na lista → foca rota no mapa
// ─────────────────────────────────────────────────────────────────────────

function initListInteraction(root) {
    const list = root.querySelector('[data-routes-list]');
    if (!list || list.dataset.routesClickInit === '1') return;
    list.dataset.routesClickInit = '1';

    list.addEventListener('click', (e) => {
        const item = e.target.closest('[data-route-id]');
        if (!item) return;

        const routeId = Number(item.dataset.routeId);
        if (!Number.isFinite(routeId)) return;

        // Marca visualmente
        list.querySelectorAll('.routes-list-item.is-active')
            .forEach((el) => el.classList.remove('is-active'));
        item.classList.add('is-active');

        // Foca no mapa
        const lines = polylinesByRoute.get(routeId) || [];
        if (lines.length === 0 || !map) return;

        const bounds = L.latLngBounds([]);
        lines.forEach((line) => bounds.extend(line.getBounds()));

        if (bounds.isValid()) {
            map.fitBounds(bounds, { padding: [40, 40], maxZoom: 16 });
            lines[0].openPopup();
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
