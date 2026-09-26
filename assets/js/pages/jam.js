/**
 * pages/jam.js — Página /jams (theme-aware)
 *
 * Melhorias aplicadas:
 *  #2  Sort clicável em todas as colunas (client-side, memória)
 *  #3  "Carregar mais" por tabela (PAGE_SIZE incremental)
 *  #4  Empty state contextual com filtros ativos listados
 *  #5  Coluna 🔔 alertas próximos consistente em todas as tabelas
 * #13  data-show-url-template lido do DOM (Symfony gera a URL real)
 * #14  Relógio gerenciado por um único setInterval
 */

const REFRESH_MS      = 60_000;
const FILTER_DEBOUNCE = 350;
const PAGE_SIZE       = 30;   // linhas visíveis por tabela (incrementa no "carregar mais")

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
    endpoint:        null,
    exportEndpoint:  null,
    showUrlTemplate: '/jams/__ID__',   // sobrescrito pelo data-attribute (#13)
    timezone:        'America/Sao_Paulo',
    map:             null,
    mapLayer:        null,
    alertLayer:      null,
    filterTimer:     null,
    refreshTimer:    null,
    clockTimer:      null,
    lastData:        null,
    // sort por tabela: { 'blocked-active': { key, dir }, ... }
    sort: {
        'blocked-active': { key: 'level', dir: -1 },
        'blocked-stale':  { key: 'level', dir: -1 },
        'top-jams':       { key: 'level', dir: -1 },
    },
    // quantas linhas cada tabela exibe agora
    pageSize: {
        'blocked-active': PAGE_SIZE,
        'blocked-stale':  PAGE_SIZE,
        'top-jams':       PAGE_SIZE,
    },
    // dados completos (antes da paginação)
    rows: {
        'blocked-active': [],
        'blocked-stale':  [],
        'top-jams':       [],
    },
};

let bootstrapped = false;

// ─────────────────────────────────────────────────────────────────────────
// Utils
// ─────────────────────────────────────────────────────────────────────────

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];

function escapeHtml(str) {
    return String(str ?? '')
        .replaceAll('&', '&amp;').replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;').replaceAll('"', '&quot;')
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
        const s = Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 1000));
        if (s < 60)  return `há ${s}s`;
        const m = Math.floor(s / 60);
        if (m < 60) return `há ${m} min`;
        return `há ${Math.floor(m / 60)}h`;
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
// Relógio único (#14 — substitui a duplicação anterior)
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
    // #14 — um único timer; o fetchAndRender NÃO sobrescreve mais este elemento
    state.clockTimer = setInterval(tick, 1000);
}

// ─────────────────────────────────────────────────────────────────────────
// Filtros
// ─────────────────────────────────────────────────────────────────────────

function readFilters() {
    const filters = {};
    $$('[data-jam-filter]').forEach((el) => {
        filters[el.dataset.jamFilter] = el.type === 'checkbox'
            ? (el.checked ? '1' : '')
            : el.value;
    });
    return filters;
}

function applyFilterToUrl(filters) {
    const url = new URL(window.location.href);
    Object.entries(filters).forEach(([k, v]) => {
        if (v === '' || v == null) url.searchParams.delete(k);
        else url.searchParams.set(k, v);
    });
    window.history.replaceState({}, '', url.toString());

    const exportLink = $('[data-jam-export]');
    if (exportLink && state.exportEndpoint) {
        const exp = new URL(state.exportEndpoint, window.location.origin);
        Object.entries(filters).forEach(([k, v]) => {
            if (v !== '' && v != null) exp.searchParams.set(k, v);
        });
        exportLink.href = exp.toString();
    }
}

