/** Route comparison: standalone ES module; optional Chart.js and Leaflet globals. */
const FALLBACK_MESSAGES = {"pt_BR":{"Comparando…":"Comparando…","Diferença (p.p.)":"Diferença (p.p.)","Dom":"Dom","Empate técnico":"Empate técnico","Gráfico indisponível":"Gráfico indisponível","Mapa de calor da comparação de rotas":"Mapa de calor da comparação de rotas","Mapa indisponível":"Mapa indisponível","Não foi possível carregar o gráfico.":"Não foi possível carregar o gráfico.","Não foi possível carregar o mapa. Os demais dados continuam disponíveis.":"Não foi possível carregar o mapa. Os demais dados continuam disponíveis.","Qua":"Qua","Qui":"Qui","Rota A":"Rota A","Rota A melhor":"Rota A melhor","Rota B":"Rota B","Rota B melhor":"Rota B melhor","Seg":"Seg","Selecione duas rotas diferentes.":"Selecione duas rotas diferentes.","Sem amostras":"Sem amostras","Sem dados":"Sem dados","Sem traçado geográfico":"Sem traçado geográfico","Sex":"Sex","Sáb":"Sáb","Ter":"Ter","Uma ou ambas as rotas não têm geometria cadastrada.":"Uma ou ambas as rotas não têm geometria cadastrada.","segundos":"segundos"},"en":{"Comparando…":"Comparing…","Diferença (p.p.)":"Difference (pp)","Dom":"Sun","Empate técnico":"Technical tie","Gráfico indisponível":"Chart unavailable","Mapa de calor da comparação de rotas":"Route comparison heatmap","Mapa indisponível":"Map unavailable","Não foi possível carregar o gráfico.":"Could not load the chart.","Não foi possível carregar o mapa. Os demais dados continuam disponíveis.":"Could not load the map. Other data remains available.","Qua":"Wed","Qui":"Thu","Rota A":"Route A","Rota A melhor":"Route A is better","Rota B":"Route B","Rota B melhor":"Route B is better","Seg":"Mon","Selecione duas rotas diferentes.":"Select two different routes.","Sem amostras":"No samples","Sem dados":"No data","Sem traçado geográfico":"No geographical route","Sex":"Fri","Sáb":"Sat","Ter":"Tue","Uma ou ambas as rotas não têm geometria cadastrada.":"One or both routes have no registered geometry.","segundos":"seconds"},"es":{"Comparando…":"Comparando…","Diferença (p.p.)":"Diferencia (p.p.)","Dom":"Dom","Empate técnico":"Empate técnico","Gráfico indisponível":"Gráfico no disponible","Mapa de calor da comparação de rotas":"Mapa de calor de comparación de rutas","Mapa indisponível":"Mapa no disponible","Não foi possível carregar o gráfico.":"No se pudo cargar el gráfico.","Não foi possível carregar o mapa. Os demais dados continuam disponíveis.":"No se pudo cargar el mapa. Los demás datos siguen disponibles.","Qua":"Mié","Qui":"Jue","Rota A":"Ruta A","Rota A melhor":"Ruta A es mejor","Rota B":"Ruta B","Rota B melhor":"Ruta B es mejor","Seg":"Lun","Selecione duas rotas diferentes.":"Seleccione dos rutas diferentes.","Sem amostras":"Sin muestras","Sem dados":"Sin datos","Sem traçado geográfico":"Sin trazado geográfico","Sex":"Vie","Sáb":"Sáb","Ter":"Mar","Uma ou ambas as rotas não têm geometria cadastrada.":"Una o ambas rutas no tienen geometría registrada.","segundos":"segundos"}};
const states = new WeakMap();
const activeStates = new Set();
const DOW_KEYS = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];

