/**
 * pages/route-compare.js — Página /routes/compare
 */

const COLORS = {
    blue:   '#2563eb',
    orange: '#ea580c',
    green:  '#16a34a',
    red:    '#dc2626',
    grid:   'rgba(148, 163, 184, .25)',
    text:   '#475569',
};

const DOW_LABELS = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];

const charts = new Map();

// ─────────────────────────────────────────────────────────────────────────
// Utils
// ─────────────────────────────────────────────────────────────────────────

function safeJson(str, fallback) {
    try { return JSON.parse(str); } catch { return fallback; }
}

function destroyChart(key) {
    const c = charts.get(key);
    if (c) { c.destroy(); charts.delete(key); }
}

function mountChart(key, canvas, config) {
    if (!canvas || typeof Chart === 'undefined') return;
    destroyChart(key);
    charts.set(key, new Chart(canvas.getContext('2d'), config));
}

/**
 * diff = ratioA - ratioB
 *   > 0  → A pior (vermelho, B ganha)
 *   < 0  → A melhor (verde, A ganha)
 *   ~ 0  → empate (cinza)
 */
function diffToColor(diff) {
    if (diff === null || diff === undefined) return null;
    const d = Math.max(-0.5, Math.min(0.5, diff));
    if (Math.abs(d) < 0.03) return '#e2e8f0';
    if (d > 0) {
        const t = d / 0.5;
        return `hsl(0, ${50 + t * 25}%, ${58 - t * 12}%)`;
    }
    const t = -d / 0.5;
    return `hsl(140, ${55 + t * 20}%, ${44 - t * 4}%)`;
}

function fmtPct(r) {
    if (r === null || r === undefined) return '—';
    return `${Math.round(r * 100)}%`;
}

// ─────────────────────────────────────────────────────────────────────────
// Heatmap diferencial
// ─────────────────────────────────────────────────────────────────────────

function renderHeatmap(root) {
    const container = root.querySelector('[data-rc-heatmap]');
    if (!container) return;

    const data = safeJson(container.dataset.rcHeatmapData, []) || [];
    const lookup = new Map();
    data.forEach((c) => lookup.set(`${c.dow}:${c.hour}`, c));

    const html = [];
    html.push('<div class="rc-heatmap__corner"></div>');
    for (let h = 0; h < 24; h++) {
        html.push(`<div class="rc-heatmap__hour-label">${String(h).padStart(2, '0')}</div>`);
    }

    for (let d = 0; d < 7; d++) {
        html.push(`<div class="rc-heatmap__dow-label">${DOW_LABELS[d]}</div>`);

        for (let h = 0; h < 24; h++) {
            const c = lookup.get(`${d}:${h}`);
            const hasDiff = c && c.diff !== null;

            if (!hasDiff) {
                html.push(`<div class="rc-heatmap__cell rc-heatmap__cell--empty" title="${DOW_LABELS[d]} ${String(h).padStart(2,'0')}h · sem amostras"></div>`);
                continue;
            }

            const color = diffToColor(c.diff);
            const winnerLabel = Math.abs(c.diff) < 0.03
                ? 'Empate técnico'
                : (c.diff > 0 ? 'Rota B melhor' : 'Rota A melhor');

            const title = [
                `${DOW_LABELS[d]} ${String(h).padStart(2, '0')}h`,
                `A: ${fmtPct(c.aRatio)} · B: ${fmtPct(c.bRatio)}`,
                `Diferença: ${(c.diff * 100).toFixed(0)}%`,
                winnerLabel,
            ].join(' · ');

            html.push(`<div class="rc-heatmap__cell" style="background:${color}" title="${title}"></div>`);
        }
    }

    container.innerHTML = html.join('');
}

// ─────────────────────────────────────────────────────────────────────────
// Gráficos
// ─────────────────────────────────────────────────────────────────────────

