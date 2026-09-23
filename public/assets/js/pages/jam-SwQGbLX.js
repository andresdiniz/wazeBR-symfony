/**
 * pages/jam.js — Página /jams
 *
 * Considerações:
 *   - delay = -1  → Waze não estimou o atraso; tratamos como INTERDIÇÃO
 *   - level = 5   → parado; tratamos como INTERDIÇÃO
 *   - Interdições sobem pro topo da tabela e ganham visual distinto
 */

const REFRESH_MS       = 60_000;
const REFETCH_DEBOUNCE = 400;
const FILTER_DEBOUNCE  = 350;

const LEVEL_COLORS = {
    1: '#22c55e',
    2: '#84cc16',
    3: '#f97316',
    4: '#ef4444',
    5: '#b91c1c',
};
const BLOCKED_COLOR = '#7f1d1d';

const state = {
    endpoint: null,
    timezone: 'America/Sao_Paulo',
    map: null,
    mapLayer: null,
    filterTimer: null,
    refreshTimer: null,
    lastData: null,
};

let bootstrapped = false;

// ─────────────────────────────────────────────────────────────────────────
// Utils
// ─────────────────────────────────────────────────────────────────────────

function $(sel, root = document) { return root.querySelector(sel); }

function escapeHtml(str) {
    return String(str ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function fmtNum(n, digits = 0) {
    if (n === null || n === undefined) return '—';
    return new Intl.NumberFormat('pt-BR', {
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    }).format(n);
}

function fmtRelative(iso) {
    if (!iso) return '—';
    try {
        const diff = Date.now() - new Date(iso).getTime();
        const s = Math.max(0, Math.floor(diff / 1000));
        if (s < 60) return `há ${s}s`;
        const m = Math.floor(s / 60);
        if (m < 60) return `há ${m} min`;
        const h = Math.floor(m / 60);
        return `há ${h}h`;
    } catch { return '—'; }
}

function fmtClock(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '—';
    try {
        return new Intl.DateTimeFormat('pt-BR', {
            hour: '2-digit', minute: '2-digit', hour12: false,
            timeZone: state.timezone,
        }).format(d);
    } catch { return '—'; }
}

function levelColor(level) {
    return LEVEL_COLORS[level] ?? '#94a3b8';
}

// ─────────────────────────────────────────────────────────────────────────
// Filtros
// ─────────────────────────────────────────────────────────────────────────

function readFilters() {
    const filters = {};
    document.querySelectorAll('[data-jam-filter]').forEach((el) => {
        if (el.type === 'checkbox') {
            filters[el.dataset.jamFilter] = el.checked ? '1' : '';
        } else {
            filters[el.dataset.jamFilter] = el.value;
        }
    });
    return filters;
}

function applyFilterToUrl(filters) {
    const url = new URL(window.location.href);
    Object.entries(filters).forEach(([k, v]) => {
        if (v === '' || v === null || v === undefined) {
            url.searchParams.delete(k);
        } else {
            url.searchParams.set(k, v);
        }
    });
    window.history.replaceState({}, '', url.toString());
}

function restoreFiltersFromUrl() {
    const url = new URL(window.location.href);
    url.searchParams.forEach((value, key) => {
        const el = document.querySelector(`[data-jam-filter="${key}"]`);
        if (!el) return;
        if (el.type === 'checkbox') {
            el.checked = value === '1' || value === 'true';
        } else {
            el.value = value;
        }
    });
}

function installFilterHandlers() {
    const form = $('[data-jam-filters]');
    if (!form) return;

    form.querySelectorAll('input[type="text"]').forEach((input) => {
        input.addEventListener('input', () => {
            clearTimeout(state.filterTimer);
            state.filterTimer = setTimeout(fetchAndRender, FILTER_DEBOUNCE);
        });
        input.addEventListener('keydown', (ev) => {
            if (ev.key === 'Enter') {
                ev.preventDefault();
                fetchAndRender();
            }
        });
    });

    form.querySelectorAll('select').forEach((select) => {
        select.addEventListener('change', fetchAndRender);
    });

    // Checkbox aplica imediato
    form.querySelectorAll('input[type="checkbox"]').forEach((checkbox) => {
        checkbox.addEventListener('change', fetchAndRender);
    });

    $('[data-jam-apply]')?.addEventListener('click', fetchAndRender);

    $('[data-jam-reset]')?.addEventListener('click', () => {
        document.querySelectorAll('[data-jam-filter]').forEach((el) => {
            if (el.type === 'checkbox') {
                el.checked = false;
            } else if (el.tagName === 'SELECT') {
                el.selectedIndex = 0;
            } else {
                el.value = '';
            }
        });
        const lvl = document.querySelector('[data-jam-filter="level_min"]');
        if (lvl) lvl.value = '3';
        const win = document.querySelector('[data-jam-filter="window_hours"]');
        if (win) win.value = '2';
        fetchAndRender();
    });
}

// ─────────────────────────────────────────────────────────────────────────
// Mapa
// ─────────────────────────────────────────────────────────────────────────

function initMap() {
    const container = $('[data-jam-map]');
    if (!container) return;

    if (typeof L === 'undefined') {
        let tries = 0;
        const retry = setInterval(() => {
            if (typeof L !== 'undefined' || ++tries > 30) {
                clearInterval(retry);
                if (typeof L !== 'undefined') doInitMap(container);
            }
        }, 100);
        return;
    }
    doInitMap(container);
}

function doInitMap(container) {
    try {
        state.map = L.map(container, {
            zoomControl: true,
            attributionControl: true,
            preferCanvas: true,
            center: [-20.66, -43.78],
            zoom: 12,
        });

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap',
        }).addTo(state.map);

        state.mapLayer = L.layerGroup().addTo(state.map);

        setTimeout(() => state.map && state.map.invalidateSize(), 200);
        setTimeout(() => state.map && state.map.invalidateSize(), 600);
        window.addEventListener('resize', () => state.map && state.map.invalidateSize());
    } catch (err) {
        console.error('[JAM] mapa falhou', err);
    }
}

