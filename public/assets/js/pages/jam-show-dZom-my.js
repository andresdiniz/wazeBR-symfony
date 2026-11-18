import { ensureLeaflet } from '../core/leaflet-loader.js';

const JAM_LOCALE = document.documentElement.lang || 'pt-BR';
const JAM_MESSAGES = (() => {
    try { return JSON.parse(document.querySelector('[data-jams-i18n]')?.dataset.jamsI18n || '{}'); }
    catch { return {}; }
})();
function jt(message, parameters = {}) {
    let value = Object.hasOwn(JAM_MESSAGES, message) ? JAM_MESSAGES[message] : message;
    for (const [key, replacement] of Object.entries(parameters)) value = value.replaceAll(key, String(replacement));
    return value;
}
function jamLabel(value, fallback = '—') {
    if (value == null || value === '') return fallback;
    const text = String(value);
    if (Object.hasOwn(JAM_MESSAGES, text)) return JAM_MESSAGES[text];
    const key = `code.${text}`;
    return Object.hasOwn(JAM_MESSAGES, key) ? JAM_MESSAGES[key] : text;
}

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

function showMapMessage(container, icon, title, hint = '') {
    container.innerHTML = `
        <div class="jam-show-map__message" role="status">
            <span aria-hidden="true">${icon}</span>
            <strong>${escapeHtml(title)}</strong>
            ${hint ? `<small>${escapeHtml(hint)}</small>` : ''}
        </div>`;
}

function validPoint(p) {
    return Array.isArray(p) && Number.isFinite(Number(p[0])) && Number.isFinite(Number(p[1]))
        && Math.abs(Number(p[0])) <= 90 && Math.abs(Number(p[1])) <= 180;
}

// ─────────────────────────────────────────────────────────────────────────
// Mapa
// ─────────────────────────────────────────────────────────────────────────

async function initMap(root) {
    const container = root.querySelector('[data-jam-show-map]');
    if (!container) return;

    const jam    = safeJson(root.dataset.jam, null);
    const alerts = (safeJson(root.dataset.nearbyAlerts, []) || [])
        .filter((a) => Number.isFinite(a?.lat) && Number.isFinite(a?.lng));
    const path   = Array.isArray(jam?.path) ? jam.path.filter(validPoint).map((p) => [Number(p[0]), Number(p[1])]) : [];
    const points = [...path, ...alerts.map((a) => [a.lat, a.lng])];

    if (!jam || points.length === 0) {
        showMapMessage(
            container, '📍',
            jt('Sem coordenadas disponíveis para este congestionamento.'),
            jt('O feed não enviou o traçado desta via.'),
        );
        return;
    }

    container.innerHTML = `<div class="jam-show-map__message" role="status"><span aria-hidden="true">⏳</span><strong>${escapeHtml(jt('Carregando mapa…'))}</strong></div>`;

    if (!(await ensureLeaflet())) {
        showMapMessage(
            container, '🗺️',
            jt('Não foi possível carregar o mapa.'),
            jt('Verifique sua conexão e recarregue a página.'),
        );
        return;
    }

    container.innerHTML = '';
    window.jamShowMap?.remove?.();

    const map = L.map(container, { zoomControl: true, preferCanvas: true, center: points[0], zoom: 14 });
    window.jamShowMap = map;

    let tileErrors = 0;
    const tiles = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap',
    }).addTo(map);
    tiles.on('tileerror', () => {
        // Mantém o traçado visível mesmo sem o mapa base; avisa uma única vez.
        if (++tileErrors === 5) {
            const note = document.createElement('div');
            note.className = 'jam-show-map__note';
            note.textContent = jt('Mapa base indisponível no momento; o traçado continua visível.');
            container.appendChild(note);
        }
    });

    const color = (jam.isBlocked && jam.isStale) ? COLORS.l3
                : jam.isBlocked                    ? COLORS.l5
                : levelColor(jam.level);

    if (path.length >= 2) {
        L.polyline(path, {
            color, weight: 7, opacity: 0.92,
            dashArray: (jam.isBlocked && jam.isStale) ? '8, 6' : null,
            lineCap: 'round', lineJoin: 'round',
        }).addTo(map);

        L.circleMarker(path[0], {
            radius: 6, color: '#fff', weight: 2, fillColor: '#16a34a', fillOpacity: 1,
        }).bindTooltip(jt('Início'), { direction: 'top' }).addTo(map);

        L.circleMarker(path[path.length - 1], {
            radius: 6, color: '#fff', weight: 2, fillColor: '#dc2626', fillOpacity: 1,
        }).bindTooltip(jt('Fim'), { direction: 'top' }).addTo(map);
    }

    alerts.forEach((a) => {
        L.circleMarker([a.lat, a.lng], {
            radius: 7, color: '#fff', weight: 2,
            fillColor: alertColor(a.type), fillOpacity: 0.95,
        }).bindPopup(`
            <div style="font:inherit;font-size:12px;min-width:180px">
                <strong style="display:block;font-size:13px">
                    ${escapeHtml(jamLabel(a.type || a.typeLabel, jt('Alerta')))}
                </strong>
                ${a.subtype ? `<small style="display:block;color:#64748b">${escapeHtml(jamLabel(a.subtype))}</small>` : ''}
                ${a.street  ? `<div style="margin-top:4px">${escapeHtml(a.street)}</div>` : ''}
                ${a.city    ? `<div style="color:#64748b">${escapeHtml(a.city)}</div>` : ''}
                <div style="display:flex;justify-content:space-between;padding:2px 0;margin-top:6px">
                    <span style="color:#64748b">${escapeHtml(jt('Distância'))}</span>
                    <strong>${escapeHtml(a.distanceMeters)} m</strong>
                </div>
                <div style="display:flex;justify-content:space-between;padding:2px 0">
                    <span style="color:#64748b">${escapeHtml(jt('Δ Tempo'))}</span>
                    <strong>${a.timeOffsetMinutes > 0 ? '+' : ''}${escapeHtml(a.timeOffsetMinutes)} min</strong>
                </div>
            </div>
        `).addTo(map);
    });

    addLegend(map, color, path.length >= 2, alerts);

    // Enquadra traçado + alertas; ponto único vira zoom fixo.
    if (points.length === 1) {
        map.setView(points[0], 16);
    } else {
        try { map.fitBounds(points, { padding: [40, 40], maxZoom: 16 }); } catch { map.setView(points[0], 14); }
    }

    // O contêiner pode mudar de tamanho depois do carregamento (fontes, layout, sidebar).
    setTimeout(() => map.invalidateSize(), 120);
    if (typeof ResizeObserver === 'function') {
        new ResizeObserver(() => map.invalidateSize()).observe(container);
    } else {
        window.addEventListener('resize', () => map.invalidateSize());
    }
}