function initCharts(root) {
    if (typeof Chart === 'undefined') return;

    Chart.defaults.color = COLORS.text;
    Chart.defaults.font.family = "Inter, system-ui, -apple-system, 'Segoe UI', sans-serif";
    Chart.defaults.font.size = 12;

    // ── Timeline ─────────────────────────────────────────────
    root.querySelectorAll('[data-chart="rc-timeline"]').forEach((canvas) => {
        const points = safeJson(canvas.dataset.chartData, []) || [];

        const labels = points.map((p) => {
            const t = (p.time || '');
            return t.length >= 16 ? `${t.slice(8, 10)}/${t.slice(5, 7)} ${t.slice(11, 16)}` : t;
        });
        const aDelay = points.map((p) => p.aDelay);
        const bDelay = points.map((p) => p.bDelay);

        mountChart('rcTimeline', canvas, {
            type: 'line',
            data: {
                labels,
                datasets: [
                    {
                        label: 'Rota A',
                        data: aDelay,
                        borderColor: COLORS.blue,
                        backgroundColor: 'rgba(37,99,235,.10)',
                        fill: true, tension: 0.35, pointRadius: 0, borderWidth: 2,
                    },
                    {
                        label: 'Rota B',
                        data: bDelay,
                        borderColor: COLORS.orange,
                        backgroundColor: 'rgba(234,88,12,.10)',
                        fill: true, tension: 0.35, pointRadius: 0, borderWidth: 2,
                    },
                ],
            },
            options: {
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const v = ctx.parsed.y;
                                return `${ctx.dataset.label}: +${v?.toFixed(0) ?? 0}s`;
                            },
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { grid: { color: COLORS.grid }, beginAtZero: true, title: { display: true, text: 'segundos' } },
                },
            },
        });
    });

    // ── Por hora ─────────────────────────────────────────────
    root.querySelectorAll('[data-chart="rc-by-hour"]').forEach((canvas) => {
        const points = safeJson(canvas.dataset.chartData, []) || [];

        const labels = points.map((p) => `${String(p.hour).padStart(2, '0')}h`);
        const aDelay = points.map((p) => p.aDelay ?? 0);
        const bDelay = points.map((p) => p.bDelay ?? 0);

        mountChart('rcByHour', canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [
                    { label: 'Rota A', data: aDelay, backgroundColor: COLORS.blue,   borderRadius: 3 },
                    { label: 'Rota B', data: bDelay, backgroundColor: COLORS.orange, borderRadius: 3 },
                ],
            },
            options: {
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const i = ctx.dataIndex;
                                const p = points[i];
                                const isA = ctx.dataset.label === 'Rota A';
                                const ratio = isA ? p.aRatio : p.bRatio;
                                return `${ctx.dataset.label}: +${Math.round(ctx.parsed.y)}s · ${fmtPct(ratio)}`;
                            },
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { grid: { color: COLORS.grid }, beginAtZero: true, title: { display: true, text: 'segundos' } },
                },
            },
        });
    });

    // ── Por dia ──────────────────────────────────────────────
    root.querySelectorAll('[data-chart="rc-by-dow"]').forEach((canvas) => {
        const points = safeJson(canvas.dataset.chartData, []) || [];

        const labels = points.map((p) => DOW_LABELS[p.dow] ?? '?');
        const aDelay = points.map((p) => p.aDelay ?? 0);
        const bDelay = points.map((p) => p.bDelay ?? 0);

        mountChart('rcByDow', canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [
                    { label: 'Rota A', data: aDelay, backgroundColor: COLORS.blue,   borderRadius: 3 },
                    { label: 'Rota B', data: bDelay, backgroundColor: COLORS.orange, borderRadius: 3 },
                ],
            },
            options: {
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const i = ctx.dataIndex;
                                const p = points[i];
                                const isA = ctx.dataset.label === 'Rota A';
                                const ratio = isA ? p.aRatio : p.bRatio;
                                return `${ctx.dataset.label}: +${Math.round(ctx.parsed.y)}s · ${fmtPct(ratio)}`;
                            },
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { grid: { color: COLORS.grid }, beginAtZero: true, title: { display: true, text: 'segundos' } },
                },
            },
        });
    });
}

// ─────────────────────────────────────────────────────────────────────────
// Mapa comparativo
// ─────────────────────────────────────────────────────────────────────────

let map = null;

