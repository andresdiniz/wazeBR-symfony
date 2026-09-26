/**
 * pages/jam-history.js — /jams/historico
 *
 *  • Filtros com quick-range (24h, 7d, 30d, 90d) e debounce
 *  • Timeline SVG (jams + alertas)
 *  • KPIs com deltas vs período anterior
 *  • Sort client-side na tabela
 *  • Carregar mais (paginação local)
 *  • Export CSV via URL (com filtros aplicados)
 */

const FILTER_DEBOUNCE = 350;
const PAGE_SIZE       = 40;

const state = {
    endpoint:        null,
    exportEndpoint:  null,
    timezone:        'America/Sao_Paulo',
    filterTimer:     null,
    sortKey:         'when',
    sortDir:         -1,
    pageSize:        PAGE_SIZE,
    allRows:         [],
    bootstrapped:    false,
};

// ─────────────────────────────────────────────────────────────────────────
// Utils
// ─────────────────────────────────────────────────────────────────────────

const $  = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];

function escapeHtml(str) {
    return String(str ?? '')
        .replaceAll('&', '&amp;').replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;').replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function fmtNum(n, digits = 0) {
    if (n === null || n === undefined || Number.isNaN(n)) return '—';
    return new Intl.NumberFormat('pt-BR', {
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    }).format(n);
}

function fmtDuration(min) {
    const m = Number(min) || 0;
    if (m < 1)   return '<1 min';
    if (m < 60)  return `${m} min`;
    const h = Math.floor(m / 60);
    const r = m % 60;
    return r === 0 ? `${h}h` : `${h}h ${r}m`;
}

function fmtDateTime(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '—';
    return new Intl.DateTimeFormat('pt-BR', {
        day: '2-digit', month: '2-digit',
        hour: '2-digit', minute: '2-digit',
        hour12: false, timeZone: state.timezone,
    }).format(d);
}

function fmtDateShort(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '—';
    return new Intl.DateTimeFormat('pt-BR', {
        day: '2-digit', month: '2-digit',
        timeZone: state.timezone,
    }).format(d);
}

function levelColor(level) {
    switch (Number(level)) {
        case 1: return '#22c55e';
        case 2: return '#84cc16';
        case 3: return '#f97316';
        case 4: return '#ef4444';
        case 5: return '#b91c1c';
        default: return '#94a3b8';
    }
}

function setText(sel, v) {
    const el = $(sel);
    if (el) el.textContent = v;
}

// ─────────────────────────────────────────────────────────────────────────
// Filtros
// ─────────────────────────────────────────────────────────────────────────

function readFilters() {
    const f = {};
    $$('[data-jh-filter]').forEach((el) => {
        f[el.dataset.jhFilter] = el.type === 'checkbox'
            ? (el.checked ? '1' : '')
            : el.value;
    });
    return f;
}

function applyFilterToUrl(filters) {
    const url = new URL(window.location.href);
    Object.entries(filters).forEach(([k, v]) => {
        if (v === '' || v == null) url.searchParams.delete(k);
        else url.searchParams.set(k, v);
    });
    window.history.replaceState({}, '', url.toString());

    const exportLink = $('[data-jh-export]');
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
        const el = document.querySelector(`[data-jh-filter="${key}"]`);
        if (!el) return;
        if (el.type === 'checkbox') el.checked = (value === '1' || value === 'true');
        else el.value = value;
    });
}

function setQuickRange(days) {
    const to   = new Date();
    const from = new Date();
    from.setDate(to.getDate() - (days - 1));

    const toYmd   = to.toISOString().slice(0, 10);
    const fromYmd = from.toISOString().slice(0, 10);

    const fromEl = $('[data-jh-filter="date_from"]');
    const toEl   = $('[data-jh-filter="date_to"]');
    if (fromEl) fromEl.value = fromYmd;
    if (toEl)   toEl.value   = toYmd;

    $$('[data-jh-range]').forEach((btn) => {
        btn.classList.toggle('is-active', Number(btn.dataset.jhRange) === days);
    });
}

