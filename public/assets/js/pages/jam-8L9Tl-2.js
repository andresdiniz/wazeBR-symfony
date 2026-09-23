/**
 * pages/jam.js — Página /jams (theme-aware)
 *
 * Regras de negócio:
 *   - Interdição:        level = 5  OU  delay = -1
 *   - Interdição ativa:  interdição + last_seen recente
 *   - Interdição antiga: interdição + last_seen > 15min atrás
 *
 * Melhorias desta versão:
 *   - Cada linha vira link para /jams/{id}
 *   - Badge de "alertas próximos" (impacto)
 *   - Popup do mapa com botão "Ver detalhes"
 *   - Respeita o tema atual (não força dark)
 */

const REFRESH_MS      = 60_000;
const FILTER_DEBOUNCE = 350;

const LEVEL_COLORS = {
    1: '#22c55e',
    2: '#84cc16',
    3: '#f97316',
    4: '#ef4444',
    5: '#b91c1c',
};
const BLOCKED_ACTIVE_COLOR = '#7f1d1d';
const BLOCKED_STALE_COLOR  = '#78350f';
const ALERT_PIN_COLOR      = '#7c3aed';

const state = {
    endpoint: null,
    exportEndpoint: null,
    showUrlTemplate: '/jams/__ID__',
    timezone: 'America/Sao_Paulo',
    map: null,
    mapLayer: null,
    alertLayer: null,
    filterTimer: null,
    refreshTimer: null,
    clockTimer: null,
    lastData: null,
};

let bootstrapped = false;

// ─────────────────────────────────────────────────────────────────────────
// Utils
// ─────────────────────────────────────────────────────────────────────────

function $(sel, root = document) { return root.querySelector(sel); }
function $$(sel, root = document) { return [...root.querySelectorAll(sel)]; }

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

function buildShowUrl(jamId) {
    return state.showUrlTemplate.replace('__ID__', String(jamId));
}

function setText(selector, value) {
    const el = $(selector);
    if (el) el.textContent = value;
}

// ─────────────────────────────────────────────────────────────────────────
// Relógio do header
// ─────────────────────────────────────────────────────────────────────────

function installLiveClock() {
    const el = $('[data-jam-live-clock]');
    if (!el) return;

    const fmt = new Intl.DateTimeFormat('pt-BR', {
        hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false,
        timeZone: state.timezone,
    });

    const tick = () => { el.textContent = fmt.format(new Date()); };
    tick();
    state.clockTimer = setInterval(tick, 1000);
}

// ─────────────────────────────────────────────────────────────────────────
// Filtros
// ─────────────────────────────────────────────────────────────────────────