function renderMap(mapData) {
    if (!state.map || !state.mapLayer || !mapData) return;

    state.mapLayer.clearLayers();

    const jams = mapData.jams || [];
    const bounds = [];

    jams.forEach((j) => {
        if (!Array.isArray(j.path) || j.path.length < 2) return;

        const isBlocked = Boolean(j.isBlocked);
        const color = isBlocked ? BLOCKED_COLOR : levelColor(j.level);

        // Interdições: traço grosso, escuro, sólido.
        // Demais: espessura por nível.
        const weight = isBlocked ? 8
                    : j.level >= 4 ? 6
                    : j.level >= 3 ? 5
                    : 4;

        const poly = L.polyline(j.path, {
            color,
            weight,
            opacity: isBlocked ? 1.0 : 0.88,
            lineCap: 'round',
            lineJoin: 'round',
        });

        const delayLabel = (j.delay === -1 || j.delay === null || j.delay === undefined)
            ? 'sem medição'
            : `${Math.round(j.delay / 60)} min`;

        const blockedLabel = isBlocked
            ? '<br><span style="color:#fca5a5;font-weight:800">🚫 INTERDIÇÃO TOTAL</span>'
            : '';

        poly.bindTooltip(
            `<strong>${escapeHtml(j.street || 'Via')}</strong><br>` +
            `${escapeHtml(j.city || '')}<br>` +
            `Nível ${j.level} · ${delayLabel} · ` +
            `${Math.round(j.length / 100) / 10} km` +
            blockedLabel,
            { sticky: true, direction: 'top' }
        );

        poly.addTo(state.mapLayer);
        j.path.forEach((pt) => bounds.push(pt));
    });

    const badge = $('[data-jam-map-badge]');
    const badgeCount = $('[data-jam-map-count]');
    if (badgeCount) badgeCount.textContent = String(jams.length);
    if (badge) badge.hidden = jams.length === 0;

    if (bounds.length >= 2) {
        try {
            state.map.fitBounds(L.latLngBounds(bounds), { padding: [30, 30], maxZoom: 15 });
        } catch {
            if (mapData.center && mapData.center.hasData) {
                state.map.setView([mapData.center.lat, mapData.center.lng], mapData.center.zoom, { animate: false });
            }
        }
    } else if (mapData.center && mapData.center.hasData) {
        state.map.setView([mapData.center.lat, mapData.center.lng], mapData.center.zoom, { animate: false });
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Render — stats
// ─────────────────────────────────────────────────────────────────────────

function renderSummary(summary) {
    const s = summary || {};

    setText('[data-jam-stat="total"]',        fmtNum(s.total ?? 0));
    setText('[data-jam-stat="level3"]',       fmtNum(s.byLevel?.[3] ?? 0));
    setText('[data-jam-stat="level4"]',       fmtNum(s.byLevel?.[4] ?? 0));
    setText('[data-jam-stat="blocked"]',      fmtNum(s.blocked ?? 0));
    setText('[data-jam-stat="avgDelay"]',     `${fmtNum(s.avgDelay ?? 0)}s`);
    setText('[data-jam-stat="maxDelay"]',     `${fmtNum(s.maxDelay ?? 0)}s`);
    setText('[data-jam-stat="avgSpeed"]',     `${fmtNum(s.avgSpeed ?? 0)} km/h`);
    setText('[data-jam-stat="totalLength"]',  `${fmtNum((s.totalLength ?? 0) / 1000, 1)} km`);
}

// ─────────────────────────────────────────────────────────────────────────
// Render — gráficos
// ─────────────────────────────────────────────────────────────────────────

function renderLevels(byLevel) {
    const el = $('[data-jam-levels]');
    if (!el) return;

    const levels = byLevel || {};
    const max = Math.max(1, ...Object.values(levels).map((v) => Number(v) || 0));
    const labels = {
        1: '1 · Baixo',
        2: '2 · Moderado',
        3: '3 · Alto',
        4: '4 · Muito alto',
        5: '5 · Parado',
    };

    el.innerHTML = [1, 2, 3, 4, 5].map((lvl) => {
        const count = Number(levels[lvl] ?? 0);
        const pct = max > 0 ? Math.round((count / max) * 100) : 0;
        return `
            <div class="jam-level">
                <span class="jam-level__label">${labels[lvl]}</span>
                <div class="jam-level__bar">
                    <span class="jam-level__fill jam-level__fill--${lvl}" style="width:${pct}%"></span>
                </div>
                <span class="jam-level__count">${count}</span>
            </div>
        `;
    }).join('');
}

function renderHourly(byHour) {
    const el = $('[data-jam-hourly]');
    if (!el) return;

    if (!Array.isArray(byHour) || byHour.length === 0) {
        el.innerHTML = '<div class="jam-empty">Sem dados</div>';
        return;
    }

    const max = Math.max(1, ...byHour.map((h) => Number(h.count) || 0));

    el.innerHTML = byHour.map((h) => {
        const count = Number(h.count) || 0;
        const pct   = Math.max(2, Math.round((count / max) * 100));
        const mod   = count >= 30 ? ' jam-hourly__bar--alert'
                    : count >= 15 ? ' jam-hourly__bar--warn'
                    : '';
        return `
            <div class="jam-hourly__bar${mod}"
                 style="height:${pct}%"
                 data-count="${count}">
                <span class="jam-hourly__label">${escapeHtml(fmtClock(h.at))}</span>
            </div>
        `;
    }).join('');

    const hint = $('[data-jam-hourly-window]');
    if (hint) hint.textContent = `últimas ${byHour.length}h`;
}

// ─────────────────────────────────────────────────────────────────────────
// Render — tabela
// ─────────────────────────────────────────────────────────────────────────

/**
 * Célula de nível. Se for interdição, mostra badge própria.
 * Caso contrário, mostra o número do nível com cor.
 */
function levelCell(j) {
    if (j.isBlocked) {
        const icon = j.level === 5 ? '🚫' : '⚠';
        return `<span class="jam-table__blocked-badge">${icon} INTERDIÇÃO</span>`;
    }
    return `<span class="jam-table__level jam-table__level--${j.level}">${j.level}</span>`;
}

/**
 * Célula de atraso. delay = -1 vira "—".
 */
function delayCell(j) {
    if (j.delay === -1 || j.delay === null || j.delay === undefined) {
        return `<span class="jam-table__dash">—</span>`;
    }
    if (j.delay === 0) return '0 min';
    const min = j.delayMin ?? (j.delay / 60);
    return `${fmtNum(min, min < 10 ? 1 : 0)} min`;
}

function renderTable(rows) {
    const tbody = $('[data-jam-table]');
    if (!tbody) return;

    if (!Array.isArray(rows) || rows.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="jam-empty">Sem congestionamentos com os filtros atuais</td></tr>';
        return;
    }

    tbody.innerHTML = rows.map((j) => {
        const rowClass = j.isBlocked ? ' class="jam-table__row--blocked"' : '';
        const tag = j.isBlocked
            ? `<span class="jam-table__blocked-tag">🚫 interdição total</span>`
            : '';

        return `
            <tr${rowClass}>
                <td>${levelCell(j)}</td>
                <td>
                    <span class="jam-table__street">${escapeHtml(j.street || '—')}</span>
                    <span class="jam-table__city">${escapeHtml(j.city || '')}</span>
                    ${tag}
                </td>
                <td class="is-num">${delayCell(j)}</td>
                <td class="is-num">${fmtNum(j.speed, 0)} km/h</td>
                <td class="is-num">${j.lengthKm} km</td>
                <td class="is-num">${j.points}</td>
                <td><span class="jam-table__time">${fmtClock(j.when)}</span></td>
            </tr>
        `;
    }).join('');
}

// ─────────────────────────────────────────────────────────────────────────
// Render — rankings
// ─────────────────────────────────────────────────────────────────────────

function renderRankStreets(rows) {
    const el = $('[data-jam-rank-streets]');
    if (!el) return;

    if (!Array.isArray(rows) || rows.length === 0) {
        el.innerHTML = '<li class="jam-empty">Sem dados</li>';
        return;
    }

    el.innerHTML = rows.map((r, i) => {
        const isBlocked = Number(r.blocked) > 0;
        const blockedMark = isBlocked
            ? `<span class="jam-rank__blocked-mark">🚫 ${r.blocked}</span> · `
            : '';
        const delay = r.maxDelay > 0 ? `máx ${r.maxDelay}s` : '—';

        return `
            <li${isBlocked ? ' class="is-blocked"' : ''}>
                <span class="jam-rank__pos">${i + 1}</span>
                <span class="jam-rank__name">
                    ${escapeHtml(r.street)}
                    <span class="jam-rank__city">${escapeHtml(r.city)}</span>
                </span>
                <span class="jam-rank__stats">
                    ${r.count}
                    <small>${blockedMark}${delay}</small>
                </span>
            </li>
        `;
    }).join('');
}

function renderRankCities(rows) {
    const el = $('[data-jam-rank-cities]');
    if (!el) return;

    if (!Array.isArray(rows) || rows.length === 0) {
        el.innerHTML = '<li class="jam-empty">Sem dados</li>';
        return;
    }

    el.innerHTML = rows.map((r, i) => {
        const isBlocked = Number(r.blocked) > 0;
        const blockedMark = isBlocked
            ? `<span class="jam-rank__blocked-mark">🚫 ${r.blocked}</span> · `
            : '';
        const avg = r.avgDelay > 0 ? `média ${r.avgDelay}s` : '—';

        return `
            <li${isBlocked ? ' class="is-blocked"' : ''}>
                <span class="jam-rank__pos">${i + 1}</span>
                <span class="jam-rank__name">${escapeHtml(r.city)}</span>
                <span class="jam-rank__stats">
                    ${r.count}
                    <small>${blockedMark}${avg}</small>
                </span>
            </li>
        `;
    }).join('');
}

// ─────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────

function setText(selector, value) {
    const el = $(selector);
    if (el) el.textContent = value;
}

// ─────────────────────────────────────────────────────────────────────────
// Fetch + render principal
// ─────────────────────────────────────────────────────────────────────────

async function fetchAndRender() {
    if (!state.endpoint) return;

    const filters = readFilters();
    applyFilterToUrl(filters);

    const url = new URL(state.endpoint, window.location.origin);
    Object.entries(filters).forEach(([k, v]) => {
        if (v !== '' && v !== null && v !== undefined) {
            url.searchParams.set(k, v);
        }
    });

    try {
        const res = await fetch(url.toString(), {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store',
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);

        const json = await res.json();
        if (!json || !json.data) throw new Error('Payload inválido');

        const data = json.data;
        state.lastData = data;

        renderSummary(data.summary);
        renderLevels(data.summary?.byLevel);
        renderHourly(data.byHour);
        renderTable(data.topJams);
        renderRankStreets(data.topStreets);
        renderRankCities(data.topCities);
        renderMap(data.map);

        setText('[data-jam-updated]', `atualizado ${fmtRelative(data.generatedAt)}`);
    } catch (err) {
        console.error('[JAM] fetch falhou', err);
        setText('[data-jam-updated]', 'falha ao atualizar');
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Bootstrap
// ─────────────────────────────────────────────────────────────────────────

export function initJamPage(root = document) {
    const page = root.querySelector?.('[data-jam-page]') ?? root;
    if (!page || !page.matches?.('[data-jam-page]') || bootstrapped) return;
    bootstrapped = true;

    state.endpoint = page.dataset.apiEndpoint;
    if (!state.endpoint) {
        console.warn('[JAM] sem data-api-endpoint');
        return;
    }
    state.timezone = page.dataset.timezone || 'America/Sao_Paulo';

    restoreFiltersFromUrl();
    installFilterHandlers();

    initMap();
    fetchAndRender();

    state.refreshTimer = setInterval(fetchAndRender, REFRESH_MS);

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) fetchAndRender();
    });

    window.addEventListener('beforeunload', () => {
        if (state.refreshTimer) clearInterval(state.refreshTimer);
    });
}

export default initJamPage;