function installFilterHandlers() {
    const form = $('[data-jh-filter-form]');
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

    form.querySelectorAll('select, input[type="date"], input[type="checkbox"]')
        .forEach((el) => el.addEventListener('change', fetchAndRender));

    form.addEventListener('submit', (ev) => { ev.preventDefault(); fetchAndRender(); });

    // Quick ranges
    $$('[data-jh-range]').forEach((btn) => {
        btn.addEventListener('click', () => {
            setQuickRange(Number(btn.dataset.jhRange));
            fetchAndRender();
        });
    });

    $$('[data-action="jh-clear-filters"]').forEach((btn) => {
        btn.addEventListener('click', () => {
            $$('[data-jh-filter]').forEach((el) => {
                if (el.type === 'checkbox') el.checked = (el.dataset.jhFilter === 'include_inactive');
                else if (el.tagName === 'SELECT') el.selectedIndex = 0;
                else if (el.type === 'date') el.value = '';
                else el.value = '';
            });
            setQuickRange(7);
            fetchAndRender();
        });
    });

    $$('[data-action="jh-refresh"]').forEach((btn) => {
        btn.addEventListener('click', fetchAndRender);
    });
}

// ─────────────────────────────────────────────────────────────────────────
// KPIs
// ─────────────────────────────────────────────────────────────────────────

function renderKpis(summary) {
    const cur = summary.current || {};
    const delta = summary.delta || {};

    const setDelta = (sel, value, lowerIsBetter = true) => {
        const el = $(sel);
        if (!el) return;
        if (value === null || value === undefined) {
            el.className = 'jh-delta is-flat';
            el.textContent = '—';
            return;
        }
        const v = Number(value);
        if (Math.abs(v) < 0.1) {
            el.className = 'jh-delta is-flat';
            el.textContent = '0%';
            return;
        }
        const isUp = v > 0;
        const isGood = lowerIsBetter ? !isUp : isUp;
        el.className = `jh-delta ${isGood ? 'is-down' : 'is-up'}`;
        el.innerHTML = `<span aria-hidden="true">${isUp ? '▲' : '▼'}</span> ${fmtNum(Math.abs(v), 1)}%`;
    };

    setText('[data-jh-kpi="total"]', fmtNum(cur.total ?? 0));
    setText('[data-jh-kpi="blocked"]', fmtNum(cur.blocked ?? 0));
    setText('[data-jh-kpi="avgDelay"]', fmtNum(cur.avgDelay ?? 0) + 's');
    setText('[data-jh-kpi="avgDuration"]', fmtDuration(cur.avgDuration ?? 0));
    setText('[data-jh-kpi="correlated"]', '—');

    setDelta('[data-jh-delta="total"]',       delta.total,       true);
    setDelta('[data-jh-delta="blocked"]',     delta.blocked,     true);
    setDelta('[data-jh-delta="avgDelay"]',    delta.avgDelay,    true);
    setDelta('[data-jh-delta="avgDuration"]', delta.avgDuration, true);

    const prevRange = summary.prevRange || {};
    setText('[data-jh-prev-range]',
        prevRange.from ? `vs ${fmtDateShort(prevRange.from)} → ${fmtDateShort(prevRange.to)}` : '');

    setText('[data-jh-kpi-foot="total"]',
        `${fmtNum(cur.distinctCities ?? 0)} cidades · ${fmtNum(cur.distinctStreets ?? 0)} ruas`);

    const stale = cur.blockedStale ?? 0;
    setText('[data-jh-kpi-foot="blocked"]',
        stale > 0 ? `${fmtNum(stale)} antigas` : 'Todas ativas');
}

// ─────────────────────────────────────────────────────────────────────────
// Timeline SVG
// ─────────────────────────────────────────────────────────────────────────

