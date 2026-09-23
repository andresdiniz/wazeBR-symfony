/**
 * pages/tv.js — Wallboard /tv (Mercure + SSE + fallback polling)
 */

const POLL_INTERVAL    = 120_000;   // 2 min — só fallback se o Mercure morrer
const ERROR_THRESHOLD  = 3;
const SOUND_COOLDOWN   = 120_000;
const SSE_MAX_FAILURES = 3;
const REFETCH_DEBOUNCE = 400;

const CAM_ROTATION_MS  = 12_000;
const CAM_FADE_MS      = 300;
const CAM_MAX_SLOTS    = 4;

const HYDRO_ROTATION_MS = 8_000;
const HYDRO_SLOTS       = 4;

const state = {
    endpoint: null,
    streamEndpoint: null,
    timezone: 'America/Sao_Paulo',
    failures: 0,
    lastGeneratedAt: null,
    map: null,
    mapLayers: { alerts: null, jams: null },
    soundOn: false,
    lastBeepAt: 0,
    criticalPreviously: false,
    refetchTimer: null,
    cameras: {
        list: [],
        hash: '',
        slots: 0,
        offset: 0,
        timer: null,
        slotEls: [],
    },
    wakeLock: null,
};

let eventSource   = null;
let pollTimer     = null;
let sseFailures   = 0;
let usingFallback = false;

let hydroTimer = null;
let hydroPage  = 0;

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

function fmtDateTime(iso) {
    if (!iso) return { date: '—', time: '—' };
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return { date: '—', time: '—' };
    try {
        const dateFmt = new Intl.DateTimeFormat('pt-BR', {
            day: '2-digit', month: '2-digit', year: 'numeric',
            timeZone: state.timezone,
        });
        const timeFmt = new Intl.DateTimeFormat('pt-BR', {
            hour: '2-digit', minute: '2-digit', hour12: false,
            timeZone: state.timezone,
        });
        return { date: dateFmt.format(d), time: timeFmt.format(d) };
    } catch {
        return { date: '—', time: '—' };
    }
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

function fmtDateShort(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '—';
    try {
        return new Intl.DateTimeFormat('pt-BR', {
            day: '2-digit', month: '2-digit',
            timeZone: state.timezone,
        }).format(d);
    } catch { return '—'; }
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

function dedupe(items, keyFn) {
    if (!Array.isArray(items)) return [];
    const seen = new Set();
    const out  = [];
    for (const item of items) {
        const key = keyFn(item);
        if (key && seen.has(key)) continue;
        if (key) seen.add(key);
        out.push(item);
    }
    return out;
}

function alertKey(a) {
    return a.uuid ?? a.id ?? `${a.type}|${a.street ?? ''}|${a.city ?? ''}|${a.when ?? ''}`;
}
function feedKey(e) {
    return e.uuid ?? e.id ?? `${e.kind}|${e.type ?? ''}|${e.street ?? ''}|${e.city ?? ''}|${e.when ?? ''}`;
}

// ─────────────────────────────────────────────────────────────────────────
// Relógio
// ─────────────────────────────────────────────────────────────────────────

function initClock() {
    const clock = $('[data-tv-clock]');
    const dateEl = $('[data-tv-date]');
    if (!clock || !dateEl) return;

    const timeFmt = new Intl.DateTimeFormat('pt-BR', {
        hour: '2-digit', minute: '2-digit', second: '2-digit',
        hour12: false, timeZone: state.timezone,
    });
    const dateFmt = new Intl.DateTimeFormat('pt-BR', {
        weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric',
        timeZone: state.timezone,
    });

    const tick = () => {
        const now = new Date();
        clock.textContent  = timeFmt.format(now);
        dateEl.textContent = dateFmt.format(now);
    };
    tick();
    setInterval(tick, 1000);
}

// ─────────────────────────────────────────────────────────────────────────
// Mapa
// ─────────────────────────────────────────────────────────────────────────

function initMap() {
    const container = $('[data-tv-map]');
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
            zoomControl: false,
            attributionControl: true,
            preferCanvas: true,
            center: [-20.66, -43.78],
            zoom: 12,
        });

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap',
        }).addTo(state.map);

        state.mapLayers.alerts = L.layerGroup().addTo(state.map);
        state.mapLayers.jams   = L.layerGroup().addTo(state.map);

        setTimeout(() => state.map && state.map.invalidateSize(), 100);
        setTimeout(() => state.map && state.map.invalidateSize(), 500);
        window.addEventListener('resize', () => state.map && state.map.invalidateSize());
    } catch (err) {
        console.error('[TV] mapa falhou', err);
    }
}

function renderMap(mapData) {
    if (!state.map || !mapData) return;

    state.mapLayers.alerts.clearLayers();
    state.mapLayers.jams.clearLayers();

    (mapData.jams || []).forEach((j) => {
        if (!Array.isArray(j.path) || j.path.length < 2) return;
        const color = j.level >= 5 ? '#dc2626' : j.level >= 4 ? '#ef4444' : '#f97316';
        L.polyline(j.path, {
            color, weight: 6, opacity: 0.85,
            lineCap: 'round', lineJoin: 'round',
        }).addTo(state.mapLayers.jams);
    });

    (mapData.alerts || []).forEach((a) => {
        if (!Number.isFinite(a.lat) || !Number.isFinite(a.lng)) return;
        L.circleMarker([a.lat, a.lng], {
            radius: 7, color: '#ffffff', weight: 2,
            fillColor: alertColor(a.type), fillOpacity: 0.95,
        }).addTo(state.mapLayers.alerts);
    });

    const badge = $('[data-tv-map-badge]');
    if (mapData.center && mapData.center.hasData) {
        try {
            state.map.setView([mapData.center.lat, mapData.center.lng], mapData.center.zoom, { animate: false });
        } catch {}
        if (badge) badge.hidden = false;
    } else {
        if (badge) badge.hidden = true;
    }
}