function restoreFiltersFromUrl() {
    const url = new URL(window.location.href);
    url.searchParams.forEach((value, key) => {
        const el = document.querySelector(`[data-jam-filter="${key}"]`);
        if (!el) return;
        if (el.type === 'checkbox') el.checked = (value === '1' || value === 'true');
        else el.value = value;
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

    form.querySelectorAll('select, input[type="checkbox"]')
        .forEach((el) => el.addEventListener('change', fetchAndRender));

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
// Sort (#2)
// ─────────────────────────────────────────────────────────────────────────

/**
 * Instala handlers de sort nos <th data-sortable="..."> de uma tabela.
 * O sort é client-side sobre os dados em `state.rows[tableKey]`.
 */
function installSortHandlers(table, tableKey) {
    if (!table || table.dataset.sortBound === '1') return;
    table.dataset.sortBound = '1';

    table.querySelectorAll('th[data-sortable]').forEach((th) => {
        th.style.cursor = 'pointer';
        th.addEventListener('click', () => {
            const key = th.dataset.sortable;
            const s   = state.sort[tableKey];

            if (s.key === key) {
                s.dir *= -1;
            } else {
                s.key = key;
                s.dir = -1;   // desc por padrão
            }

            // Atualiza ícones
            table.querySelectorAll('th[data-sortable]').forEach((h) => {
                const icon = h.querySelector('.jam-sort-icon');
                if (!icon) return;
                if (h.dataset.sortable === s.key) {
                    icon.textContent = s.dir === -1 ? ' ↓' : ' ↑';
                    icon.className   = 'jam-sort-icon is-active';
                } else {
                    icon.textContent = '';
                    icon.className   = 'jam-sort-icon';
                }
            });

            // Re-renderiza com o sort novo
            renderTable(tableKey);
        });
    });
}

function sortedRows(tableKey) {
    const { key, dir } = state.sort[tableKey];
    return [...state.rows[tableKey]].sort((a, b) => {
        const av = Number(a[key] ?? -Infinity);
        const bv = Number(b[key] ?? -Infinity);
        return (av - bv) * dir;
    });
}

// ─────────────────────────────────────────────────────────────────────────
// Paginação (#3)
// ─────────────────────────────────────────────────────────────────────────

function installLoadMore(tableKey) {
    const wrap = $(`[data-jam-load-more="${tableKey}"]`);
    if (!wrap || wrap.dataset.lmBound === '1') return;
    wrap.dataset.lmBound = '1';

    wrap.querySelector('button').addEventListener('click', () => {
        state.pageSize[tableKey] += PAGE_SIZE;
        renderTable(tableKey);
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
            if (typeof L !== 'undefined' || ++tries > 40) {
                clearInterval(retry);
                if (typeof L !== 'undefined') doInitMap(container);
                else {
                    // #12 — fallback: mostra aviso no container do mapa
                    container.innerHTML = `
                        <div style="display:flex;align-items:center;justify-content:center;
                                    height:100%;color:var(--jam-text-muted);font-size:13px;gap:8px">
                            ⚠ Não foi possível carregar o mapa. Verifique sua conexão.
                        </div>`;
                }
            }
        }, 100);
        return;
    }
    doInitMap(container);
}

function doInitMap(container) {
    try {
        state.map = L.map(container, {
            zoomControl: true, attributionControl: true,
            preferCanvas: true,
            center: [-20.66, -43.78], zoom: 12,
        });

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap',
        }).addTo(state.map);

        state.mapLayer   = L.layerGroup().addTo(state.map);
        state.alertLayer = L.layerGroup().addTo(state.map);

        setTimeout(() => state.map?.invalidateSize(), 200);
        setTimeout(() => state.map?.invalidateSize(), 600);
        window.addEventListener('resize', () => state.map?.invalidateSize());
    } catch (err) {
        console.error('[JAM] mapa falhou', err);
    }
}

