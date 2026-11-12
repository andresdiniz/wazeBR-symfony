const states = new WeakMap();
const DAYS = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];
export function finiteNumber(value) {
    if (value == null || typeof value === 'boolean' || String(value).trim() === '') return null;
    const number = Number(value);
    return Number.isFinite(number) ? number : null;
}
const json = (raw, fallback = []) => { try { return JSON.parse(raw); } catch { return fallback; } };
export function chartSeries(points, key) { return points.map(point => finiteNumber(point?.[key])); }
export function diffToColor(value) {
    const n = finiteNumber(value);
    if (n === null) return null;
    if (Math.abs(n) < .02) return '#e2e8f0';
    return n < 0 ? '#16a34a' : '#dc2626';
}
function segments(raw) {
    if (!Array.isArray(raw)) return [];
    const out = [];
    for (const line of raw) {
        if (!Array.isArray(line)) continue;
        let run = [];
        const flush = () => { if (run.length > 1) out.push(run); run = []; };
        for (const point of line) {
            const lat = Array.isArray(point) ? finiteNumber(point[0]) : null;
            const lng = Array.isArray(point) ? finiteNumber(point[1]) : null;
            if (lat === null || lng === null || Math.abs(lat) > 90 || Math.abs(lng) > 180) flush();
            else run.push([lat, lng]);
        }
        flush();
    }
    return out;
}
function routeSegments(box, side) {
    const raw = box.getAttribute(`data-rc-segments-${side}`);
    const parsed = raw == null ? null : json(raw, null);
    if (Array.isArray(parsed) && parsed.length) return segments(parsed);
    return segments([json(box.getAttribute(`data-rc-${side}`))]);
}
function status(node, message) {
    let box = node.parentElement.querySelector('[data-rc-status]');
    if (!box) { box = document.createElement('p'); box.dataset.rcStatus = ''; box.setAttribute('role', 'status'); node.after(box); }
    box.textContent = message;
}
export function renderHeatmap(page) {
    const box = page.querySelector('[data-rc-heatmap]');
    if (!box) return;
    const rows = json(box.dataset.rcHeatmapData);
    const lookup = new Map((Array.isArray(rows) ? rows : []).filter(Boolean).map(row => [`${row.dow}:${row.hour}`, row]));
    const fragment = document.createDocumentFragment();
    const label = (cls, text) => { const node = document.createElement('div'); node.className = cls; node.textContent = text; fragment.append(node); };
    label('rc-heatmap__corner', '');
    for (let hour = 0; hour < 24; hour++) label('rc-heatmap__hour-label', String(hour).padStart(2, '0'));
    for (let day = 0; day < 7; day++) {
        label('rc-heatmap__dow-label', DAYS[day]);
        for (let hour = 0; hour < 24; hour++) {
            const row = lookup.get(`${day}:${hour}`), diff = finiteNumber(row?.diff);
            const cell = document.createElement('button'); cell.type = 'button';
            cell.className = 'rc-heatmap__cell' + (diff === null ? ' rc-heatmap__cell--empty' : '');
            const text = diff === null ? 'Sem amostras' : Math.abs(diff) < .02 ? 'Empate técnico' : diff < 0 ? 'A com menor atraso relativo' : 'B com menor atraso relativo';
            cell.title = `${DAYS[day]} ${hour}h · ${text}`;
            if (diff !== null) { cell.style.background = diffToColor(diff); cell.title += ` · diferença ${(diff * 100).toFixed(1)} p.p.`; }
            cell.setAttribute('aria-label', cell.title); cell.tabIndex = day === 0 && hour === 0 ? 0 : -1;
            fragment.append(cell);
        }
    }
    box.replaceChildren(fragment);
}
function initMap(page, state) {
    const box = page.querySelector('[data-rc-map]');
    if (!box || state.mapDone) return true;
    if (typeof window.L?.map !== 'function') return false;
    const routes = [routeSegments(box, 'a'), routeSegments(box, 'b')];
    if (!routes.some(lines => lines.length)) { status(box, 'Sem traçado geográfico disponível.'); state.mapDone = true; return true; }
    try {
        state.map = window.L.map(box, { preferCanvas: true });
        window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }).addTo(state.map);
        const names = [...page.querySelectorAll('.rc-vs__name')].map(node => node.textContent);
        const bounds = window.L.latLngBounds([]);
        routes.forEach((lines, index) => lines.forEach(line => {
            const popup = document.createElement('strong'); popup.textContent = names[index] || `Rota ${index ? 'B' : 'A'}`;
            const layer = window.L.polyline(line, { color: index ? '#ea580c' : '#2563eb', weight: index ? 5 : 6, opacity: .9, dashArray: index ? '8 6' : null, lineCap: 'round', lineJoin: 'round' }).bindPopup(popup).addTo(state.map);
            bounds.extend(layer.getBounds());
        }));
        if (bounds.isValid()) state.map.fitBounds(bounds, { padding: [24, 24], maxZoom: 15 });
        if (routes.some(lines => !lines.length)) status(box, 'Uma das rotas não tem traçado disponível.');
        if (typeof ResizeObserver === 'function') { state.resize = new ResizeObserver(() => state.map?.invalidateSize()); state.resize.observe(box); }
        requestAnimationFrame(() => state.map?.invalidateSize());
        state.mapDone = true;
    } catch (error) { state.map?.remove(); state.map = null; state.mapDone = true; status(box, 'Não foi possível carregar o mapa.'); console.error('[route-compare]', error); }
    return true;
}
function initCharts(page, state) {
    const canvases = [...page.querySelectorAll('[data-chart^="rc-"]')];
    if (!canvases.length) return true;
    if (typeof window.Chart !== 'function') return false;
    const names = [...page.querySelectorAll('.rc-vs__name')].map(node => node.textContent);
    for (const canvas of canvases) {
        const rows = json(canvas.dataset.chartData), points = Array.isArray(rows) ? rows : [];
        const a = chartSeries(points, 'aDelay'), b = chartSeries(points, 'bDelay');
        state.charts.get(canvas)?.destroy(); state.charts.delete(canvas);
        if (![...a, ...b].some(n => n !== null)) { canvas.hidden = true; status(canvas, 'Sem dados para este gráfico.'); continue; }
        canvas.hidden = false;
        const kind = canvas.dataset.chart;
        const labels = points.map(point => {
            if (kind === 'rc-by-hour') return `${point.hour}h`;
            if (kind === 'rc-by-dow') return DAYS[point.dow] || '—';
            const date = new Date(point.time);
            return Number.isFinite(date.getTime()) ? new Intl.DateTimeFormat(state.locale, { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', timeZone: 'America/Sao_Paulo' }).format(date) : String(point.time);
        });
        const colors = ['#2563eb', '#ea580c'];
        const css = getComputedStyle(page), text = css.getPropertyValue('--rc-text').trim() || '#475569', grid = css.getPropertyValue('--rc-line').trim() || '#e2e8f0';
        try {
            const chart = new window.Chart(canvas, { type: kind === 'rc-timeline' ? 'line' : 'bar', data: { labels, datasets: [a, b].map((data, i) => ({ label: names[i] || `Rota ${i ? 'B' : 'A'}`, data, borderColor: colors[i], backgroundColor: colors[i], borderWidth: 2, pointRadius: 0, spanGaps: false })) }, options: { responsive: true, maintainAspectRatio: false, animation: false, interaction: { mode: 'index', intersect: false }, plugins: { legend: { labels: { color: text } } }, scales: { x: { ticks: { color: text, maxTicksLimit: 10, maxRotation: 0 }, grid: { display: false } }, y: { beginAtZero: true, ticks: { color: text }, grid: { color: grid }, title: { display: true, text: 'Atraso (segundos)', color: text } } } } });
            state.charts.set(canvas, chart);
        } catch (error) { canvas.hidden = true; status(canvas, 'Gráfico indisponível.'); console.error('[route-compare]', error); }
    }
    return true;
}
export function initRouteCompare(root = document) {
    const page = root.matches?.('[data-route-compare]') ? root : root.querySelector('[data-route-compare]');
    if (!page || states.has(page)) return;
    const state = { abort: new AbortController(), charts: new Map(), map: null, mapDone: false, resize: null, timer: null, locale: (page.dataset.rcLocale || 'pt-BR').replace('_', '-') };
    states.set(page, state);
    const signal = state.abort.signal;
    const form = page.querySelector('[data-rc-form]'), a = page.querySelector('[data-rc-select-a]'), b = page.querySelector('[data-rc-select-b]');
    if (form && a && b) {
        const validate = () => b.setCustomValidity(a.value && a.value === b.value ? 'Selecione duas rotas diferentes.' : '');
        for (const select of [a, b]) select.addEventListener('change', validate, { signal });
        form.addEventListener('submit', event => { validate(); if (!form.reportValidity()) event.preventDefault(); }, { signal });
        page.querySelector('[data-rc-swap]')?.addEventListener('click', () => { [a.value, b.value] = [b.value, a.value]; validate(); }, { signal });
    }
    renderHeatmap(page);
    const heat = page.querySelector('[data-rc-heatmap]');
    heat?.addEventListener('keydown', event => {
        const cells = [...heat.querySelectorAll('button')], index = cells.indexOf(event.target);
        if (index < 0) return;
        const moves = { ArrowRight: 1, ArrowLeft: -1, ArrowDown: 24, ArrowUp: -24 };
        let next;
        if (Object.hasOwn(moves, event.key)) next = Math.max(0, Math.min(cells.length - 1, index + moves[event.key]));
        else if (event.key === 'Home') next = Math.floor(index / 24) * 24;
        else if (event.key === 'End') next = Math.floor(index / 24) * 24 + 23;
        else return;
        event.preventDefault(); cells[index].tabIndex = -1; cells[next].tabIndex = 0; cells[next].focus();
    }, { signal });
    const hydrate = attempt => {
        if (signal.aborted || !page.isConnected) return;
        if (!state.chartsDone) state.chartsDone = initCharts(page, state);
        const ready = initMap(page, state);
        if (state.chartsDone && ready) return;
        if (attempt < 30) state.timer = setTimeout(() => hydrate(attempt + 1), 150);
        else {
            if (!state.chartsDone) for (const canvas of page.querySelectorAll('canvas')) { canvas.hidden = true; status(canvas, 'Biblioteca de gráficos indisponível.'); }
            if (!ready) { const box = page.querySelector('[data-rc-map]'); if (box) status(box, 'Biblioteca do mapa indisponível.'); }
        }
    };
    hydrate(0);
    window.addEventListener('wazebr:themechange', () => initCharts(page, state), { signal });
    window.addEventListener('pagehide', () => { state.abort.abort(); clearTimeout(state.timer); state.resize?.disconnect(); state.charts.forEach(chart => chart.destroy()); state.map?.remove(); states.delete(page); }, { once: true, signal });
}
export default initRouteCompare;
if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => initRouteCompare(), { once: true });
    else initRouteCompare();
    window.addEventListener('pageshow', event => { if (event.persisted) initRouteCompare(); });
}