function addLegend(map, jamColor, hasPath, alerts) {
    const types = [...new Set(alerts.map((a) => (a.type || '').toUpperCase()).filter(Boolean))];
    const legend = L.control({ position: 'bottomleft' });

    legend.onAdd = () => {
        const el = L.DomUtil.create('div', 'jam-show-legend');
        const rows = [];
        if (hasPath) {
            rows.push(`<span><i style="background:${jamColor};border-radius:2px;height:4px;width:16px"></i>${escapeHtml(jt('Congestionamento'))}</span>`);
            rows.push(`<span><i style="background:#16a34a"></i>${escapeHtml(jt('Início'))}</span>`);
            rows.push(`<span><i style="background:#dc2626"></i>${escapeHtml(jt('Fim'))}</span>`);
        }
        types.forEach((t) => rows.push(`<span><i style="background:${alertColor(t)}"></i>${escapeHtml(jamLabel(t))}</span>`));
        el.innerHTML = rows.join('');
        return el;
    };

    legend.addTo(map);
}

// ─────────────────────────────────────────────────────────────────────────
// Compartilhar (antes era um <script> inline, bloqueado pelo CSP)
// ─────────────────────────────────────────────────────────────────────────

function initShare(page) {
    const button = page.querySelector('#jam-share-button');
    if (!button) return;

    const select   = page.querySelector('#jam-share-duration');
    const result   = page.querySelector('#jam-share-result');
    const urlInput = page.querySelector('#jam-share-url');
    const copy     = page.querySelector('#jam-share-copy');
    const feedback = page.querySelector('#jam-share-feedback');

    const say = (message, isError = false) => {
        if (!feedback) return;
        feedback.textContent = message;
        feedback.classList.toggle('is-error', isError);
    };

    button.addEventListener('click', async () => {
        button.disabled = true;
        say('');

        try {
            const response = await fetch(button.dataset.shareUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ durationHours: Number(select?.value || 24) }),
            });
            const json = await response.json().catch(() => ({}));

            if (!response.ok || !json.ok || !json.url) {
                say(json.error || jt('Não foi possível gerar o link. Tente novamente.'), true);
                return;
            }

            urlInput.value = json.url;
            result.hidden = false;
            urlInput.focus();
            urlInput.select();
            say(jt('Link gerado. Quem tiver o link poderá ver este congestionamento até expirar.'));
        } catch (error) {
            say(jt('Falha de conexão ao gerar o link.'), true);
        } finally {
            button.disabled = false;
        }
    });

    copy?.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(urlInput.value);
            say(jt('Link copiado.'));
        } catch (error) {
            urlInput.select();
            say(document.execCommand?.('copy') ? jt('Link copiado.') : jt('Selecione e copie o link manualmente.'));
        }
    });
}

// Link "Abrir no Waze" calculado a partir do ponto médio do traçado.
function initWazeLink(page) {
    const link = page.querySelector('[data-jam-waze-link]');
    const jam  = safeJson(page.dataset.jam, null);
    if (!link || !Array.isArray(jam?.path)) return;

    const path = jam.path.filter(validPoint);
    if (path.length === 0) return;

    const mid = path[Math.floor(path.length / 2)];
    link.href = `https://www.waze.com/ul?ll=${Number(mid[0]).toFixed(6)}%2C${Number(mid[1]).toFixed(6)}&zoom=16`;
    link.hidden = false;
}

// ─────────────────────────────────────────────────────────────────────────
// Distribuição por tipo de alerta
// ─────────────────────────────────────────────────────────────────────────

function renderTypeBreakdown(root, alerts) {
    const el = root.querySelector('[data-jam-show-types]');
    if (!el) return;

    if (!Array.isArray(alerts) || alerts.length === 0) {
        el.innerHTML = ('<div class="jam-show-empty"><strong>' + escapeHtml(jt("Nenhum alerta próximo")) + '</strong></div>');
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
                <span class="jam-show-type__label">${escapeHtml(jamLabel(type))}</span>
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

    renderTypeBreakdown(page, alerts);
    initShare(page);
    initWazeLink(page);

    // Não bloqueia o restante da página: o mapa carrega o Leaflet de forma assíncrona.
    initMap(page).catch((error) => {
        console.error('[JAM-SHOW] falha ao montar o mapa', error);
        const container = page.querySelector('[data-jam-show-map]');
        if (container) {
            showMapMessage(container, '🗺️', jt('Não foi possível carregar o mapa.'), jt('Verifique sua conexão e recarregue a página.'));
        }
    });
}

export default initJamShow;
