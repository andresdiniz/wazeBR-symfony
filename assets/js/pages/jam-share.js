import { ensureLeaflet } from '../core/leaflet-loader.js';

const LEVEL_COLORS = { 1: '#22c55e', 2: '#84cc16', 3: '#f97316', 4: '#ef4444', 5: '#b91c1c' };

async function init() {
    const el = document.querySelector('[data-share-map]');
    if (!el) return;

    let jam = null;
    try { jam = JSON.parse(el.dataset.jam || 'null'); } catch (error) { return; }

    const path = (Array.isArray(jam?.path) ? jam.path : [])
        .filter((p) => Array.isArray(p) && Number.isFinite(Number(p[0])) && Number.isFinite(Number(p[1])))
        .map((p) => [Number(p[0]), Number(p[1])]);
    if (path.length < 2) return; // sem traçado: a página segue só com o cartão de informações

    el.hidden = false;
    if (!(await ensureLeaflet())) {
        el.innerHTML = '<div class="share-map__message">Não foi possível carregar o mapa.</div>';
        return;
    }

    const map = L.map(el, { zoomControl: true, scrollWheelZoom: false, preferCanvas: true });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19, attribution: '&copy; OpenStreetMap',
    }).addTo(map);

    const color = jam.isBlocked ? LEVEL_COLORS[5] : (LEVEL_COLORS[Number(jam.level)] || '#94a3b8');
    L.polyline(path, {
        color, weight: 7, opacity: 0.92, lineCap: 'round', lineJoin: 'round',
        dashArray: jam.isBlocked && jam.isStale ? '8, 6' : null,
    }).addTo(map);

    map.fitBounds(path, { padding: [30, 30], maxZoom: 16 });
    setTimeout(() => map.invalidateSize(), 120);
}

init().catch((error) => console.error('[jam-share]', error));
