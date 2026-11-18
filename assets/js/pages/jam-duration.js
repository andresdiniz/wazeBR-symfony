import { ensureLeaflet } from '../core/leaflet-loader.js';

const LEVEL_COLORS = { 1: '#22c55e', 2: '#84cc16', 3: '#f97316', 4: '#ef4444', 5: '#b91c1c' };

function message(el, icon, title, hint = '') {
    el.textContent = '';
    const box = document.createElement('div');
    box.className = 'jf-map-message';
    box.setAttribute('role', 'status');
    const i = document.createElement('span');
    i.textContent = icon;
    i.setAttribute('aria-hidden', 'true');
    const t = document.createElement('strong');
    t.textContent = title;
    box.append(i, t);
    if (hint) {
        const h = document.createElement('small');
        h.textContent = hint;
        box.append(h);
    }
    el.append(box);
}

async function init() {
    const el = document.querySelector('[data-jam-duration-map]');
    if (!el || el.dataset.ready === '1') return;
    el.dataset.ready = '1';

    let items = [];
    try { items = JSON.parse(el.dataset.items || '[]'); } catch (error) { console.warn('[Jam duration]', error); }
    items = items.filter((item) => Number.isFinite(item.lat) && Number.isFinite(item.lng));

    if (items.length === 0) {
        message(el, '📍', 'Nenhum acompanhamento com coordenadas para exibir no mapa.');
        return;
    }

    message(el, '⏳', 'Carregando mapa…');
    if (!(await ensureLeaflet())) {
        message(el, '🗺️', 'Não foi possível carregar o mapa.', 'Verifique sua conexão e recarregue a página.');
        return;
    }

    el.textContent = '';
    const map = L.map(el).setView([items[0].lat, items[0].lng], 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors', maxZoom: 19,
    }).addTo(map);

    const template = el.dataset.showUrlTemplate || '';
    const points = [];

    items.forEach((item) => {
        const point = [item.lat, item.lng];
        points.push(point);

        const popup = document.createElement('div');
        const title = document.createElement('strong');
        title.textContent = item.street || 'Via não informada';
        const text = document.createElement('p');
        text.textContent = `${item.status} · ${Number(item.speed).toFixed(1)} km/h · ${item.trend}`;
        popup.append(title, text);

        if (template && item.jam_id != null) {
            const link = document.createElement('a');
            link.href = template.replace(/\/0(?=$|[/?#])/, `/${encodeURIComponent(item.jam_id)}`);
            link.textContent = 'Detalhes do jam';
            popup.append(link);
        }

        const color = LEVEL_COLORS[Number(item.level)] || '#f59e0b';
        L.circleMarker(point, { radius: 8, color: '#fff', weight: 2, fillColor: color, fillOpacity: 0.95 })
            .addTo(map)
            .bindPopup(popup);
    });

    if (points.length === 1) {
        map.setView(points[0], 15);
    } else {
        map.fitBounds(points, { padding: [25, 25], maxZoom: 14 });
    }

    setTimeout(() => map.invalidateSize(), 120);
    if (typeof ResizeObserver === 'function') new ResizeObserver(() => map.invalidateSize()).observe(el);
}

init().catch((error) => console.error('[Jam duration]', error));

export default init;