function renderMap(mapData) {
    if (!state.map || !state.mapLayer || !mapData) return;

    state.mapLayer.clearLayers();
    state.alertLayer?.clearLayers();

    const jams   = mapData.jams || [];
    const bounds = [];

    jams.forEach((j) => {
        if (!Array.isArray(j.path) || j.path.length < 2) return;

        let color, weight, opacity, dashArray = null;

        if (j.isBlocked && j.isStale) {
            color = BLOCKED_STALE_COLOR; weight = 6; opacity = 0.75; dashArray = '8, 6';
        } else if (j.isBlocked) {
            color = BLOCKED_ACTIVE_COLOR; weight = 8; opacity = 1.0;
        } else {
            color   = levelColor(j.level);
            weight  = j.level >= 4 ? 6 : j.level >= 3 ? 5 : 4;
            opacity = 0.9;
        }

        const poly = L.polyline(j.path, {
            color, weight, opacity, dashArray,
            lineCap: 'round', lineJoin: 'round',
        });

        const delayLabel = (j.delay === -1 || j.delay == null)
            ? 'sem medição'
            : `${Math.round(j.delay / 60)} min`;

        let flag = '';
        if (j.isBlocked && j.isStale)
            flag = `<br><span style="color:${BLOCKED_STALE_COLOR};font-weight:800">⚠ INTERDIÇÃO ANTIGA</span>`;
        else if (j.isBlocked)
            flag = `<br><span style="color:#dc2626;font-weight:800">🚫 INTERDIÇÃO TOTAL</span>`;

        const alertsBadge = j.nearbyAlerts > 0
            ? `<br><span style="display:inline-block;margin-top:6px;padding:2px 8px;border-radius:999px;
                               background:rgba(124,58,237,.14);color:#7c3aed;font-weight:800;font-size:11px">
                🔔 ${j.nearbyAlerts} alerta${j.nearbyAlerts > 1 ? 's' : ''} próximo${j.nearbyAlerts > 1 ? 's' : ''}
               </span>`
            : '';

        poly.bindPopup(`
            <div style="font-family:inherit;font-size:12px;line-height:1.45;min-width:220px">
                <strong style="display:block;font-size:13px;margin-bottom:3px">${escapeHtml(j.street || 'Via')}</strong>
                <small style="display:block;color:#64748b;margin-bottom:6px">${escapeHtml(j.city || '')}</small>
                <div style="display:flex;justify-content:space-between;padding:2px 0"><span style="color:#64748b">Nível</span><strong>${j.level}</strong></div>
                <div style="display:flex;justify-content:space-between;padding:2px 0"><span style="color:#64748b">Atraso</span><strong>${delayLabel}</strong></div>
                <div style="display:flex;justify-content:space-between;padding:2px 0"><span style="color:#64748b">Extensão</span><strong>${Math.round(j.length / 100) / 10} km</strong></div>
                ${flag}${alertsBadge}
                <hr style="border:none;border-top:1px dashed #e2e8f0;margin:8px 0">
                <a href="${buildShowUrl(j.id)}" style="color:#7c3aed;font-weight:800;text-decoration:none">
                    Ver detalhes &amp; impacto →
                </a>
            </div>
        `, { maxWidth: 320 });

        poly.bindTooltip(
            `<strong>${escapeHtml(j.street || 'Via')}</strong> · Nível ${j.level}${j.nearbyAlerts ? ` · 🔔 ${j.nearbyAlerts}` : ''}`,
            { sticky: true, direction: 'top' }
        );

        poly.addTo(state.mapLayer);
        j.path.forEach((pt) => bounds.push(pt));

        // Pinos de alertas próximos
        (j.alerts || []).forEach((a) => {
            if (!Number.isFinite(a.lat) || !Number.isFinite(a.lng)) return;
            L.circleMarker([a.lat, a.lng], {
                radius: 5, color: '#fff', weight: 2,
                fillColor: ALERT_PIN_COLOR, fillOpacity: 0.95,
            }).bindPopup(`
                <div style="font-family:inherit;font-size:12px;line-height:1.4;min-width:180px">
                    <strong style="display:block;font-size:12.5px">🔔 ${escapeHtml(a.type || 'Alerta')}</strong>
                    ${a.subtype ? `<small style="display:block;color:#64748b">${escapeHtml(a.subtype)}</small>` : ''}
                    ${a.street  ? `<div style="margin-top:4px">${escapeHtml(a.street)}</div>` : ''}
                    <div style="display:flex;justify-content:space-between;padding:2px 0;margin-top:4px">
                        <span style="color:#64748b">Distância</span><strong>${a.distanceMeters} m</strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;padding:2px 0">
                        <span style="color:#64748b">Δ Tempo</span>
                        <strong>${a.timeOffsetMinutes > 0 ? '+' : ''}${a.timeOffsetMinutes} min</strong>
                    </div>
                </div>
            `).addTo(state.alertLayer);
        });
    });

    const hint = $('[data-jam-map-hint]');
    if (hint) {
        const withAlerts = jams.filter((j) => j.nearbyAlerts > 0).length;
        hint.textContent = withAlerts > 0
            ? `${jams.length} vias · ${withAlerts} com impacto`
            : `${jams.length} vias plotadas`;
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
}

// ─────────────────────────────────────────────────────────────────────────
// Render — gráficos
// ─────────────────────────────────────────────────────────────────────────

function renderLevels(byLevel) {
    const el = $('[data-jam-levels]');
    if (!el) return;

    const levels  = byLevel || {};
    const max     = Math.max(1, ...Object.values(levels).map((v) => Number(v) || 0));
    const labels  = { 1: '1 · Baixo', 2: '2 · Moderado', 3: '3 · Alto', 4: '4 · Muito alto', 5: '5 · Parado' };

    el.innerHTML = [1, 2, 3, 4, 5].map((lvl) => {
        const count = Number(levels[lvl] ?? 0);
        const pct   = max > 0 ? Math.round((count / max) * 100) : 0;
        return `
            <div class="jam-level">
                <span class="jam-level__label">${labels[lvl]}</span>
                <div class="jam-level__bar">
                    <span class="jam-level__fill jam-level__fill--${lvl}" style="width:${pct}%"></span>
                </div>
                <span class="jam-level__count">${count}</span>
            </div>`;
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
        const mod   = count >= 30 ? ' jam-hourly__bar--alert' : count >= 15 ? ' jam-hourly__bar--warn' : '';
        return `
            <div class="jam-hourly__bar${mod}" style="height:${pct}%" data-count="${count}">
                <span class="jam-hourly__label">${escapeHtml(fmtClock(h.at))}</span>
            </div>`;
    }).join('');

    const hint = $('[data-jam-hourly-window]');
    if (hint) hint.textContent = `últimas ${byHour.length}h`;
}

// ─────────────────────────────────────────────────────────────────────────
// Render — tabelas (com sort #2, paginação #3, empty state #4, alertas #5)
// ─────────────────────────────────────────────────────────────────────────

function levelCell(j) {
    if (j.isBlocked && j.isStale) return `<span class="jam-table__stale-badge">⚠ ANTIGA</span>`;
    if (j.isBlocked)              return `<span class="jam-table__blocked-badge">🚫 INTERDIÇÃO</span>`;
    return `<span class="jam-table__level jam-table__level--${j.level}">${j.level}</span>`;
}

function delayCell(j) {
    if (j.delay === -1 || j.delay == null) return `<span class="jam-table__dash">—</span>`;
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

// #5 — badge de alertas para todas as tabelas
function alertsBadgeCell(j) {
    const n = Number(j.nearbyAlerts ?? 0);
    if (n <= 0) return `<span class="jam-alerts-badge jam-alerts-badge--zero">—</span>`;
    return `<span class="jam-alerts-badge"><i>🔔</i> ${n}</span>`;
}

/**
 * Renderiza (ou re-renderiza) uma tabela específica.
 * Respeita sort e pageSize do estado.
 *
 * @param {'blocked-active'|'blocked-stale'|'top-jams'} tableKey
 */
function renderTable(tableKey) {
    const tbodyMap = {
        'blocked-active': $('[data-jam-table-blocked-active]'),
        'blocked-stale':  $('[data-jam-table-blocked-stale]'),
        'top-jams':       $('[data-jam-table]'),
    };
    const emptyMsgMap = {
        'blocked-active': 'Nenhuma interdição ativa no momento.',
        'blocked-stale':  'Sem interdições antigas.',
        'top-jams':       null,   // usa empty state contextual (#4)
    };

    const tbody = tbodyMap[tableKey];
    if (!tbody) return;

    const allRows  = sortedRows(tableKey);
    const visible  = allRows.slice(0, state.pageSize[tableKey]);
    const hasMore  = allRows.length > state.pageSize[tableKey];
    const loadMore = $(`[data-jam-load-more="${tableKey}"]`);

    if (loadMore) loadMore.style.display = hasMore ? '' : 'none';

    // Badge de contagem
    const countBadgeMap = {
        'blocked-active': '[data-jam-count="blocked-active"]',
        'blocked-stale':  '[data-jam-count="blocked-stale"]',
        'top-jams':       '[data-jam-count="top-jams"]',
    };
    setText(countBadgeMap[tableKey], fmtNum(allRows.length));

    if (allRows.length === 0) {
        // #4 — empty state contextual
        const staticMsg = emptyMsgMap[tableKey];
        if (staticMsg) {
            tbody.innerHTML = `<tr><td colspan="7" class="jam-empty">${escapeHtml(staticMsg)}</td></tr>`;
        } else {
            // top-jams: mostra quais filtros estão ativos
            const filters  = readFilters();
            const active   = Object.entries(filters)
                .filter(([, v]) => v !== '' && v !== '0' && v !== null && v !== undefined && v !== false)
                .map(([k]) => k.replace('_', ' '));
            const hint     = active.length > 0
                ? `<br><small style="font-size:11px">Filtros ativos: ${escapeHtml(active.join(', '))}</small>
                   <br><button type="button"
                               style="margin-top:8px;font:inherit;font-size:12px;font-weight:700;
                                      color:var(--jam-primary);background:none;border:none;cursor:pointer"
                               onclick="document.querySelectorAll('[data-action=clear-filters]')[0]?.click()">
                       ↩ Limpar filtros
                   </button>`
                : '';
            tbody.innerHTML = `
                <tr>
                    <td colspan="7" class="jam-empty">
                        Sem congestionamentos com os filtros atuais.${hint}
                    </td>
                </tr>`;
        }
        return;
    }

    tbody.innerHTML = visible.map((j) => {
        const url = buildShowUrl(j.id);
        return `
            <tr data-jam-id="${j.id}" data-jam-href="${url}" tabindex="0">
                <td>${levelCell(j)}</td>
                <td>
                    <span class="jam-table__street">${escapeHtml(j.street || '—')}</span>
                    <span class="jam-table__city">${escapeHtml(j.city || '')}</span>
                </td>
                <td class="is-num">${delayCell(j)}</td>
                <td class="is-num">${fmtNum(j.speed, 0)} km/h</td>
                <td class="is-num">${j.lengthKm} km</td>
                <td class="is-num">${alertsBadgeCell(j)}</td>
                <td>
                    ${timeCell(j)}
                    <a class="jam-row-link" href="${url}" aria-label="Detalhes">→</a>
                </td>
            </tr>`;
    }).join('');

    // Delegação de clique (instala uma vez)
    if (tbody.dataset.clickBound !== '1') {
        tbody.dataset.clickBound = '1';
        tbody.addEventListener('click', (e) => {
            if (e.target.closest('a')) return;
            const tr = e.target.closest('tr[data-jam-href]');
            if (tr) window.location.href = tr.dataset.jamHref;
        });
        tbody.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter') return;
            const tr = e.target.closest('tr[data-jam-href]');
            if (tr) window.location.href = tr.dataset.jamHref;
        });
    }

    // Instala sort e load-more (idempotente)
    const tableEl = tbody.closest('table');
    installSortHandlers(tableEl, tableKey);
    installLoadMore(tableKey);
}