function renderTimeline(timeline) {
    const wrap = $('[data-jh-timeline]');
    if (!wrap) return;

    if (!Array.isArray(timeline) || timeline.length === 0) {
        wrap.innerHTML = '<div class="jh-empty">Sem dados no período</div>';
        return;
    }

    const W = 800, H = 220;
    const PAD = { top: 20, right: 20, bottom: 36, left: 44 };
    const innerW = W - PAD.left - PAD.right;
    const innerH = H - PAD.top  - PAD.bottom;

    const maxJams   = Math.max(1, ...timeline.map(d => d.jams));
    const maxAlerts = Math.max(1, ...timeline.map(d => d.alerts));
    const maxY      = Math.max(maxJams, maxAlerts);

    const stepX = timeline.length > 1 ? innerW / (timeline.length - 1) : innerW;

    const x = (i) => PAD.left + i * stepX;
    const y = (v) => PAD.top + innerH - (v / maxY) * innerH;

    const pathJams   = timeline.map((d, i) => `${i === 0 ? 'M' : 'L'} ${x(i).toFixed(1)} ${y(d.jams).toFixed(1)}`).join(' ');
    const pathAlerts = timeline.map((d, i) => `${i === 0 ? 'M' : 'L'} ${x(i).toFixed(1)} ${y(d.alerts).toFixed(1)}`).join(' ');

    // Grade horizontal
    const gridCount = 4;
    let gridLines = '';
    let yLabels   = '';
    for (let i = 0; i <= gridCount; i++) {
        const v  = Math.round((maxY * i) / gridCount);
        const yy = y(v);
        gridLines += `<line class="grid-line" x1="${PAD.left}" x2="${W - PAD.right}" y1="${yy.toFixed(1)}" y2="${yy.toFixed(1)}" />`;
        yLabels   += `<text class="axis-label" x="${PAD.left - 6}" y="${(yy + 3).toFixed(1)}" text-anchor="end">${v}</text>`;
    }

    // Marcadores X (até 8 labels)
    const labelCount = Math.min(8, timeline.length);
    const stride = Math.max(1, Math.floor(timeline.length / labelCount));
    let xLabels = '';
    for (let i = 0; i < timeline.length; i += stride) {
        const d = timeline[i];
        const label = state.timelineBucket === 'hour'
            ? d.at.slice(11, 16)
            : fmtDateShort(d.at);
        xLabels += `<text class="axis-label" x="${x(i).toFixed(1)}" y="${H - 8}" text-anchor="middle">${label}</text>`;
    }

    wrap.innerHTML = `
        <svg viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" role="img" aria-label="Timeline de jams e alertas">
            ${gridLines}
            ${yLabels}
            ${xLabels}
            <path d="${pathJams}"   fill="none" stroke="var(--jh-primary)" stroke-width="2.2" stroke-linejoin="round" stroke-linecap="round" />
            <path d="${pathAlerts}" fill="none" stroke="var(--jh-accent)"  stroke-width="2"   stroke-dasharray="4 3" stroke-linejoin="round" stroke-linecap="round" />
            ${timeline.map((d, i) => `
                <circle cx="${x(i).toFixed(1)}" cy="${y(d.jams).toFixed(1)}" r="2.5" fill="var(--jh-primary)" />
                <circle cx="${x(i).toFixed(1)}" cy="${y(d.alerts).toFixed(1)}" r="2.5" fill="var(--jh-accent)" />
            `).join('')}
        </svg>
        <div class="legend">
            <span><i class="lg-jams"></i> Jams</span>
            <span><i class="lg-alerts"></i> Alertas Waze</span>
        </div>
    `;
}

// ─────────────────────────────────────────────────────────────────────────
// Barras e listas
// ─────────────────────────────────────────────────────────────────────────

function renderByHour(hours) {
    const el = $('[data-jh-by-hour]');
    if (!el) return;

    const max = Math.max(1, ...hours.map(h => h.count));
    el.innerHTML = hours.map(h => {
        const pct = Math.round((h.count / max) * 100);
        return `
            <div class="jh-bar">
                <span class="jh-bar__label">${h.label}</span>
                <div class="jh-bar__track">
                    <span class="jh-bar__fill" style="width:${pct}%"></span>
                </div>
                <span class="jh-bar__value">${fmtNum(h.count)}</span>
            </div>`;
    }).join('');
}

function renderByDow(dows) {
    const el = $('[data-jh-by-dow]');
    if (!el) return;

    const max = Math.max(1, ...dows.map(d => d.count));
    el.innerHTML = dows.map(d => {
        const pct = Math.round((d.count / max) * 100);
        return `
            <div class="jh-bar">
                <span class="jh-bar__label">${d.label}</span>
                <div class="jh-bar__track">
                    <span class="jh-bar__fill" style="width:${pct}%"></span>
                </div>
                <span class="jh-bar__value">${fmtNum(d.count)}</span>
            </div>`;
    }).join('');
}