export function finiteNumber(value) {
    if (value === null || value === undefined || typeof value === 'boolean' || String(value).trim() === '') return null;
    const number = Number(value);
    return Number.isFinite(number) ? number : null;
}
export function diffToColor(value) {
    const diff = finiteNumber(value);
    if (diff === null) return null;
    const d = Math.max(-0.5, Math.min(0.5, diff));
    if (Math.abs(d) < 0.03) return 'var(--rc-soft, #e2e8f0)';
    if (d > 0) return `hsl(0, ${50 + d / .5 * 25}%, ${58 - d / .5 * 12}%)`;
    return `hsl(140, ${55 + -d / .5 * 20}%, ${44 - -d / .5 * 4}%)`;
}
export function chartSeries(points, key) {
    return points.map(point => finiteNumber(point?.[key]));
}
function safeJson(value, fallback) {
    try { return JSON.parse(value); } catch { return fallback; }
}
function context(page) {
    const locale = (page.dataset.rcLocale || document.documentElement.lang || 'pt-BR').replace('_', '-');
    const code = locale.startsWith('en') ? 'en' : locale.startsWith('es') ? 'es' : 'pt_BR';
    const messages = { ...FALLBACK_MESSAGES[code], ...safeJson(page.dataset.rcMessages || '{}', {}) };
    return {
        locale,
        t(key, values = {}) {
            let text = Object.hasOwn(messages, key) ? String(messages[key]) : key;
            for (const [name, value] of Object.entries(values)) text = text.replaceAll(name, String(value));
            return text;
        },
        number: new Intl.NumberFormat(locale, { maximumFractionDigits: 0 }),
    };
}
function pct(ctx, value) {
    const number = finiteNumber(value);
    return number === null ? '—' : `${ctx.number.format(number * 100)}%`;
}
function theme(page) {
    const css = getComputedStyle(page);
    return {
        text: css.getPropertyValue('--rc-text').trim() || '#475569',
        grid: css.getPropertyValue('--rc-line').trim() || 'rgba(148,163,184,.25)',
        a: css.getPropertyValue('--rc-a').trim() || '#2563eb',
        b: css.getPropertyValue('--rc-b').trim() || '#ea580c',
    };
}
function routeNames(page, ctx) {
    const names = [...page.querySelectorAll('.rc-vs__name')].map(node => node.textContent.trim());
    return [names[0] || ctx.t('Rota A'), names[1] || ctx.t('Rota B')];
}
function messageAfter(node, title, detail = '') {
    let status = node.nextElementSibling;
    if (!status?.matches('[data-rc-component-status]')) {
        status = document.createElement('div');
        status.dataset.rcComponentStatus = '';
        status.className = 'rc-component-status';
        status.setAttribute('role', 'status');
        node.after(status);
    }
    status.replaceChildren();
    if (title) {
        const strong = document.createElement('strong'); strong.textContent = title; status.append(strong);
        if (detail) { const small = document.createElement('small'); small.textContent = detail; status.append(small); }
    }
    status.hidden = !title;
    return status;
}
function readPoints(canvas) {
    const result = safeJson(canvas.dataset.chartData, []);
    return Array.isArray(result) ? result.filter(point => point && typeof point === 'object') : [];
}
export function renderHeatmap(page, ctx = context(page)) {
    const box = page.querySelector('[data-rc-heatmap]');
    if (!box) return;
    const rows = safeJson(box.dataset.rcHeatmapData, []);
    const lookup = new Map();
    if (Array.isArray(rows)) for (const row of rows) {
        if (!row) continue;
        const dow = finiteNumber(row.dow), hour = finiteNumber(row.hour);
        if (!Number.isInteger(dow) || !Number.isInteger(hour)) continue;
        if (dow >= 0 && dow < 7 && hour >= 0 && hour < 24) lookup.set(`${dow}:${hour}`, row);
    }
    const fragment = document.createDocumentFragment();
    const div = (cls, text = '') => {
        const node = document.createElement('div'); node.className = cls; node.textContent = text; fragment.append(node); return node;
    };
    div('rc-heatmap__corner');
    for (let hour = 0; hour < 24; hour++) div('rc-heatmap__hour-label', String(hour).padStart(2, '0'));
    for (let dow = 0; dow < 7; dow++) {
        const day = ctx.t(DOW_KEYS[dow]);
        div('rc-heatmap__dow-label', day);
        for (let hour = 0; hour < 24; hour++) {
            const row = lookup.get(`${dow}:${hour}`);
            const diff = finiteNumber(row?.diff);
            const hasData = diff !== null;
            const cell = document.createElement('button');
            cell.type = 'button'; cell.className = 'rc-heatmap__cell' + (hasData ? '' : ' rc-heatmap__cell--empty');
            const winner = !hasData ? ctx.t('Sem amostras') : Math.abs(diff) < .03
                ? ctx.t('Empate técnico') : ctx.t(diff < 0 ? 'Rota A melhor' : 'Rota B melhor');
            const lines = [`${day} ${String(hour).padStart(2, '0')}h`, winner];
            if (hasData) {
                cell.style.background = diffToColor(diff);
                lines.push(`A: ${pct(ctx, row.aRatio)} · B: ${pct(ctx, row.bRatio)}`,
                    `${ctx.t('Diferença (p.p.)')}: ${ctx.number.format(diff * 100)}`);
            }
            cell.title = lines.join(' · '); cell.setAttribute('aria-label', cell.title);
            cell.dataset.rcDow = String(dow); cell.dataset.rcHour = String(hour);
            cell.tabIndex = dow === 0 && hour === 0 ? 0 : -1;
            fragment.append(cell);
        }
    }
    box.replaceChildren(fragment);
    box.setAttribute('role', 'group'); box.setAttribute('aria-label', ctx.t('Mapa de calor da comparação de rotas'));
}
function heatmapKeyboard(page, signal) {
    const box = page.querySelector('[data-rc-heatmap]');
    box?.addEventListener('keydown', event => {
        const current = event.target.closest('[data-rc-hour]');
        if (!current) return;
        const cells = [...box.querySelectorAll('[data-rc-hour]')];
        const index = cells.indexOf(current);
        let next = index;
        if (event.key === 'ArrowRight') next = Math.min(167, index + 1);
        else if (event.key === 'ArrowLeft') next = Math.max(0, index - 1);
        else if (event.key === 'ArrowDown') next = Math.min(167, index + 24);
        else if (event.key === 'ArrowUp') next = Math.max(0, index - 24);
        else if (event.key === 'Home') next = Math.floor(index / 24) * 24;
        else if (event.key === 'End') next = Math.floor(index / 24) * 24 + 23;
        else return;
        event.preventDefault(); current.tabIndex = -1; cells[next].tabIndex = 0; cells[next].focus();
    }, { signal });
}
function timelineLabel(value, locale) {
    const text = String(value ?? '');
    if (/Z$|[+-]\d{2}:\d{2}$/.test(text)) {
        const date = new Date(text);
        if (Number.isFinite(date.getTime())) return new Intl.DateTimeFormat(locale, {
            day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', timeZone: 'America/Sao_Paulo',
        }).format(date);
    }
    const match = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}:\d{2})/.exec(text);
    if (!match) return text;
    return locale.startsWith('en') ? `${match[2]}/${match[3]} ${match[4]}` : `${match[3]}/${match[2]} ${match[4]}`;
}
function initCharts(page, state) {
    if (!page.querySelector('[data-chart^="rc-"]')) return true;
    if (typeof window.Chart !== 'function') return false;
    const ctx = state.ctx, colors = theme(page), names = routeNames(page, ctx);
    for (const canvas of page.querySelectorAll('[data-chart^="rc-"]')) {
        const kind = canvas.dataset.chart;
        if (!['rc-timeline', 'rc-by-hour', 'rc-by-dow'].includes(kind)) continue;
        const points = readPoints(canvas);
        const a = chartSeries(points, 'aDelay'), b = chartSeries(points, 'bDelay');
        if (!a.some(value => value !== null) && !b.some(value => value !== null)) {
            state.charts.get(canvas)?.destroy(); state.charts.delete(canvas);
            canvas.hidden = true; messageAfter(canvas, ctx.t('Sem dados')); continue;
        }
        canvas.hidden = false; messageAfter(canvas, '');
        const labels = points.map(point => kind === 'rc-timeline' ? timelineLabel(point.time, ctx.locale)
            : kind === 'rc-by-hour' ? `${String(point.hour).padStart(2, '0')}h` : ctx.t(DOW_KEYS[Number(point.dow)] || '?'));
        const datasets = [a, b].map((values, index) => ({
            label: names[index], routeKey: index === 0 ? 'a' : 'b', data: values,
            borderColor: index === 0 ? colors.a : colors.b,
            backgroundColor: index === 0 ? colors.a : colors.b,
            borderWidth: 2, borderRadius: 4, fill: false, tension: .2,
            pointRadius: 0, spanGaps: false,
        }));
        const config = {
            type: kind === 'rc-timeline' ? 'line' : 'bar', data: { labels, datasets },
            options: {
                responsive: true, maintainAspectRatio: false, animation: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label(item) {
                        const value = finiteNumber(item.parsed.y);
                        if (value === null) return `${item.dataset.label}: ${ctx.t('Sem dados')}`;
                        const sign = value > 0 ? '+' : '';
                        const point = points[item.dataIndex];
                        const ratio = item.dataset.routeKey === 'a' ? point.aRatio : point.bRatio;
                        const suffix = kind === 'rc-timeline' ? '' : ` · ${pct(ctx, ratio)}`;
                        return `${item.dataset.label}: ${sign}${ctx.number.format(value)} s${suffix}`;
                    } } },
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: colors.text, maxTicksLimit: 10, maxRotation: 0 } },
                    y: { grid: { color: colors.grid }, ticks: { color: colors.text }, beginAtZero: true,
                        title: { display: true, text: ctx.t('segundos'), color: colors.text } },
                },
            },
        };
        try {
            state.charts.get(canvas)?.destroy(); state.charts.delete(canvas);
            state.charts.set(canvas, new window.Chart(canvas.getContext('2d'), config));
        } catch (error) {
            canvas.hidden = true; messageAfter(canvas, ctx.t('Gráfico indisponível'), ctx.t('Não foi possível carregar o gráfico.'));
            console.error('[route-compare] Chart error', error);
        }
    }
    return true;
}
function validLine(raw) {
    const values = safeJson(raw, []);
    if (!Array.isArray(values)) return [];
    return values.filter(point => Array.isArray(point) && point.length >= 2)
        .map(point => [finiteNumber(point[0]), finiteNumber(point[1])])
        .filter(([lat, lng]) => lat !== null && lng !== null && Math.abs(lat) <= 90 && Math.abs(lng) <= 180);
}
function mapFallback(box, ctx, libraryError = false) {
    let fallback = box.querySelector('[data-rc-map-fallback]');
    if (!fallback) { fallback = document.createElement('div'); fallback.dataset.rcMapFallback = ''; fallback.className = 'rc-map__fallback'; box.append(fallback); }
    const title = document.createElement('strong'); title.textContent = ctx.t(libraryError ? 'Mapa indisponível' : 'Sem traçado geográfico');
    const detail = document.createElement('small'); detail.textContent = ctx.t(libraryError
        ? 'Não foi possível carregar o mapa. Os demais dados continuam disponíveis.' : 'Uma ou ambas as rotas não têm geometria cadastrada.');
    fallback.replaceChildren(title, detail); fallback.hidden = false; fallback.setAttribute('role', 'status');
}
function initMap(page, state) {
    const box = page.querySelector('[data-rc-map]');
    if (!box || state.map) return true;
    if (typeof window.L?.map !== 'function') return false;
    const lines = [validLine(box.dataset.rcA), validLine(box.dataset.rcB)];
    if (!lines.some(line => line.length > 1)) { mapFallback(box, state.ctx); return true; }
    try {
        box.querySelector('[data-rc-map-fallback]')?.setAttribute('hidden', '');
        const map = window.L.map(box, { zoomControl: true, preferCanvas: true }); state.map = map;
        window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19, attribution: '&copy; OpenStreetMap contributors',
        }).addTo(map);
        const names = routeNames(page, state.ctx);
        lines.forEach((line, index) => {
            if (line.length < 2) return;
            const popup = document.createElement('strong'); popup.textContent = names[index];
            window.L.polyline(line, { color: index === 0 ? '#2563eb' : '#ea580c', weight: index === 0 ? 6 : 5,
                opacity: .9, dashArray: index === 0 ? null : '8 6', lineCap: 'round', lineJoin: 'round',
            }).bindPopup(popup).addTo(map);
        });
        map.fitBounds(lines.filter(line => line.length > 1).flat(), { padding: [24, 24], maxZoom: 15 });
        if (typeof ResizeObserver === 'function') { state.resize = new ResizeObserver(() => map.invalidateSize()); state.resize.observe(box); }
        else window.addEventListener('resize', () => map.invalidateSize(), { signal: state.abort.signal });
        requestAnimationFrame(() => map.invalidateSize());
    } catch (error) {
        state.map?.remove(); state.map = null; mapFallback(box, state.ctx, true);
        console.error('[route-compare] Leaflet error', error);
    }
    return true;
}
function initPicker(page, state) {
    const form = page.querySelector('[data-rc-form]');
    const a = page.querySelector('[data-rc-select-a]'), b = page.querySelector('[data-rc-select-b]');
    if (!form || !a || !b) return;
    let status = form.querySelector('[data-rc-picker-status]');
    if (!status) { status = document.createElement('p'); status.dataset.rcPickerStatus = ''; status.className = 'rc-picker-status'; status.setAttribute('role', 'status'); form.append(status); }
    const validate = () => {
        b.setCustomValidity(a.value && a.value === b.value ? state.ctx.t('Selecione duas rotas diferentes.') : '');
        return form.reportValidity();
    };
    form.addEventListener('submit', event => {
        if (!validate()) { event.preventDefault(); return; }
        status.textContent = state.ctx.t('Comparando…');
    }, { signal: state.abort.signal });
    for (const select of [a, b]) select.addEventListener('change', () => {
        b.setCustomValidity(a.value && a.value === b.value ? state.ctx.t('Selecione duas rotas diferentes.') : '');
        status.textContent = '';
    }, { signal: state.abort.signal });
    page.querySelector('[data-rc-swap]')?.addEventListener('click', event => {
        event.preventDefault(); [a.value, b.value] = [b.value, a.value];
        b.setCustomValidity(a.value && a.value === b.value ? state.ctx.t('Selecione duas rotas diferentes.') : '');
        status.textContent = '';
        // Swapping alone does not navigate or steal focus; Compare submits the form.
    }, { signal: state.abort.signal });
}
function cleanup(page, state) {
    state.abort.abort(); state.timers.forEach(clearTimeout); state.resize?.disconnect();
    state.charts.forEach(chart => chart.destroy()); state.charts.clear(); state.map?.remove();
    states.delete(page); activeStates.delete(state);
}
export function initRouteCompare(root = document) {
    const page = root.matches?.('[data-route-compare]') ? root : root.querySelector('[data-route-compare]');
    if (!page || states.has(page)) return;
    const state = { ctx: context(page), charts: new Map(), map: null, abort: new AbortController(), timers: [], resize: null };
    states.set(page, state); activeStates.add(state);
    initPicker(page, state); renderHeatmap(page, state.ctx); heatmapKeyboard(page, state.abort.signal);
    const hydrate = attempt => {
        if (state.abort.signal.aborted || !page.isConnected) return;
        const chartsReady = state.chartsReady || initCharts(page, state), mapReady = initMap(page, state);
        state.chartsReady = chartsReady;
        if (chartsReady && mapReady) return;
        if (attempt < 30) { state.timers.push(setTimeout(() => hydrate(attempt + 1), 150)); return; }
        if (!chartsReady) for (const canvas of page.querySelectorAll('[data-chart^="rc-"]')) {
            canvas.hidden = true; messageAfter(canvas, state.ctx.t('Gráfico indisponível'), state.ctx.t('Não foi possível carregar o gráfico.'));
        }
        if (!mapReady) { const box = page.querySelector('[data-rc-map]'); if (box) mapFallback(box, state.ctx, true); }
    };
    hydrate(0);
    window.addEventListener('wazebr:themechange', () => initCharts(page, state), { signal: state.abort.signal });
    window.addEventListener('pagehide', () => cleanup(page, state), { once: true, signal: state.abort.signal });
}
export default initRouteCompare;
if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => initRouteCompare(), { once: true });
    else initRouteCompare();
    window.addEventListener('pageshow', event => { if (event.persisted) initRouteCompare(); });
}