function renderAllTables(data) {
    state.rows['blocked-active'] = data.blockedActive || [];
    state.rows['blocked-stale']  = data.blockedStale  || [];
    state.rows['top-jams']       = data.topJams       || [];

    // Reseta paginação a cada fetch de dados novos
    state.pageSize['blocked-active'] = PAGE_SIZE;
    state.pageSize['blocked-stale']  = PAGE_SIZE;
    state.pageSize['top-jams']       = PAGE_SIZE;

    renderTable('blocked-active');
    renderTable('blocked-stale');
    renderTable('top-jams');
}

// ─────────────────────────────────────────────────────────────────────────
// Render — rankings
// ─────────────────────────────────────────────────────────────────────────

function rankItem(r, idx, kind) {
    const isActive = Number(r.blockedActive) > 0;
    const isStale  = !isActive && Number(r.blockedStale) > 0;
    const cls      = isActive ? ' is-blocked' : isStale ? ' is-stale' : '';

    let marks = '';
    if (isActive)              marks += `<span class="mark-blocked">🚫 ${r.blockedActive}</span> · `;
    if (Number(r.blockedStale) > 0) marks += `<span class="mark-stale">⚠ ${r.blockedStale}</span> · `;

    const foot = marks + (kind === 'streets'
        ? (r.maxDelay > 0 ? `máx ${r.maxDelay}s` : '—')
        : (r.avgDelay > 0 ? `média ${r.avgDelay}s` : '—'));

    const name = kind === 'streets'
        ? `${escapeHtml(r.street)}<span class="jam-rank__city">${escapeHtml(r.city)}</span>`
        : escapeHtml(r.city);

    return `
        <li class="${cls}">
            <span class="jam-rank__pos">${idx + 1}</span>
            <span class="jam-rank__name">${name}</span>
            <span class="jam-rank__stats">
                ${r.count}
                <small>${foot}</small>
            </span>
        </li>`;
}

