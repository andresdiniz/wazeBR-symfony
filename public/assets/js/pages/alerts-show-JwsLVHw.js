/**
 * pages/alerts-show.js
 * Página de detalhes de um alerta:
 *   - Mapa Leaflet centrado na localização do alerta
 *   - Marcador colorido por tipo
 *   - Popup já aberto com dados resumidos
 */

const TYPE_COLOR = {
    ACCIDENT:      '#dc2626',
    JAM:           '#ea580c',
    ROAD_CLOSED:   '#111827',
    POLICE:        '#2563eb',
    WEATHERHAZARD: '#0891b2',
    HAZARD:        '#f59e0b',
    CONSTRUCTION:  '#7c2d12',
};

function typeColor(type) {
    return TYPE_COLOR[(type || '').toUpperCase()] || '#2563eb';
}

function safeJson(str, fallback = null) {
    try { return JSON.parse(str); } catch { return fallback; }
}

function escapeHtml(str) {
    return String(str ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function fmtSp(value) {
    if (!value) return '—';
    const d = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(d.getTime())) return '—';

    return new Intl.DateTimeFormat('pt-BR', {
        timeZone: 'America/Sao_Paulo',
        day: '2-digit', month: '2-digit',
        hour: '2-digit', minute: '2-digit',
    }).format(d);
}

function initShowMap(page) {
    const container = page.querySelector('[data-alert-show-map]');
    if (!container || typeof L === 'undefined') return;

    const alert = safeJson(page.dataset.alert);
    if (!alert) return;

    const lat = Number(alert.lat);
    const lng = Number(alert.lng);
    const hasCoords = Number.isFinite(lat) && Number.isFinite(lng);

    const map = L.map(container, { zoomControl: true, preferCanvas: true })
        .setView(hasCoords ? [lat, lng] : [-15.78, -47.92], hasCoords ? 16 : 5);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap',
    }).addTo(map);

    if (!hasCoords) {
        return;
    }

    const color = typeColor(alert.type);

    L.circleMarker([lat, lng], {
        radius: 10,
        color,
        weight: 2,
        fillColor: color,
        fillOpacity: 0.85,
    })
        .bindPopup(`
            <strong>${escapeHtml(alert.type || 'Alerta')}</strong><br>
            <small>${escapeHtml(alert.subtype || '')}</small><br>
            ${escapeHtml(alert.street || '')}${alert.street && alert.city ? ' — ' : ''}${escapeHtml(alert.city || '')}<br>
            <small>Coletado: ${escapeHtml(fmtSp(alert.when))}</small>
        `)
        .addTo(map)
        .openPopup();

    // Expor globalmente para debug
    window.alertShowMap = map;

    // Pequeno delay para o layout estabilizar antes do invalidateSize
    setTimeout(() => map.invalidateSize(), 100);
}

function init() {
    const page = document.querySelector('[data-alert-show]');
    if (!page) return;
    initShowMap(page);
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}