function renderByLevel(levels) {
    const el = $('[data-jh-by-level]');
    if (!el) return;

    const total = levels.reduce((s, l) => s + l.count, 0) || 1;

    el.innerHTML = levels.map(l => {
        const pct = Math.round((l.count / total) * 100);
        return `
            <div class="jh-bar">
                <span class="jh-bar__label">N${l.level}</span>
                <div class="jh-bar__track">
                    <span class="jh-bar__fill is-accent" style="width:${pct}%; background:linear-gradient(90deg,${levelColor(l.level)},${levelColor(l.level)}cc)"></span>
                </div>
                <span class="jh-bar__value">${fmtNum(l.count)}</span>
            </div>`;
    }).join('');
}

function renderByStatus(statuses) {
    const el = $('[data-jh-by-status]');
    if (!el) return;

    const total = statuses.reduce((s, x) => s + x.count, 0);
    if (total === 0) {
        el.innerHTML = '<div class="jh-empty">Sem dados</div>';
        return;
    }

    const normal = statuses.find(s => s.status === 'normal')?.count ?? 0;
    const active = statuses.find(s => s.status === 'active')?.count ?? 0;
    const stale  = statuses.find(s => s.status === 'stale')?.count  ?? 0;

    const pct = (n) => ((n / total) * 100).toFixed(1);

    el.innerHTML = `
        <div class="jh-stacked" role="img" aria-label="Distribuição por status">
            <span class="st-normal" style="width:${pct(normal)}%"></span>
            <span class="st-active" style="width:${pct(active)}%"></span>
            <span class="st-stale"  style="width:${pct(stale)}%"></span>
        </div>
        <div class="jh-stacked-legend">
            <span><i class="l-normal"></i> Congestionamento · ${fmtNum(normal)} (${pct(normal)}%)</span>
            <span><i class="l-active"></i> Interdição ativa · ${fmtNum(active)} (${pct(active)}%)</span>
            <span><i class="l-stale"></i> Interdição antiga · ${fmtNum(stale)} (${pct(stale)}%)</span>
        </div>`;
}

function renderRank(el, items, kind) {
    if (!el) return;
    if (!Array.isArray(items) || items.length === 0) {
        el.innerHTML = '<li class="jh-empty">Sem dados</li>';
        return;
    }

    el.innerHTML = items.map((r, idx) => {
        const name = kind === 'cities'
            ? escapeHtml(r.city)
            : `${escapeHtml(r.street)}<span class="jh-rank__city">${escapeHtml(r.city || '')}</span>`;

        const foot = kind === 'cities'
            ? (r.blocked > 0 ? `🚫 ${r.blocked} · ${r.avgDelay}s` : `${r.avgDelay}s`)
            : (r.blocked > 0 ? `🚫 ${r.blocked} · máx ${r.maxDelay}s` : `máx ${r.maxDelay}s`);

        return `
            <li>
                <span class="jh-rank__pos">${idx + 1}</span>
                <span class="jh-rank__name">${name}</span>
                <span class="jh-rank__stats">
                    ${fmtNum(r.count)}
                    <small>${foot}</small>
                </span>
            </li>`;
    }).join('');
}

// ─────────────────────────────────────────────────────────────────────────
// Correlação com alertas
// ─────────────────────────────────────────────────────────────────────────