function renderRankStreets(rows) {
    const el = $('[data-jam-rank-streets]');
    if (!el) return;
    el.innerHTML = Array.isArray(rows) && rows.length > 0
        ? rows.map((r, i) => rankItem(r, i, 'streets')).join('')
        : '<li class="jam-empty">Sem dados</li>';
}

function renderRankCities(rows) {
    const el = $('[data-jam-rank-cities]');
    if (!el) return;
    el.innerHTML = Array.isArray(rows) && rows.length > 0
        ? rows.map((r, i) => rankItem(r, i, 'cities')).join('')
        : '<li class="jam-empty">Sem dados</li>';
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
        if (v !== '' && v != null) url.searchParams.set(k, v);
    });

    try {
        const res = await fetch(url.toString(), {
            headers: { Accept: 'application/json' },
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
        // #14 — NÃO atualiza [data-jam-live-clock] aqui; o intervalo cuida disso
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

    state.endpoint       = page.dataset.apiEndpoint;
    state.exportEndpoint = page.dataset.exportEndpoint;
    state.timezone       = page.dataset.timezone || 'America/Sao_Paulo';

    // #13 — lê o template gerado pelo Symfony (URL real, não hardcoded)
    state.showUrlTemplate = page.dataset.showUrlTemplate || '/jams/__ID__';

    if (!state.endpoint) {
        console.warn('[JAM] sem data-api-endpoint');
        return;
    }

    restoreFiltersFromUrl();
    installFilterHandlers();
    installLiveClock();   // #14 — único relógio

    initMap();
    fetchAndRender();

    state.refreshTimer = setInterval(fetchAndRender, REFRESH_MS);

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) fetchAndRender();
    });

    window.addEventListener('beforeunload', () => {
        clearInterval(state.refreshTimer);
        clearInterval(state.clockTimer);
    });
}

export default initJamPage;