function alertColor(type) {
    switch (String(type || '').toUpperCase()) {
        case 'ACCIDENT':             return '#ef4444';
        case 'JAM':                  return '#f97316';
        case 'ROAD_CLOSED':          return '#7c3aed';
        case 'POLICE':               return '#3b82f6';
        case 'WEATHERHAZARD':        return '#0891b2';
        case 'HAZARD':               return '#f59e0b';
        case 'HAZARD_WEATHER_FLOOD': return '#06b6d4';
        case 'CONSTRUCTION':         return '#a855f7';
        default:                     return '#94a3b8';
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Render
// ─────────────────────────────────────────────────────────────────────────

function applyStatus(status) {
    const wrap   = $('[data-tv-status]');
    const label  = $('[data-tv-status-label]');
    const reason = $('[data-tv-status-reason]');
    const wb     = document.querySelector('.tv-wallboard');
    if (!wrap || !label) return;

    wrap.classList.remove('is-normal', 'is-attention', 'is-critical');
    wb.classList.remove('is-normal', 'is-attention', 'is-critical');

    const level = status.level || 'normal';
    wrap.classList.add(`is-${level}`);
    wb.classList.add(`is-${level}`);

    label.textContent = status.label || '—';
    if (reason) {
        reason.textContent = (status.reasons && status.reasons.length)
            ? status.reasons.join(' · ') : '';
    }

    if (level === 'critical' && !state.criticalPreviously) beep();
    state.criticalPreviously = level === 'critical';
}

/**
 * Sparkline 6h — barras verticais.
 * Cor muda conforme volume: azul (normal), amarelo (≥3), vermelho (≥6).
 */
function renderSparkline(byHour) {
    const wrap = $('[data-tv-spark]');
    const bars = $('[data-tv-spark-bars]');
    if (!wrap || !bars) return;

    if (!Array.isArray(byHour) || byHour.length === 0) {
        wrap.hidden = true;
        bars.innerHTML = '';
        return;
    }

    const max = Math.max(1, ...byHour.map((h) => Number(h.count) || 0));

    bars.innerHTML = byHour.map((h) => {
        const count = Number(h.count) || 0;
        const pct   = max > 0 ? Math.max(2, Math.round((count / max) * 100)) : 2;
        const mod   = count >= 6 ? ' tv-spark__bar--alert'
                    : count >= 3 ? ' tv-spark__bar--warn'
                    : '';
        const title = `${count} alerta${count === 1 ? '' : 's'} às ${fmtClock(h.at)}`;
        return `<div class="tv-spark__bar${mod}"
                     style="height:${pct}%"
                     data-count="${count}"
                     title="${escapeHtml(title)}"></div>`;
    }).join('');

    wrap.hidden = false;
}

/**
 * Linha "último crítico há X" no card de Alertas.
 * Fica quieta (cinza) se foi há mais de 2h.
 */
function renderLastCritical(alerts) {
    const wrap = $('[data-tv-last-critical]');
    const text = $('[data-tv-last-critical-text]');
    if (!wrap || !text) return;

    const iso = alerts.lastCriticalAt;
    if (!iso) {
        wrap.hidden = true;
        return;
    }

    const ageMs  = Date.now() - new Date(iso).getTime();
    const ageMin = ageMs / 60000;

    let label;
    if (ageMin < 1)       label = 'agora';
    else if (ageMin < 60) label = `há ${Math.floor(ageMin)} min`;
    else {
        const h = Math.floor(ageMin / 60);
        label = `há ${h}h`;
    }

    text.textContent = `Último crítico ${label} · ${fmtClock(iso)}`;
    wrap.classList.toggle('is-quiet', ageMin > 120);
    wrap.hidden = false;
}

/**
 * Widget "Top vias · 2h" — lista compacta com rank.
 */
function renderTopStreets(topStreets) {
    const card = $('[data-tv-top-streets-card]');
    const list = $('[data-tv-top-streets]');
    if (!card || !list) return;

    if (!Array.isArray(topStreets) || topStreets.length === 0) {
        card.hidden = true;
        list.innerHTML = '';
        return;
    }

    list.innerHTML = topStreets.slice(0, 5).map((s, i) => {
        const name = escapeHtml(s.street || '—');
        const city = s.city ? `<span class="tv-streets__city">${escapeHtml(s.city)}</span>` : '';
        return `
            <li>
                <span class="tv-streets__rank">${i + 1}</span>
                <span class="tv-streets__name">${name}${city}</span>
                <span class="tv-streets__count">${fmtNum(s.count)}</span>
            </li>
        `;
    }).join('');

    card.hidden = false;
}

function renderAlerts(alerts) {
    setText('[data-tv-total="alerts"]',   fmtNum(alerts.total));
    setText('[data-tv-counter="alerts"]', fmtNum(alerts.total));

    const hint = $('[data-tv-alerts-hint]');
    if (hint) {
        const crit2h = alerts.last2h?.critical ?? 0;
        hint.textContent = crit2h > 0
            ? `${crit2h} crítico${crit2h > 1 ? 's' : ''} nas últimas 2h`
            : 'Nenhum crítico nas últimas 2h';
        hint.style.color = crit2h > 0 ? '#f87171' : '';
    }

    setText('[data-tv-type="ACCIDENT"]',             fmtNum(alerts.byType.ACCIDENT ?? 0));
    setText('[data-tv-type="ROAD_CLOSED"]',          fmtNum(alerts.byType.ROAD_CLOSED ?? 0));
    setText('[data-tv-type="HAZARD_WEATHER_FLOOD"]', fmtNum(alerts.byType.HAZARD_WEATHER_FLOOD ?? 0));
    setText('[data-tv-type="WEATHERHAZARD"]',        fmtNum(alerts.byType.WEATHERHAZARD ?? 0));

    renderSparkline(alerts.byHour);
    renderLastCritical(alerts);

    const list = $('[data-tv-top-alerts]');
    if (!list) return;

    const recent = dedupe(alerts.recent || [], alertKey);
    if (recent.length === 0) {
        list.innerHTML = '<li class="tv-empty-row">Sem ocorrências recentes</li>';
        return;
    }

    list.innerHTML = recent.map((a) => {
        const local = a.street || a.city || 'Local não informado';
        const dt = fmtDateTime(a.when);
        return `
            <li>
                <time class="tv-list__when" datetime="${escapeHtml(a.when ?? '')}">
                    <span class="tv-list__date">${escapeHtml(dt.date)}</span>
                    <span class="tv-list__hour">${escapeHtml(dt.time)}</span>
                </time>
                <div>
                    <strong>${escapeHtml(a.typeLabel || a.type || 'Alerta')}</strong>
                    <small>${escapeHtml(local)}</small>
                </div>
            </li>
        `;
    }).join('');
}

function renderJams(jams) {
    setText('[data-tv-total="jams"]',   fmtNum(jams.total));
    setText('[data-tv-counter="jams"]', fmtNum(jams.total));
    setText('[data-tv-jams="level3"]',  fmtNum(jams.level3plus));
    setText('[data-tv-jams="level4"]',  fmtNum(jams.level4plus));

    const avg = $('[data-tv-jams="avgDelay"]');
    if (avg) avg.textContent = jams.avgDelay > 0 ? `${jams.avgDelay}s` : '—';

    const hint = $('[data-tv-jams-hint]');
    if (hint) {
        hint.textContent = jams.level4plus > 0
            ? `${jams.level4plus} em nível alto ou superior`
            : jams.avgDelay > 120
                ? 'Atraso médio elevado'
                : 'Operação estável';
        hint.style.color = jams.level4plus > 0 ? '#fb923c' : '';
    }

    const sub = $('[data-tv-jam-sub]');
    if (sub) sub.textContent = 'ativos';
}

function renderFeed(feed) {
    const list = $('[data-tv-feed]');
    const updated = $('[data-tv-feed-updated]');
    if (!list) return;

    if (updated) {
        updated.textContent = new Intl.DateTimeFormat('pt-BR', {
            hour: '2-digit', minute: '2-digit', second: '2-digit',
            hour12: false, timeZone: state.timezone,
        }).format(new Date());
    }

    const items = dedupe(feed || [], feedKey);
    if (items.length === 0) {
        list.innerHTML = '<li class="tv-empty-row">Sem eventos recentes</li>';
        return;
    }

    list.innerHTML = items.slice(0, 6).map((e) => {
        const isAlert = e.kind === 'alert';
        const icon  = isAlert ? iconForAlert(e.type) : '≋';
        const label = isAlert ? (e.typeLabel || e.type) : 'Congestionamento';
        const local = [e.street, e.city].filter(Boolean).join(' · ') || 'Local não informado';
        const extra = !isAlert && e.level ? `Nível ${e.level} · +${e.delay ?? 0}s` : '';
        const dt = fmtDateTime(e.when);

        return `
            <li class="tv-feed__row tv-feed__row--${isAlert ? 'alert' : 'jam'}">
                <time class="tv-feed__when" datetime="${escapeHtml(e.when ?? '')}">
                    <span class="tv-feed__date">${escapeHtml(dt.date)}</span>
                    <span class="tv-feed__hour">${escapeHtml(dt.time)}</span>
                </time>
                <span class="tv-feed__icon">${icon}</span>
                <div class="tv-feed__body">
                    <strong>${escapeHtml(label)}</strong>
                    <small>${escapeHtml(local)}${extra ? ' · ' + escapeHtml(extra) : ''}</small>
                </div>
            </li>
        `;
    }).join('');
}

function iconForAlert(type) {
    switch (String(type || '').toUpperCase()) {
        case 'ACCIDENT':             return '✕';
        case 'ROAD_CLOSED':          return '⊘';
        case 'HAZARD_WEATHER_FLOOD': return '🌊';
        case 'WEATHERHAZARD':        return '☂';
        case 'HAZARD':               return '▲';
        case 'POLICE':               return '◉';
        case 'JAM':                  return '≋';
        default:                     return '⚠';
    }
}

function renderHydro(hydro) {
    const wrap    = $('[data-tv-hydro]');
    const pagesEl = $('[data-tv-hydro-pages]');
    const badge   = $('[data-tv-hydro-badge]');

    setText('[data-tv-counter="hydro"]', fmtNum(hydro.stations?.length ?? 0));

    if (badge) {
        const atRisk = hydro.atRisk || 0;
        badge.textContent = String(atRisk);
        badge.classList.remove('is-warning', 'is-critical');
        if (hydro.risk.overflow > 0 || hydro.risk.alert > 0) badge.classList.add('is-critical');
        else if (atRisk > 0) badge.classList.add('is-warning');
    }

    if (!wrap) return;

    if (hydroTimer) { clearInterval(hydroTimer); hydroTimer = null; }

    const all = Array.isArray(hydro.stations) ? hydro.stations : [];

    if (all.length === 0) {
        wrap.innerHTML = '<div class="tv-empty-row">Sem estações cadastradas</div>';
        if (pagesEl) pagesEl.hidden = true;
        return;
    }

    // Tudo cabe → sem rotação
    if (all.length <= HYDRO_SLOTS) {
        wrap.innerHTML = all.map(renderHydroItem).join('');
        if (pagesEl) pagesEl.hidden = true;
        return;
    }

    // Rotação: pin críticas + rotate resto
    const isCritical = (s) => s.risk === 'overflow' || s.risk === 'alert';
    const pinned   = all.filter(isCritical);
    const rotating = all.filter((s) => !isCritical(s));

    const pinnedSlots = Math.min(pinned.length, HYDRO_SLOTS - 1);
    const rotSlots    = Math.max(1, HYDRO_SLOTS - pinnedSlots);
    const totalPages  = Math.max(1, Math.ceil(rotating.length / rotSlots));

    const renderPage = (page) => {
        const start = page * rotSlots;
        const visiblePinned   = pinned.slice(0, pinnedSlots);
        const visibleRotating = rotating.slice(start, start + rotSlots);

        wrap.style.opacity = '0.4';
        requestAnimationFrame(() => {
            wrap.innerHTML =
                visiblePinned.map(renderHydroItem).join('') +
                visibleRotating.map(renderHydroItem).join('');
            wrap.style.opacity = '1';
        });

        if (pagesEl) {
            if (totalPages > 1) {
                pagesEl.hidden = false;
                pagesEl.innerHTML = Array.from({ length: totalPages }, (_, i) =>
                    `<span class="tv-hydro__page${i === page ? ' is-active' : ''}"></span>`
                ).join('');
            } else {
                pagesEl.hidden = true;
            }
        }
    };

    renderPage(0);
    hydroPage = 0;

    if (totalPages > 1) {
        hydroTimer = setInterval(() => {
            if (document.hidden) return;
            hydroPage = (hydroPage + 1) % totalPages;
            renderPage(hydroPage);
        }, HYDRO_ROTATION_MS);
    }
}

function renderHydroItem(s) {
    const riskLabel = {
        overflow: 'Transbordo', alert: 'Alerta', attention: 'Atenção',
        normal: 'Normal', unknown: 'Sem dados',
    }[s.risk] || '—';

    const progress = (s.progress !== null && s.progress !== undefined)
        ? Math.min(100, s.progress) : 0;

    const updated = s.observedAt
        ? `<span class="tv-hydro__updated">${escapeHtml(fmtRelative(s.observedAt))}</span>`
        : '';

    return `
        <div class="tv-hydro__item tv-hydro__item--${s.risk}">
            <div class="tv-hydro__head">
                <span class="tv-hydro__name">${escapeHtml(s.name)}</span>
                ${updated}
                <span class="tv-hydro__risk tv-hydro__risk--${s.risk}">${riskLabel}</span>
            </div>
            <div class="tv-hydro__value">
                <strong>${s.level !== null ? fmtNum(s.level, 2) : '—'}</strong>
                <span>m</span>
            </div>
            ${s.transbordo !== null ? `
                <div class="tv-hydro__bar"><span style="width:${progress}%"></span></div>
            ` : ''}
        </div>
    `;
}

/**
 * Rotas mais lentas vs. histórico de 30 dias.
 *
 * O backend calcula `slownessPct = (currentTime - historicTime) / historicTime * 100`:
 *   positivo → mais lento que o habitual
 *   negativo → mais rápido
 *
 * Fallback: se nenhuma rota passou do threshold (10%), o backend
 * devolve as 3 com maior tempo absoluto — sinalizamos com o sufixo "(atual)".
 */
function renderRoutes(routes) {
    const card  = $('[data-tv-routes-card]');
    const list  = $('[data-tv-routes]');
    const badge = $('[data-tv-routes-badge]');
    const note  = $('[data-tv-routes-note]');
    if (!card || !list) return;

    const items      = Array.isArray(routes?.items) ? routes.items : [];
    const total      = Number(routes?.total) || items.length;
    const isFallback = Boolean(routes?.isFallback);
    const basedDays  = Number(routes?.basedOnDays) || 30;

    if (items.length === 0) {
        card.hidden = true;
        return;
    }

    if (badge) badge.textContent = String(total);

    list.innerHTML = items.map((r) => {
        const name = escapeHtml(r.name || '—');

        // Linha de meta: tempo atual vs. histórico, com seta
        const curT  = r.currentTime !== null && r.currentTime !== undefined
            ? `${Math.round(r.currentTime / 60)} min` : '—';
        const histT = r.historicTime !== null && r.historicTime !== undefined
            ? `${Math.round(r.historicTime / 60)} min` : '—';
        const meta  = `${curT}<span class="tv-routes__meta-arrow">vs</span>${histT}`;

        // Pct class + label
        let pctClass = 'tv-routes__pct--flat';
        let pctLabel = '—';

        const pct = r.slownessPct;

        if (pct !== null && pct !== undefined) {
            const v = Number(pct);
            if (v >= 30)       { pctClass = 'tv-routes__pct--slower'; pctLabel = `▼ ${fmtNum(v, 0)}%`; }
            else if (v >= 10)  { pctClass = 'tv-routes__pct--slow';   pctLabel = `▼ ${fmtNum(v, 0)}%`; }
            else if (v <= -10) { pctClass = 'tv-routes__pct--fast';   pctLabel = `▲ ${fmtNum(Math.abs(v), 0)}%`; }
            else               { pctClass = 'tv-routes__pct--flat';   pctLabel = `${fmtNum(v, 0)}%`; }
        } else if (isFallback && r.currentTime !== null) {
            // Sem histórico → mostra tempo absoluto
            pctClass = 'tv-routes__pct--flat';
            pctLabel = `${Math.round(r.currentTime / 60)}min`;
        }

        const jam = Number.isFinite(r.jamLevel) && r.jamLevel > 0
            ? ` · jam ${r.jamLevel}` : '';

        return `
            <li>
                <span class="tv-routes__info">
                    <span class="tv-routes__name">${name}</span>
                    <span class="tv-routes__meta">${meta}${escapeHtml(jam)}</span>
                </span>
                <span class="tv-routes__pct ${pctClass}">${escapeHtml(pctLabel)}</span>
            </li>
        `;
    }).join('');

    card.hidden = false;
    if (note) {
        note.textContent = isFallback
            ? 'tempos atuais · sem histórico recente'
            : `vs. média ${basedDays} dias`;
        note.hidden = false;
    }
}

function renderRain(rain) {
    setText('[data-tv-rain="lastHour"]', fmtNum(rain.lastHour ?? 0, 1));
    setText('[data-tv-rain="last24h"]',  fmtNum(rain.last24h ?? 0, 1));
    setText('[data-tv-rain="peak24h"]',  fmtNum(rain.peak24h ?? 0, 1));

    const top = $('[data-tv-rain-top]');
    if (top) {
        if (rain.topStation) {
            const city = rain.topStation.city
                ? ` · ${rain.topStation.city}${rain.topStation.state ? '/' + rain.topStation.state : ''}`
                : '';
            top.textContent = `Maior: ${rain.topStation.name}${city} — ${fmtNum(rain.topStation.rain24h, 1)} mm`;
        } else {
            top.textContent = 'Sem leituras recentes';
        }
    }
}

function renderWeather(w) {
    if (!w) {
        setText('[data-tv-weather="temperature"]', '—');
        setText('[data-tv-weather="humidity"]', '—');
        setText('[data-tv-weather="wind"]', '—');
        return;
    }

    setText('[data-tv-weather="temperature"]', w.temperature !== null ? fmtNum(w.temperature, 1) : '—');
    setText('[data-tv-weather="humidity"]',    w.humidity    !== null ? `${w.humidity}%`     : '—');
    setText('[data-tv-weather="wind"]',        w.windSpeed   !== null ? `${fmtNum(w.windSpeed, 0)} km/h` : '—');

    const station = $('[data-tv-weather-station]');
    if (station) {
        const parts = [];
        if (w.stationName) parts.push(w.stationName);
        if (w.city) parts.push(`${w.city}${w.state ? '/' + w.state : ''}`);
        if (w.observedAt) parts.push(fmtRelative(w.observedAt));
        station.textContent = parts.join(' · ') || '—';
    }
}

function renderToday(today) {
    setText('[data-tv-today="alerts"]',     fmtNum(today.alerts));
    setText('[data-tv-today="accidents"]',  fmtNum(today.accidents));
    setText('[data-tv-today="roadClosed"]', fmtNum(today.roadClosed));
    setText('[data-tv-today="floods"]',     fmtNum(today.floods));
    setText('[data-tv-today="jams"]',       fmtNum(today.jams));

    const trend = $('[data-tv-today="trend"]');
    if (trend) {
        trend.classList.remove('is-up', 'is-down', 'is-flat');
        if (today.trend === null || today.trend === undefined) {
            trend.textContent = '—'; trend.classList.add('is-flat');
        } else if (today.trend > 0) {
            trend.textContent = `▲ ${fmtNum(today.trend, 1)}%`; trend.classList.add('is-up');
        } else if (today.trend < 0) {
            trend.textContent = `▼ ${fmtNum(Math.abs(today.trend), 1)}%`; trend.classList.add('is-down');
        } else {
            trend.textContent = '0%'; trend.classList.add('is-flat');
        }
    }
}

function renderFresh(fetch) {
    const now = Date.now();
    const map = {
        alerts: fetch.alerts, jams: fetch.jams, weather: fetch.weather,
        hydro: fetch.hydro, pluvio: fetch.pluvio,
        tvt: fetch.tvt,
    };

    Object.entries(map).forEach(([key, iso]) => {
        const el = $(`[data-tv-fresh="${key}"]`)?.parentElement;
        if (!el) return;
        el.classList.remove('is-fresh', 'is-stale', 'is-old');
        if (!iso) { el.classList.add('is-old'); return; }
        const min = (now - new Date(iso).getTime()) / 60000;
        if (min < 10)      el.classList.add('is-fresh');
        else if (min < 60) el.classList.add('is-stale');
        else               el.classList.add('is-old');
    });
}

function renderUpdated(generatedAt) {
    const el = $('[data-tv-updated]');
    if (el) el.textContent = fmtRelative(generatedAt);
}

function renderTicker(feed) {
    const track = $('[data-tv-ticker-track]');
    if (!track) return;

    const items = dedupe(feed || [], feedKey);
    if (items.length === 0) {
        track.innerHTML = '<span>Sem eventos recentes</span>';
        return;
    }

    const html = items.slice(0, 8).map((e) => {
        const isAlert = e.kind === 'alert';
        const label = isAlert ? (e.typeLabel || e.type) : `Congestionamento nível ${e.level}`;
        const local = [e.street, e.city].filter(Boolean).join(' · ') || 'Local não informado';
        const stamp = `${fmtDateShort(e.when)} ${fmtClock(e.when)}`;
        return `<span><strong>${escapeHtml(stamp)}</strong>${escapeHtml(label)} · ${escapeHtml(local)}</span>`;
    }).join('');

    track.innerHTML = html + html;
}

// ─────────────────────────────────────────────────────────────────────────
// Câmeras
// ─────────────────────────────────────────────────────────────────────────

function renderCameras(list) {
    const wrap      = $('[data-tv-cams]');
    const grid      = $('[data-tv-cams-grid]');
    const badge     = $('[data-tv-cams-badge]');
    const badgeText = $('[data-tv-cams-badge-text]');
    if (!wrap || !grid) return;

    const safeList = Array.isArray(list) ? list : [];
    const hash = safeList.map((c) => c.id).join(',');

    if (hash === state.cameras.hash && state.cameras.slotEls.length > 0) {
        if (badgeText) {
            badgeText.textContent = `${safeList.length} câmera${safeList.length !== 1 ? 's' : ''}`;
        }
        return;
    }

    state.cameras.hash   = hash;
    state.cameras.list   = safeList;
    state.cameras.offset = 0;

    if (state.cameras.timer) {
        clearInterval(state.cameras.timer);
        state.cameras.timer = null;
    }

    if (safeList.length === 0) {
        state.cameras.slotEls.forEach(destroySlot);
        wrap.hidden = true;
        grid.innerHTML = '';
        state.cameras.slotEls = [];
        state.cameras.slots = 0;
        return;
    }

    const slots = Math.min(safeList.length, CAM_MAX_SLOTS);
    state.cameras.slots = slots;

    grid.style.setProperty('--cam-slots', String(slots));
    grid.innerHTML = '';

    const slotEls = [];
    for (let i = 0; i < slots; i++) {
        const slot = document.createElement('div');
        slot.className = 'tv-cam';
        slot.innerHTML = `
            <div class="tv-cam__overlay">
                <span class="tv-cam__name" data-cam-name>—</span>
                <span class="tv-cam__city" data-cam-city></span>
            </div>
        `;
        grid.appendChild(slot);
        slotEls.push(slot);
    }
    state.cameras.slotEls = slotEls;

    wrap.hidden = false;
    if (badge) badge.hidden = false;
    if (badgeText) {
        badgeText.textContent = `${safeList.length} câmera${safeList.length !== 1 ? 's' : ''}`;
    }

    paintCamSlots(0);

    if (safeList.length > slots) {
        state.cameras.timer = setInterval(() => {
            if (document.hidden) return;
            state.cameras.offset = (state.cameras.offset + slots) % state.cameras.list.length;
            paintCamSlots(state.cameras.offset);
        }, CAM_ROTATION_MS);
    }
}

function paintCamSlots(offset) {
    const { list, slots, slotEls } = state.cameras;
    if (list.length === 0 || slots === 0) return;

    for (let i = 0; i < slots; i++) {
        const camera = list[(offset + i) % list.length];
        updateCamSlot(slotEls[i], camera);
    }
}

function updateCamSlot(slotEl, camera) {
    if (!slotEl || !camera) return;
    if (slotEl.dataset.currentUrl === camera.url) return;

    destroySlot(slotEl);
    slotEl.dataset.currentUrl = camera.url;

    const nameEl = slotEl.querySelector('[data-cam-name]');
    const cityEl = slotEl.querySelector('[data-cam-city]');
    if (nameEl) nameEl.textContent = camera.name || 'Câmera';
    if (cityEl) cityEl.textContent = [camera.city, camera.state].filter(Boolean).join('/') || '';

    const type = String(camera.urlType || '').toLowerCase();
    if (type === 'hls' && typeof Hls !== 'undefined' && Hls.isSupported()) {
        mountHls(slotEl, camera);
        return;
    }
    if (type === 'iframe' || type === 'embed') {
        mountIframe(slotEl, camera);
        return;
    }
    mountImage(slotEl, camera);
}

function destroySlot(slotEl) {
    if (!slotEl) return;

    const video = slotEl.querySelector('video');
    if (video) {
        if (video._hls) { try { video._hls.destroy(); } catch {} video._hls = null; }
        try { video.pause(); } catch {}
        video.removeAttribute('src');
        try { video.load(); } catch {}
        video.remove();
    }

    slotEl.querySelectorAll('img, iframe').forEach((el) => el.remove());
    slotEl.classList.remove('is-loading', 'is-streaming', 'is-error');
    delete slotEl.dataset.currentUrl;
}

function mountHls(slotEl, camera) {
    slotEl.classList.add('is-loading');

    const video = document.createElement('video');
    video.muted = true;
    video.autoplay = true;
    video.playsInline = true;
    video.setAttribute('playsinline', '');
    video.setAttribute('webkit-playsinline', '');
    video.preload = 'auto';

    const overlay = slotEl.querySelector('.tv-cam__overlay');
    if (overlay) slotEl.insertBefore(video, overlay);
    else slotEl.appendChild(video);

    const hls = new Hls({
        enableWorker: false,
        lowLatencyMode: false,
        liveSyncDurationCount: 3,
        manifestLoadingTimeOut: 15000,
        manifestLoadingMaxRetry: 4,
        manifestLoadingRetryDelay: 1000,
        fragLoadingTimeOut: 15000,
        fragLoadingMaxRetry: 5,
        fragLoadingRetryDelay: 1000,
        capLevelToPlayerSize: true,
        startLevel: -1,

        // 🔑 CRÍTICO: envia cookies de sessão nas requisições XHR do hls.js.
        // Sem isso, a rota /tv/camera/{id}/hls recebe 302 → /login e o
        // player nunca carrega.
        xhrSetup: (xhr) => {
            xhr.withCredentials = true;
        },
    });

    hls.on(Hls.Events.MANIFEST_PARSED, () => {
        slotEl.classList.add('is-streaming');
        video.play().catch(() => {});
    });

    const onReady = () => {
        slotEl.classList.remove('is-loading');
        slotEl.classList.add('is-streaming');
        video.classList.add('is-ready');
    };
    video.addEventListener('canplay', onReady, { once: true });

    const failTimer = setTimeout(() => {
        if (video.classList.contains('is-ready')) return;
        slotEl.classList.remove('is-loading', 'is-streaming');
        slotEl.classList.add('is-error');
        try { hls.destroy(); } catch {}
    }, 15_000);
    video.addEventListener('canplay', () => clearTimeout(failTimer), { once: true });

    hls.on(Hls.Events.ERROR, (_evt, data) => {
        if (!data || !data.fatal) return;
        console.warn('[TV] HLS fatal:', data.type, data.details, camera.url);
        clearTimeout(failTimer);
        slotEl.classList.remove('is-loading', 'is-streaming');
        slotEl.classList.add('is-error');
        try { hls.destroy(); } catch {}
    });

    video._hls = hls;
    hls.loadSource(camera.url);
    hls.attachMedia(video);
}

function mountIframe(slotEl, camera) {
    slotEl.classList.remove('is-loading', 'is-streaming', 'is-error');

    const iframe = document.createElement('iframe');
    iframe.src = camera.url;
    iframe.dataset.src = camera.url;
    iframe.setAttribute('sandbox', 'allow-scripts allow-same-origin');
    iframe.setAttribute('loading', 'lazy');
    iframe.setAttribute('referrerpolicy', 'no-referrer');
    iframe.setAttribute('allow', 'autoplay; encrypted-media');

    const overlay = slotEl.querySelector('.tv-cam__overlay');
    if (overlay) slotEl.insertBefore(iframe, overlay);
    else slotEl.appendChild(iframe);
}

function mountImage(slotEl, camera) {
    slotEl.classList.remove('is-error', 'is-streaming');
    slotEl.classList.add('is-loading');

    const img = document.createElement('img');
    img.alt = '';
    img.loading = 'eager';
    img.decoding = 'async';
    img.style.opacity = '0';

    const overlay = slotEl.querySelector('.tv-cam__overlay');
    if (overlay) slotEl.insertBefore(img, overlay);
    else slotEl.appendChild(img);

    setTimeout(() => {
        const bust = '_tv=' + Date.now();
        img.src = camera.url + (camera.url.includes('?') ? '&' : '?') + bust;

        img.onload = () => {
            slotEl.classList.remove('is-loading');
            img.style.opacity = '1';
        };
        img.onerror = () => {
            slotEl.classList.remove('is-loading');
            slotEl.classList.add('is-error');
            img.style.opacity = '1';
        };
    }, CAM_FADE_MS / 2);
}

// ─────────────────────────────────────────────────────────────────────────
// Wake Lock
// ─────────────────────────────────────────────────────────────────────────

async function acquireWakeLock() {
    if (!('wakeLock' in navigator)) return;
    if (state.wakeLock && !state.wakeLock.released) return;

    try {
        state.wakeLock = await navigator.wakeLock.request('screen');
        state.wakeLock.addEventListener('release', () => { state.wakeLock = null; });
    } catch {}
}

function installWakeLock() {
    acquireWakeLock();

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) acquireWakeLock();
    });
    window.addEventListener('focus', acquireWakeLock);
    window.addEventListener('click', acquireWakeLock, { passive: true });
    window.addEventListener('keydown', acquireWakeLock, { passive: true });

    setInterval(() => {
        if (!document.hidden) acquireWakeLock();
    }, 5 * 60 * 1000);
}