function readFilters() {
    const filters = {};
    $$('[data-jam-filter]').forEach((el) => {
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
        if (v === '' || v === null || v === undefined) url.searchParams.delete(k);
        else url.searchParams.set(k, v);
    });
    window.history.replaceState({}, '', url.toString());

    const exportLink = $('[data-jam-export]');
    if (exportLink && state.exportEndpoint) {
        const exp = new URL(state.exportEndpoint, window.location.origin);
        Object.entries(filters).forEach(([k, v]) => {
            if (v !== '' && v !== null && v !== undefined) exp.searchParams.set(k, v);
        });
        exportLink.href = exp.toString();
    }
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
    const form = $('[data-jam-filter-form]');
    if (!form) return;

    form.querySelectorAll('input[type="search"]').forEach((input) => {
        input.addEventListener('input', () => {
            clearTimeout(state.filterTimer);
            state.filterTimer = setTimeout(fetchAndRender, FILTER_DEBOUNCE);
        });
        input.addEventListener('keydown', (ev) => {
            if (ev.key === 'Enter') { ev.preventDefault(); fetchAndRender(); }
        });
    });

    form.querySelectorAll('select, input[type="checkbox"]').forEach((el) => {
        el.addEventListener('change', fetchAndRender);
    });

    form.addEventListener('submit', (ev) => {
        ev.preventDefault();
        fetchAndRender();
    });

    $$('[data-action="clear-filters"]').forEach((btn) => {
        btn.addEventListener('click', () => {
            $$('[data-jam-filter]').forEach((el) => {
                if (el.type === 'checkbox') el.checked = false;
                else if (el.tagName === 'SELECT') el.selectedIndex = 0;
                else el.value = '';
            });
            const lvl = document.querySelector('[data-jam-filter="level_min"]');
            if (lvl) lvl.value = '3';
            fetchAndRender();
        });
    });

    $$('[data-action="refresh"]').forEach((btn) => {
        btn.addEventListener('click', fetchAndRender);
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

        // Camada de jams (baixo)
        state.mapLayer = L.layerGroup().addTo(state.map);
        // Camada de alertas próximos (cima)
        state.alertLayer = L.layerGroup().addTo(state.map);

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
    state.alertLayer?.clearLayers();

    const jams = mapData.jams || [];
    const bounds = [];

    jams.forEach((j) => {
        if (!Array.isArray(j.path) || j.path.length < 2) return;

        let color, weight, opacity, style;

        if (j.isBlocked && j.isStale) {
            color = BLOCKED_STALE_COLOR;
            weight = 6; opacity = 0.75; style = 'dashed';
        } else if (j.isBlocked) {
            color = BLOCKED_ACTIVE_COLOR;
            weight = 8; opacity = 1.0; style = 'solid';
        } else {
            color = levelColor(j.level);
            weight = j.level >= 4 ? 6 : j.level >= 3 ? 5 : 4;
            opacity = 0.9; style = 'solid';
        }

        const poly = L.polyline(j.path, {
            color, weight, opacity,
            dashArray: style === 'dashed' ? '8, 6' : null,
            lineCap: 'round',
            lineJoin: 'round',
        });

        const delayLabel = (j.delay === -1 || j.delay === null || j.delay === undefined)
            ? 'sem medição'
            : `${Math.round(j.delay / 60)} min`;

        let flag = '';
        if (j.isBlocked && j.isStale) flag = `<br><span style="color:${BLOCKED_STALE_COLOR};font-weight:800">⚠ INTERDIÇÃO ANTIGA</span>`;
        else if (j.isBlocked)        flag = `<br><span style="color:#dc2626;font-weight:800">🚫 INTERDIÇÃO TOTAL</span>`;

        const alertsBadge = j.nearbyAlerts > 0
            ? `<br><span style="display:inline-block;margin-top:6px;padding:2px 8px;border-radius:999px;background:rgba(124,58,237,.14);color:#7c3aed;font-weight:800;font-size:11px">🔔 ${j.nearbyAlerts} alerta${j.nearbyAlerts > 1 ? 's' : ''} próximo${j.nearbyAlerts > 1 ? 's' : ''}</span>`
            : '';

        poly.bindPopup(`
            <div style="font-family:inherit;font-size:12px;line-height:1.45;min-width:220px">
                <strong style="display:block;font-size:13px;margin-bottom:3px">${escapeHtml(j.street || 'Via')}</strong>
                <small style="display:block;color:#64748b;margin-bottom:6px">${escapeHtml(j.city || '')}</small>
                <div style="display:flex;justify-content:space-between;padding:2px 0"><span style="color:#64748b">Nível</span><strong>${j.level}</strong></div>
                <div style="display:flex;justify-content:space-between;padding:2px 0"><span style="color:#64748b">Atraso</span><strong>${delayLabel}</strong></div>
                <div style="display:flex;justify-content:space-between;padding:2px 0"><span style="color:#64748b">Extensão</span><strong>${Math.round(j.length / 100) / 10} km</strong></div>
                ${flag}
                ${alertsBadge}
                <hr style="border:none;border-top:1px dashed #e2e8f0;margin:8px 0">
                <a href="${buildShowUrl(j.id)}" style="color:#7c3aed;font-weight:800;text-decoration:none">Ver detalhes &amp; impacto →</a>
            </div>
        `, { maxWidth: 320 });

        // Tooltip ao hover
        poly.bindTooltip(
            `<strong>${escapeHtml(j.street || 'Via')}</strong> · Nível ${j.level}${j.nearbyAlerts ? ` · 🔔 ${j.nearbyAlerts}` : ''}`,
            { sticky: true, direction: 'top' }
        );

        poly.on('click', () => {
            // Fecha outros e abre só este
            state.map.eachLayer((l) => { if (l instanceof L.Polyline) l.closePopup?.(); });
            poly.openPopup();
        });

        poly.addTo(state.mapLayer);
        j.path.forEach((pt) => bounds.push(pt));

        // Pinos dos alertas próximos (se houver)
        (j.alerts || []).forEach((a) => {
            if (!Number.isFinite(a.lat) || !Number.isFinite(a.lng)) return;
            const pin = L.circleMarker([a.lat, a.lng], {
                radius: 5,
                color: '#ffffff', weight: 2,
                fillColor: ALERT_PIN_COLOR, fillOpacity: 0.95,
            });
            pin.bindPopup(`
                <div style="font-family:inherit;font-size:12px;line-height:1.4;min-width:180px">
                    <strong style="display:block;font-size:12.5px">🔔 ${escapeHtml(a.type || 'Alerta')}</strong>
                    ${a.subtype ? `<small style="display:block;color:#64748b">${escapeHtml(a.subtype)}</small>` : ''}
                    ${a.street ? `<div style="margin-top:4px">${escapeHtml(a.street)}</div>` : ''}
                    <div style="display:flex;justify-content:space-between;padding:2px 0;margin-top:4px"><span style="color:#64748b">Distância</span><strong>${a.distanceMeters} m</strong></div>
                    <div style="display:flex;justify-content:space-between;padding:2px 0"><span style="color:#64748b">Δ Tempo</span><strong>${a.timeOffsetMinutes > 0 ? '+' : ''}${a.timeOffsetMinutes} min</strong></div>
                </div>
            `);
            pin.addTo(state.alertLayer);
        });
    });

    const hint = $('[data-jam-map-hint]');
    if (hint) {
        const total = jams.length;
        const withAlerts = jams.filter((j) => j.nearbyAlerts > 0).length;
        hint.textContent = withAlerts > 0
            ? `${total} vias · ${withAlerts} com impacto`
            : `${total} vias plotadas`;
    }

    if (bounds.length >= 2) {
        try {
            state.map.fitBounds(L.latLngBounds(bounds), { padding: [30, 30], maxZoom: 15 });
        } catch {
            if (mapData.center?.hasData) {
                state.map.setView([mapData.center.lat, mapData.center.lng], mapData.center.zoom, { animate: false });
            }
        }
    } else if (mapData.center?.hasData) {
        state.map.setView([mapData.center.lat, mapData.center.lng], mapData.center.zoom, { animate: false });
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Render — KPIs
// ─────────────────────────────────────────────────────────────────────────

function renderKPIs(summary) {
    const s = summary || {};
    const level4plus = (s.byLevel?.[4] ?? 0) + (s.byLevel?.[5] ?? 0);

    setText('[data-jam-kpi="total"]',         fmtNum(s.total ?? 0));
    setText('[data-jam-kpi="blockedActive"]', fmtNum(s.blockedActive ?? 0));
    setText('[data-jam-kpi="blockedStale"]',  fmtNum(s.blockedStale ?? 0));
    setText('[data-jam-kpi="avgDelay"]',      `${fmtNum(s.avgDelay ?? 0)}s`);
    setText('[data-jam-kpi="maxDelay"]',      `Máximo ${fmtNum(s.maxDelay ?? 0)}s`);
    setText('[data-jam-kpi="level4plus"]',    `${level4plus} em nível 4+`);

    setText('[data-jam-count="blocked-active"]', fmtNum(s.blockedActive ?? 0));
    setText('[data-jam-count="blocked-stale"]',  fmtNum(s.blockedStale ?? 0));
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
        const pct = Math.max(2, Math.round((count / max) * 100));
        const mod = count >= 30 ? ' jam-hourly__bar--alert'
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
// Render — tabelas
// ─────────────────────────────────────────────────────────────────────────

function levelCell(j) {
    if (j.isBlocked && j.isStale) return `<span class="jam-table__stale-badge">⚠ ANTIGA</span>`;
    if (j.isBlocked)              return `<span class="jam-table__blocked-badge">🚫 INTERDIÇÃO</span>`;
    return `<span class="jam-table__level jam-table__level--${j.level}">${j.level}</span>`;
}

function delayCell(j) {
    if (j.delay === -1 || j.delay === null || j.delay === undefined) {
        return `<span class="jam-table__dash">—</span>`;
    }
    if (j.delay === 0) return '0 min';
    const min = j.delayMin ?? (j.delay / 60);
    return `${fmtNum(min, min < 10 ? 1 : 0)} min`;
}

function timeCell(j) {
    if (j.isBlocked && j.isStale) {
        return `<span class="jam-table__time jam-table__time--stale">${fmtRelative(j.lastSeen || j.when)}</span>`;
    }
    return `<span class="jam-table__time">${fmtClock(j.when)}</span>`;
}

/**
 * Badge roxo: quantos alertas impactaram este jam.
 */
function alertsBadgeCell(j) {
    const n = Number(j.nearbyAlerts ?? 0);
    if (n <= 0) {
        return `<span class="jam-alerts-badge jam-alerts-badge--zero">—</span>`;
    }
    return `<span class="jam-alerts-badge"><i>🔔</i> ${n}</span>`;
}

function renderTableBody(tbody, rows, emptyMsg) {
    if (!tbody) return;

    if (!Array.isArray(rows) || rows.length === 0) {
        tbody.innerHTML = `<tr><td colspan="7" class="jam-empty">${escapeHtml(emptyMsg)}</td></tr>`;
        return;
    }

    tbody.innerHTML = rows.map((j) => {
        const url = buildShowUrl(j.id);
        const rowClass = (j.isBlocked && !j.isStale) ? 'is-blocked-row'
                       : (j.isBlocked && j.isStale) ? 'is-stale-row'
                       : '';
        return `
            <tr class="${rowClass}" data-jam-id="${j.id}" data-jam-href="${url}" tabindex="0">
                <td>${levelCell(j)}</td>
                <td>
                    <span class="jam-table__street">${escapeHtml(j.street || '—')}</span>
                    <span class="jam-table__city">${escapeHtml(j.city || '')}</span>
                </td>
                <td class="is-num">${delayCell(j)}</td>
                <td class="is-num">${fmtNum(j.speed, 0)} km/h</td>
                <td class="is-num">${j.lengthKm} km</td>
                <td>${alertsBadgeCell(j)}</td>
                <td>
                    ${timeCell(j)}
                    <a class="jam-row-link" href="${url}" aria-label="Detalhes">→</a>
                </td>
            </tr>
        `;
    }).join('');

    // Delegação de clique (uma vez por tbody)
    if (tbody.dataset.clickBound !== '1') {
        tbody.dataset.clickBound = '1';
        tbody.addEventListener('click', (e) => {
            if (e.target.closest('a')) return;
            const tr = e.target.closest('tr[data-jam-href]');
            if (!tr) return;
            window.location.href = tr.dataset.jamHref;
        });
        tbody.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter') return;
            const tr = e.target.closest('tr[data-jam-href]');
            if (!tr) return;
            window.location.href = tr.dataset.jamHref;
        });
    }
}

function renderAllTables(data) {
    renderTableBody(
        $('[data-jam-table-blocked-active]'),
        data.blockedActive || [],
        'Nenhuma interdição ativa no momento.'
    );
    renderTableBody(
        $('[data-jam-table-blocked-stale]'),
        data.blockedStale || [],
        'Sem interdições antigas.'
    );
    renderTableBody(
        $('[data-jam-table]'),
        data.topJams || [],
        'Sem congestionamentos com os filtros atuais.'
    );

    setText('[data-jam-count="blocked-active"]', fmtNum(data.blockedActive?.length ?? 0));
    setText('[data-jam-count="blocked-stale"]',  fmtNum(data.blockedStale?.length ?? 0));
    setText('[data-jam-count="top-jams"]',       fmtNum(data.topJams?.length ?? 0));
}

// ─────────────────────────────────────────────────────────────────────────
// Render — rankings
// ─────────────────────────────────────────────────────────────────────────

function rankItem(r, idx, kind) {
    const isActive = Number(r.blockedActive) > 0;
    const isStale  = !isActive && Number(r.blockedStale) > 0;
    const itemClass = isActive ? ' is-blocked' : (isStale ? ' is-stale' : '');

    let marks = '';
    if (isActive) marks += `<span class="mark-blocked">🚫 ${r.blockedActive}</span> · `;
    if (Number(r.blockedStale) > 0) marks += `<span class="mark-stale">⚠ ${r.blockedStale}</span> · `;

    let foot = marks;
    if (kind === 'streets') foot += r.maxDelay > 0 ? `máx ${r.maxDelay}s` : '—';
    else                    foot += r.avgDelay > 0 ? `média ${r.avgDelay}s` : '—';

    const name = kind === 'streets'
        ? `${escapeHtml(r.street)}<span class="jam-rank__city">${escapeHtml(r.city)}</span>`
        : escapeHtml(r.city);

    return `
        <li class="${itemClass}">
            <span class="jam-rank__pos">${idx + 1}</span>
            <span class="jam-rank__name">${name}</span>
            <span class="jam-rank__stats">
                ${r.count}
                <small>${foot}</small>
            </span>
        </li>
    `;
}

function renderRankStreets(rows) {
    const el = $('[data-jam-rank-streets]');
    if (!el) return;
    if (!Array.isArray(rows) || rows.length === 0) {
        el.innerHTML = '<li class="jam-empty">Sem dados</li>';
        return;
    }
    el.innerHTML = rows.map((r, i) => rankItem(r, i, 'streets')).join('');
}

function renderRankCities(rows) {
    const el = $('[data-jam-rank-cities]');
    if (!el) return;
    if (!Array.isArray(rows) || rows.length === 0) {
        el.innerHTML = '<li class="jam-empty">Sem dados</li>';
        return;
    }
    el.innerHTML = rows.map((r, i) => rankItem(r, i, 'cities')).join('');
}

// ─────────────────────────────────────────────────────────────────────────
// Fetch + render
// ─────────────────────────────────────────────────────────────────────────

async function fetchAndRender() {
    if (!state.endpoint) return;

    const filters = readFilters();
    applyFilterToUrl(filters);

    const url = new URL(state.endpoint, window.location.origin);
    Object.entries(filters).forEach(([k, v]) => {
        if (v !== '' && v !== null && v !== undefined) url.searchParams.set(k, v);
    });

    try {
        const res = await fetch(url.toString(), {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store',
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);

        const json = await res.json();
        if (!json?.data) throw new Error('Payload inválido');

        const data = json.data;
        state.lastData = data;

        renderKPIs(data.summary);
        renderLevels(data.summary?.byLevel);
        renderHourly(data.byHour);
        renderAllTables(data);
        renderRankStreets(data.topStreets);
        renderRankCities(data.topCities);
        renderMap(data.map);

        setText('[data-jam-live-clock]', new Intl.DateTimeFormat('pt-BR', {
            hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false,
            timeZone: state.timezone,
        }).format(new Date()));
    } catch (err) {
        console.error('[JAM] fetch falhou', err);
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
    state.exportEndpoint = page.dataset.exportEndpoint;
    state.showUrlTemplate = page.dataset.showUrlTemplate || '/jams/__ID__';
    if (!state.endpoint) {
        console.warn('[JAM] sem data-api-endpoint');
        return;
    }
    state.timezone = page.dataset.timezone || 'America/Sao_Paulo';

    restoreFiltersFromUrl();
    installFilterHandlers();
    installLiveClock();

    initMap();
    fetchAndRender();

    state.refreshTimer = setInterval(fetchAndRender, REFRESH_MS);

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) fetchAndRender();
    });

    window.addEventListener('beforeunload', () => {
        if (state.refreshTimer) clearInterval(state.refreshTimer);
        if (state.clockTimer) clearInterval(state.clockTimer);
    });
}

export default initJamPage;