function renderCorrelation(corr) {
    const el = $('[data-jh-correlation]');
    if (!el) return;

    if (!corr || corr.sampled === 0) {
        el.innerHTML = '<div class="jh-empty">Sem amostra para correlação</div>';
        return;
    }

    const typesHtml = (corr.byType || []).slice(0, 6).map((t) => {
        const max = Math.max(1, ...(corr.byType || []).map(x => x.count));
        const pct = Math.round((t.count / max) * 100);
        return `
            <div class="jh-bar">
                <span class="jh-bar__label" title="${escapeHtml(t.label)}">${escapeHtml(t.label).slice(0, 8)}</span>
                <div class="jh-bar__track">
                    <span class="jh-bar__fill is-accent" style="width:${pct}%"></span>
                </div>
                <span class="jh-bar__value">${fmtNum(t.count)}</span>
            </div>`;
    }).join('');

    const topHtml = (corr.topCorrelated || []).slice(0, 5).map((j) => `
        <div class="jh-correlated-jam">
            <div>
                <strong>${escapeHtml(j.street || '—')}</strong>
                <small>${escapeHtml(j.city || '')} · ${fmtDateTime(j.when)}</small>
            </div>
            <span class="badge">🔔 ${j.nearbyAlerts}</span>
        </div>
    `).join('');

    el.innerHTML = `
        <div class="jh-correlation-summary">
            <div class="jh-correlation-metric">
                <small>Amostra</small>
                <strong>${fmtNum(corr.sampled)}</strong>
                <em>jams de nível 3+</em>
            </div>
            <div class="jh-correlation-metric">
                <small>Com alertas</small>
                <strong>${fmtNum(corr.withAlerts)}</strong>
                <em>${fmtNum(corr.withAlertsPct, 1)}% do total</em>
            </div>
            <div class="jh-correlation-metric">
                <small>Distância média</small>
                <strong>${fmtNum(corr.avgDistanceM)}m</strong>
                <em>Δ ${fmtNum(corr.avgTimeOffsetM)}min</em>
            </div>
        </div>

        <div class="jh-panel__head" style="margin-top:12px">
            <div>
                <span class="jh-kicker">Tipos de alerta</span>
                <h2>Tipos correlacionados</h2>
            </div>
        </div>
        <div class="jh-correlation-types">${typesHtml || '<div class="jh-empty">Sem alertas correlacionados</div>'}</div>

        <div class="jh-panel__head" style="margin-top:12px">
            <div>
                <span class="jh-kicker">Top 5</span>
                <h2>Jams com mais alertas</h2>
            </div>
        </div>
        <div style="display:flex;flex-direction:column;gap:6px">
            ${topHtml || '<div class="jh-empty">Sem correlação forte no período</div>'}
        </div>
    `;

    setText('[data-jh-kpi="correlated"]', fmtNum(corr.withAlertsPct, 1) + '%');
}

// ─────────────────────────────────────────────────────────────────────────
// Tabela
// ─────────────────────────────────────────────────────────────────────────

function sortedRows() {
    const k = state.sortKey, d = state.sortDir;
    return [...state.allRows].sort((a, b) => {
        const av = a[k], bv = b[k];
        if (av === null || av === undefined) return 1;
        if (bv === null || bv === undefined) return -1;
        if (typeof av === 'string') return av.localeCompare(bv) * d;
        return (Number(av) - Number(bv)) * d;
    });
}

function levelCell(j) {
    if (j.isBlocked && j.isStale) return `<span class="jh-table__level jh-table__level--5" title="Interdição antiga">⚠</span>`;
    if (j.isBlocked)              return `<span class="jh-table__level jh-table__level--5" title="Interdição ativa">🚫</span>`;
    return `<span class="jh-table__level jh-table__level--${j.level}">${j.level}</span>`;
}

function delayCell(j) {
    if (j.delay === -1 || j.delay === null) return `<span class="jh-table__dash">—</span>`;
    if (j.delay === 0) return '0 min';
    const min = j.delayMin ?? (j.delay / 60);
    return `${fmtNum(min, min < 10 ? 1 : 0)} min`;
}

function alertsCell(j) {
    const n = Number(j.nearbyAlerts ?? 0);
    if (n <= 0) return `<span class="jh-alerts-badge jh-alerts-badge--zero">—</span>`;
    return `<span class="jh-alerts-badge"><i>🔔</i> ${n}</span>`;
}

function renderTable() {
    const tbody = $('[data-jh-table]');
    if (!tbody) return;

    const all     = sortedRows();
    const visible = all.slice(0, state.pageSize);
    const hasMore = all.length > state.pageSize;

    setText('[data-jh-count="rows"]', fmtNum(all.length));

    const loadMore = $('[data-jh-load-more]');
    if (loadMore) loadMore.style.display = hasMore ? '' : 'none';

    if (all.length === 0) {
        tbody.innerHTML = `
            <tr><td colspan="8" class="jh-empty">
                Sem registros no período selecionado.
                <span class="jh-empty__hint">
                    <small>Tente ampliar o intervalo de datas ou limpar filtros.</small>
                </span>
            </td></tr>`;
        return;
    }

    tbody.innerHTML = visible.map((j) => {
        const url = `/jams/${j.id}`;
        const cls = j.isBlocked && j.isStale ? 'is-stale'
                  : j.isBlocked              ? 'is-blocked'
                  : '';
        return `
            <tr class="${cls}" data-jh-href="${url}" tabindex="0">
                <td>${levelCell(j)}</td>
                <td>
                    <span class="jh-table__street">${escapeHtml(j.street || '—')}</span>
                    <span class="jh-table__city">${escapeHtml(j.city || '')}</span>
                </td>
                <td class="is-num">${delayCell(j)}</td>
                <td class="is-num">${fmtNum(j.speed, 0)} km/h</td>
                <td class="is-num">${fmtNum(j.lengthKm, 2)} km</td>
                <td class="is-num"><span class="jh-table__duration">${fmtDuration(j.durationMin)}</span></td>
                <td class="is-num">${alertsCell(j)}</td>
                <td><span class="jh-table__when">${fmtDateTime(j.when)}</span></td>
            </tr>`;
    }).join('');

    if (tbody.dataset.bound !== '1') {
        tbody.dataset.bound = '1';
        tbody.addEventListener('click', (e) => {
            if (e.target.closest('a')) return;
            const tr = e.target.closest('tr[data-jh-href]');
            if (tr) window.location.href = tr.dataset.jhHref;
        });
        tbody.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter') return;
            const tr = e.target.closest('tr[data-jh-href]');
            if (tr) window.location.href = tr.dataset.jhHref;
        });
    }
}