// ─────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────

function setText(selector, value) {
    const el = $(selector);
    if (el) el.textContent = value;
}

function showOverlay(msg) {
    const overlay = $('[data-tv-overlay]');
    const msgEl = $('[data-tv-overlay-msg]');
    if (overlay) overlay.hidden = false;
    if (msgEl && msg) msgEl.textContent = msg;
}

function hideOverlay() {
    const overlay = $('[data-tv-overlay]');
    if (overlay) overlay.hidden = true;
}

// ─────────────────────────────────────────────────────────────────────────
// Som
// ─────────────────────────────────────────────────────────────────────────

let audioCtx = null;

function beep() {
    if (!state.soundOn) return;
    const now = Date.now();
    if (now - state.lastBeepAt < SOUND_COOLDOWN) return;
    state.lastBeepAt = now;

    try {
        if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = audioCtx.createOscillator();
        const gain = audioCtx.createGain();
        osc.type = 'sine';
        osc.frequency.value = 880;
        gain.gain.value = 0.08;
        osc.connect(gain);
        gain.connect(audioCtx.destination);
        osc.start();
        osc.stop(audioCtx.currentTime + 0.35);
    } catch {}
}

// ─────────────────────────────────────────────────────────────────────────
// Aplica payload + fetch
// ─────────────────────────────────────────────────────────────────────────