function initMap(root, attempt = 0) {
    const container = root.querySelector('[data-rc-map]');
    if (!container) return;

    if (map) return;

    // ── Guard: Leaflet pode não estar pronto quando o registry dispara o init.
    // Tenta por até ~2s (10 × 200ms).
    if (typeof L === 'undefined') {
        if (attempt < 10) {
            setTimeout(() => initMap(root, attempt + 1), 200);
        } else {
            console.warn('[WazeBR compare] Leaflet não carregou a tempo.');
            showMapFallback(container);
        }
        return;
    }

    const aPoly = safeJson(container.dataset.rcA, []) || [];
    const bPoly = safeJson(container.dataset.rcB, []) || [];

    // Sem traçado → mostra fallback em vez de mapa vazio
    if (aPoly.length < 2 && bPoly.length < 2) {
        showMapFallback(container);
        return;
    }

    try {
        map = L.map(container, {
            zoomControl: true,
            preferCanvas: true,
            center: [-20.66, -43.78],
            zoom: 12,
        });

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap',
        }).addTo(map);

        // A — azul sólido
        if (aPoly.length > 1) {
            L.polyline(aPoly, {
                color: COLORS.blue,
                weight: 6,
                opacity: 0.9,
                lineCap: 'round',
                lineJoin: 'round',
            }).bindPopup('<div class="rc-popup"><strong>Rota A</strong></div>').addTo(map);
        }

        // B — laranja tracejado, pra ficar visível quando sobrepõem
        if (bPoly.length > 1) {
            L.polyline(bPoly, {
                color: COLORS.orange,
                weight: 5,
                opacity: 0.9,
                dashArray: '8 6',
                lineCap: 'round',
                lineJoin: 'round',
            }).bindPopup('<div class="rc-popup"><strong>Rota B</strong></div>').addTo(map);
        }

        // Fit nas duas
        const all = [...aPoly, ...bPoly];
        if (all.length > 0) {
            try {
                map.fitBounds(all, { padding: [24, 24], maxZoom: 15 });
            } catch {
                // geometria inválida → ignora, fica no centro default
            }
        }

        // ── CRÍTICO: o container pode ter ganho layout só depois do init.
        // Força o recálculo de dimensões em dois momentos.
        setTimeout(() => map && map.invalidateSize(), 80);
        setTimeout(() => map && map.invalidateSize(), 300);

        window.addEventListener('resize', () => map && map.invalidateSize());

    } catch (err) {
        console.error('[WazeBR compare] falha ao montar o mapa', err);
        showMapFallback(container);
    }
}

function showMapFallback(container) {
    const fb = container.querySelector('[data-rc-map-fallback]');
    if (fb) fb.hidden = false;
}

// ─────────────────────────────────────────────────────────────────────────
// Picker
// ─────────────────────────────────────────────────────────────────────────

function initPicker(root) {
    const form = root.querySelector('[data-rc-form]');
    const selA = root.querySelector('[data-rc-select-a]');
    const selB = root.querySelector('[data-rc-select-b]');
    const swap = root.querySelector('[data-rc-swap]');

    if (!form || form.dataset.rcPickerInit === '1') return;
    form.dataset.rcPickerInit = '1';

    // Auto-submit ao trocar select, desde que ambos preenchidos e diferentes
    const maybeSubmit = () => {
        const a = selA?.value ?? '';
        const b = selB?.value ?? '';
        if (a && b && a !== b) form.submit();
    };

    selA?.addEventListener('change', maybeSubmit);
    selB?.addEventListener('change', maybeSubmit);

    swap?.addEventListener('click', (e) => {
        e.preventDefault();
        if (!selA || !selB) return;
        const tmp = selA.value;
        selA.value = selB.value;
        selB.value = tmp;
        if (selA.value && selB.value) form.submit();
    });
}

// ─────────────────────────────────────────────────────────────────────────
// Bootstrap
// ─────────────────────────────────────────────────────────────────────────

export function initRouteCompare(root = document) {
    const page = root.querySelector('[data-route-compare]');
    if (!page) return;

    // Picker — só roda uma vez
    if (!page.dataset.rcPicker) {
        page.dataset.rcPicker = '1';
        initPicker(page);
    }

    // Resto só existe quando o compare foi feito
    if (!page.querySelector('[data-rc-heatmap]')) return;

    if (!page.dataset.rcHeatmap) {
        page.dataset.rcHeatmap = '1';
        renderHeatmap(page);
    }

    if (!page.dataset.rcCharts) {
        page.dataset.rcCharts = '1';
        initCharts(page);
    }

    // Mapa é idempotente (retorna cedo se `map` já existe)
    initMap(page);
}

export default initRouteCompare;