function installSortHandlers() {
    const table = $('[data-jh-table-root]');
    if (!table || table.dataset.sortBound === '1') return;
    table.dataset.sortBound = '1';

    table.querySelectorAll('th[data-sortable]').forEach((th) => {
        th.addEventListener('click', () => {
            const key = th.dataset.sortable;
            if (state.sortKey === key) state.sortDir *= -1;
            else { state.sortKey = key; state.sortDir = -1; }

            table.querySelectorAll('th[data-sortable]').forEach((h) => {
                const icon = h.querySelector('.jh-sort-icon');
                if (!icon) return;
                if (h.dataset.sortable === state.sortKey) {
                    icon.textContent = state.sortDir === -1 ? ' ↓' : ' ↑';
                    icon.classList.add('is-active');
                } else {
                    icon.textContent = '';
                    icon.classList.remove('is-active');
                }
            });
            renderTable();
        });
    });

    const loadMore = $('[data-jh-load-more] button');
    if (loadMore && loadMore.dataset.bound !== '1') {
        loadMore.dataset.bound = '1';
        loadMore.addEventListener('click', () => {
            state.pageSize += PAGE_SIZE;
            renderTable();
        });
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Fetch
// ─────────────────────────────────────────────────────────────────────────

async function fetchAndRender() {
    if (!state.endpoint) return;

    const filters = readFilters();
    applyFilterToUrl(filters);

    const url = new URL(state.endpoint, window.location.origin);
    Object.entries(filters).forEach(([k, v]) => {
        if (v !== '' && v != null) url.searchParams.set(k, v);
    });

    const page = $('[data-jh-page]');
    page?.classList.add('jh-loading');

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
        state.timelineBucket = data.period?.bucket || 'day';
        state.allRows = data.recentJams || [];
        state.pageSize = PAGE_SIZE;

        renderKpis(data.summary);
        renderTimeline(data.timeline);
        renderByHour(data.byHour);
        renderByDow(data.byDayOfWeek);
        renderByLevel(data.byLevel);
        renderByStatus(data.byStatus);
        renderRank($('[data-jh-rank-streets]'), data.topStreets, 'streets');
        renderRank($('[data-jh-rank-cities]'),  data.topCities,  'cities');
        renderCorrelation(data.alertCorrelation);
        renderTable();
    } catch (err) {
        console.error('[JAM-HISTORY] fetch falhou', err);
    } finally {
        page?.classList.remove('jh-loading');
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Bootstrap
// ─────────────────────────────────────────────────────────────────────────

export function initJamHistoryPage(root = document) {
    const page = root.querySelector?.('[data-jh-page]') ?? root;
    if (!page?.matches?.('[data-jh-page]') || state.bootstrapped) return;
    state.bootstrapped = true;

    state.endpoint       = page.dataset.apiEndpoint;
    state.exportEndpoint = page.dataset.exportEndpoint;
    state.timezone       = page.dataset.timezone || 'America/Sao_Paulo';

    if (!state.endpoint) {
        console.warn('[JAM-HISTORY] sem data-api-endpoint');
        return;
    }

    restoreFiltersFromUrl();
    installFilterHandlers();
    installSortHandlers();

    fetchAndRender();
}

export default initJamHistoryPage;