function applyPayload(data) {
    if (!data) return;

    state.lastGeneratedAt = data.generatedAt;
    state.failures = 0;

    applyStatus(data.status);
    renderAlerts(data.alerts);
    renderTopStreets(data.alerts?.topStreets);
    renderJams(data.jams);
    renderFeed(data.feed);
    renderMap(data.map);
    renderHydro(data.hydro);
    renderRoutes(data.routes);
    renderRain(data.rain);
    renderWeather(data.weather);
    renderToday(data.today);
    renderFresh(data.fetch);
    renderUpdated(data.generatedAt);
    renderTicker(data.feed);
    renderCameras(data.cameras || []);
}

async function fetchData() {
    if (!state.endpoint) return;

    try {
        const res = await fetch(state.endpoint, {
            headers: { 'Accept': 'application/json' },
            cache: 'no-store',
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const json = await res.json();
        if (!json || !json.data) throw new Error('Payload inválido');

        hideOverlay();
        applyPayload(json.data);
    } catch (err) {
        state.failures++;
        console.warn('[TV] fetch falhou', state.failures, err);
        if (state.failures >= ERROR_THRESHOLD) {
            showOverlay(`Tentando novamente… (${state.failures} falhas)`);
        }
    }
}

function startPolling() {
    if (pollTimer) return;
    usingFallback = true;
    fetchData();
    pollTimer = setInterval(fetchData, POLL_INTERVAL);
}

function stopPolling() {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

function scheduleRefetch() {
    clearTimeout(state.refetchTimer);
    state.refetchTimer = setTimeout(fetchData, REFETCH_DEBOUNCE);
}

async function startRealtime() {
    if (eventSource) return;

    if (typeof EventSource === 'undefined') {
        console.warn('[TV] EventSource indisponível — usando polling');
        startPolling();
        return;
    }

    let meta;
    try {
        const res = await fetch(state.streamEndpoint, {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        meta = await res.json();
    } catch (err) {
        console.warn('[TV] metadata do stream falhou — usando polling', err);
        startPolling();
        return;
    }

    if (!meta || !meta.hub || !meta.topic) {
        console.warn('[TV] metadata inválido — usando polling', meta);
        startPolling();
        return;
    }

    let hubUrl;
    try {
        hubUrl = new URL(meta.hub, window.location.origin);
        hubUrl.searchParams.append('topic', meta.topic);
    } catch (err) {
        console.warn('[TV] URL do hub inválida — usando polling', err);
        startPolling();
        return;
    }

    try {
        eventSource = new EventSource(hubUrl.toString(), { withCredentials: true });
    } catch (err) {
        console.warn('[TV] SSE não iniciou — usando polling', err);
        startPolling();
        return;
    }

    eventSource.addEventListener('open', () => {
        console.debug('[TV] Mercure conectado em', meta.topic);
        sseFailures = 0;
        usingFallback = false;
        hideOverlay();
        stopPolling();
    });

    eventSource.addEventListener('message', () => {
        scheduleRefetch();
    });

    eventSource.addEventListener('error', () => {
        sseFailures++;
        if (sseFailures >= SSE_MAX_FAILURES) {
            console.warn('[TV] Mercure falhou seguido — caindo para polling');
            stopRealtime();
            startPolling();
        }
    });
}

function stopRealtime() {
    if (eventSource) {
        try { eventSource.close(); } catch {}
        eventSource = null;
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Fullscreen + som
// ─────────────────────────────────────────────────────────────────────────

function initFullscreen() {
    const btn = $('[data-tv-fullscreen]');
    if (!btn) return;
    btn.addEventListener('click', () => {
        try {
            if (!document.fullscreenElement) document.documentElement.requestFullscreen?.();
            else document.exitFullscreen?.();
        } catch (err) {
            console.warn('[TV] fullscreen falhou', err);
        }
    });
}

function initSoundToggle() {
    const btn  = $('[data-tv-sound]');
    const icon = $('[data-tv-sound-icon]');
    if (!btn) return;

    try { state.soundOn = localStorage.getItem('wazebr:tv:sound') === '1'; } catch {}

    updateSoundUI();

    btn.addEventListener('click', () => {
        state.soundOn = !state.soundOn;
        try { localStorage.setItem('wazebr:tv:sound', state.soundOn ? '1' : '0'); } catch {}
        updateSoundUI();
        if (state.soundOn) beep();
    });

    function updateSoundUI() {
        btn.classList.toggle('is-on', state.soundOn);
        btn.setAttribute('aria-label', state.soundOn ? 'Desativar alerta sonoro' : 'Ativar alerta sonoro');
        if (icon) icon.textContent = state.soundOn ? '🔔' : '🔇';
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Bootstrap
// ─────────────────────────────────────────────────────────────────────────

let bootstrapped = false;

export function initTvWallboard(root = document) {
    const page = root.querySelector?.('[data-tv-wallboard]') ?? root;
    if (!page || !page.matches?.('[data-tv-wallboard]') || bootstrapped) return;
    bootstrapped = true;

    state.endpoint = page.dataset.apiEndpoint;
    if (!state.endpoint) {
        console.warn('[TV] sem data-api-endpoint');
        return;
    }

    state.streamEndpoint = state.endpoint.replace(/\/api\/data\/?$/, '/stream');
    state.timezone = page.dataset.timezone || 'America/Sao_Paulo';

    initClock();
    initMap();
    initFullscreen();
    initSoundToggle();
    initWakeLock();

    fetchData().finally(() => {
        startRealtime();
    });

    setInterval(() => {
        if (state.lastGeneratedAt) renderUpdated(state.lastGeneratedAt);
    }, 5000);

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) return;
        if (!eventSource && usingFallback) {
            fetchData();
        }
    });

    window.addEventListener('beforeunload', () => {
        stopRealtime();
        stopPolling();
    });
}

export default initTvWallboard;
