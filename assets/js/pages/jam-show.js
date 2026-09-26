/**
 * pages/jam-show.js — Detalhes + impacto de um jam
 *
 * - Mapa com a polyline do jam + pinos dos alertas próximos
 * - Lista de alertas ordenados por distância
 * - Distribuição por tipo de alerta
 * - Auto-fit do mapa
 * #12 — fallback visual quando o CDN do Leaflet não carrega
 */

const COLORS = {
    l1: '#22c55e', l2: '#84cc16', l3: '#f97316',
    l4: '#ef4444', l5: '#b91c1c',
    accent: '#7c3aed',
};

const ALERT_COLOR = {
    ACCIDENT:      '#dc2626',
    JAM:           '#ea580c',
    ROAD_CLOSED:   '#111827',
    POLICE:        '#2563eb',
    WEATHERHAZARD: '#0891b2',
    HAZARD:        '#f59e0b',
    CONSTRUCTION:  '#7c2d12',
};

let bootstrapped = false;

function safeJson(str, fb) {
    try { return JSON.parse(str); } catch { return fb; }
}

function escapeHtml(str) {
    return String(str ?? '')
        .replaceAll('&', '&amp;').replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;').replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function alertColor(type) {
    return ALERT_COLOR[(type || '').toUpperCase()] || COLORS.accent;
}

function levelColor(level) {
    switch (Number(level)) {
        case 1: return COLORS.l1;
        case 2: return COLORS.l2;
        case 3: return COLORS.l3;
        case 4: return COLORS.l4;
        case 5: return COLORS.l5;
        default: return '#94a3b8';
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Mapa
// ─────────────────────────────────────────────────────────────────────────

function initMap(root, attempt = 0) {
    const container = root.querySelector('[data-jam-show-map]');
    if (!container) return;

    // #12 — Leaflet ainda não carregou; tenta por até 4s, depois exibe aviso
    if (typeof L === 'undefined') {
        if (attempt < 40) {
            setTimeout(() => initMap(root, attempt + 1), 100);
        } else {
            container.innerHTML = `
                <div style="display:flex;align-items:center;justify-content:center;
                            height:100%;flex-direction:column;gap:8px;
                            color:var(--js-text-muted,#64748b);font-size:13px">
                    <span style="font-size:24px">🗺️</span>
                    Não foi possível carregar o mapa.
                    <small>Verifique sua conexão e recarregue a página.</small>
                </div>`;
        }
        return;
    }

    const jam    = safeJson(root.dataset.jam, null);
    const alerts = safeJson(root.dataset.nearbyAlerts, []) || [];

    if (!jam || !Array.isArray(jam.path) || jam.path.length < 2) return;

    const map = L.map(container, {
        zoomControl: true,
        preferCanvas: true,
        center: [-20.66, -43.78],
        zoom: 13,
    });

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap',
    }).addTo(map);

    const color = (jam.isBlocked && jam.isStale) ? COLORS.l3
                : jam.isBlocked                    ? COLORS.l5
                : levelColor(jam.level);

    // Polyline do jam
    L.polyline(jam.path, {
        color, weight: 7, opacity: 0.92,
        dashArray: (jam.isBlocked && jam.isStale) ? '8, 6' : null,
        lineCap: 'round', lineJoin: 'round',
    }).addTo(map);

    // Marcador de início (verde) e fim (vermelho)
    L.circleMarker(jam.path[0], {
        radius: 6, color: '#fff', weight: 2,
        fillColor: '#16a34a', fillOpacity: 1,
    }).bindTooltip('Início', { direction: 'top' }).addTo(map);

    L.circleMarker(jam.path[jam.path.length - 1], {
        radius: 6, color: '#fff', weight: 2,
        fillColor: '#dc2626', fillOpacity: 1,
    }).bindTooltip('Fim', { direction: 'top' }).addTo(map);

    // Alertas próximos
    alerts.forEach((a) => {
        if (!Number.isFinite(a.lat) || !Number.isFinite(a.lng)) return;
        L.circleMarker([a.lat, a.lng], {
            radius: 7, color: '#fff', weight: 2,
            fillColor: alertColor(a.type), fillOpacity: 0.95,
        }).bindPopup(`
            <div style="font:inherit;font-size:12px;min-width:180px">
                <strong style="display:block;font-size:13px">
                    ${escapeHtml(a.typeLabel || a.type || 'Alerta')}
                </strong>
                ${a.subtype ? `<small style="display:block;color:#64748b">${escapeHtml(a.subtype)}</small>` : ''}
                ${a.street  ? `<div style="margin-top:4px">${escapeHtml(a.street)}</div>` : ''}
                ${a.city    ? `<div style="color:#64748b">${escapeHtml(a.city)}</div>` : ''}
                <div style="display:flex;justify-content:space-between;padding:2px 0;margin-top:6px">
                    <span style="color:#64748b">Distância</span>
                    <strong>${a.distanceMeters} m</strong>
                </div>
                <div style="display:flex;justify-content:space-between;padding:2px 0">
                    <span style="color:#64748b">Δ Tempo</span>
                    <strong>${a.timeOffsetMinutes > 0 ? '+' : ''}${a.timeOffsetMinutes} min</strong>
                </div>
            </div>
        `).addTo(map);
    });

    // Fit automático
    const all = [...jam.path, ...alerts.map((a) => [a.lat, a.lng])];
    try { map.fitBounds(all, { padding: [40, 40], maxZoom: 16 }); } catch {}

    setTimeout(() => map.invalidateSize(), 120);
    setTimeout(() => map.invalidateSize(), 400);
    window.addEventListener('resize', () => map.invalidateSize());
    window.jamShowMap = map;
}

// ─────────────────────────────────────────────────────────────────────────
// Distribuição por tipo de alerta
// ─────────────────────────────────────────────────────────────────────────

function renderTypeBreakdown(root, alerts) {
    const el = root.querySelector('[data-jam-show-types]');
    if (!el) return;

    if (!Array.isArray(alerts) || alerts.length === 0) {
        el.innerHTML = '<div class="jam-show-empty"><strong>Nenhum alerta próximo</strong></div>';
        return;
    }

    const counts = {};
    alerts.forEach((a) => {
        const k = a.type || 'OTHER';
        counts[k] = (counts[k] || 0) + 1;
    });

    const entries = Object.entries(counts).sort((a, b) => b[1] - a[1]);
    const max     = entries[0]?.[1] || 1;

    el.innerHTML = entries.map(([type, n]) => {
        const pct = Math.round((n / max) * 100);
        return `
            <div class="jam-show-type">
                <span class="jam-show-type__label">${escapeHtml(type)}</span>
                <div class="jam-show-type__bar">
                    <span class="jam-show-type__fill" style="width:${pct}%"></span>
                </div>
                <span class="jam-show-type__count">${n}</span>
            </div>`;
    }).join('');
}

// ─────────────────────────────────────────────────────────────────────────
// Bootstrap
// ─────────────────────────────────────────────────────────────────────────

export function initJamShow(root = document) {
    const page = root.querySelector?.('[data-jam-show]') ?? root;
    if (!page || !page.matches?.('[data-jam-show]') || bootstrapped) return;
    bootstrapped = true;

    const alerts = safeJson(page.dataset.nearbyAlerts, []) || [];

    initMap(page);
    renderTypeBreakdown(page, alerts);
}

export default initJamShow;
