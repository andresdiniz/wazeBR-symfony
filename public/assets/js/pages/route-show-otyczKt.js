/**
 * pages/route-show.js — Página /routes/{id}
 *
 * - Heatmap dia × hora (renderiza grid em CSS puro)
 * - Gráficos Chart.js: timeline, por hora, por dia, distribuição de jam
 */

const COLORS = {
    blue:   '#2563eb',
    red:    '#dc2626',
    green:  '#16a34a',
    amber:  '#f59e0b',
    orange: '#ea580c',
    grid:   'rgba(148, 163, 184, .25)',
    text:   '#475569',
};

const DOW_LABELS = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];

const JAM_LABELS = [
    'Sem congestionamento',
    'Baixo',
    'Moderado',
    'Alto',
    'Muito alto',
    'Parado',
];

const JAM_COLORS = [
    '#16a34a',
    '#84cc16',
    '#f59e0b',
    '#f97316',
    '#ea580c',
    '#dc2626',
];

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

function ratioToColor(ratio) {
    if (ratio === null || ratio === undefined) return null;
    const r = Math.max(0, Math.min(ratio, 1));
    const hue   = 140 - r * 140;
    const sat   = 62 + r * 12;
    const light = 44 - r * 2;
    return `hsl(${hue}, ${sat}%, ${light}%)`;
}

function fmtRatio(r) {
    if (r === null || r === undefined) return '—';
    return `${Math.round(r * 100)}%`;
}

// ─────────────────────────────────────────────────────────────────────────
// Heatmap
// ─────────────────────────────────────────────────────────────────────────

function renderHeatmap(root) {
    const container = root.querySelector('[data-heatmap]');
    if (!container) return;

    const data = safeJson(container.dataset.heatmapData, []) || [];

    const lookup = new Map();
    data.forEach((c) => {
        lookup.set(`${c.dow}:${c.hour}`, c);
    });

    const html = [];

    html.push('<div class="rs-heatmap__corner"></div>');
    for (let h = 0; h < 24; h++) {
        html.push(`<div class="rs-heatmap__hour-label">${String(h).padStart(2, '0')}</div>`);
    }

    for (let d = 0; d < 7; d++) {
        html.push(`<div class="rs-heatmap__dow-label">${DOW_LABELS[d]}</div>`);

        for (let h = 0; h < 24; h++) {
            const c = lookup.get(`${d}:${h}`);
            const hasData = c && c.count >= 2 && c.avgRatio !== null;

            if (!hasData) {
                html.push(`<div class="rs-heatmap__cell rs-heatmap__cell--empty" title="${DOW_LABELS[d]} ${String(h).padStart(2,'0')}h · sem amostras"></div>`);
                continue;
            }

            const color = ratioToColor(c.avgRatio);
            const title = [
                `${DOW_LABELS[d]} ${String(h).padStart(2, '0')}h`,
                `Média: ${fmtRatio(c.avgRatio)} acima do histórico`,
                c.avgDelay !== null ? `Atraso médio: +${Math.round(c.avgDelay)}s` : null,
                `${c.count} amostra${c.count !== 1 ? 's' : ''}`,
            ].filter(Boolean).join(' · ');

            html.push(`<div class="rs-heatmap__cell" style="background:${color}" title="${title}"></div>`);
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

    // ── Timeline ────────────────────────────────────────────────
    root.querySelectorAll('[data-chart="route-timeline"]').forEach((canvas) => {
        const points = safeJson(canvas.dataset.chartData, []) || [];

        const labels = points.map((p) => {
            const t = (p.time || '');
            return t.length >= 16 ? `${t.slice(8, 10)}/${t.slice(5, 7)} ${t.slice(11, 16)}` : t;
        });
        const delay = points.map((p) => p.avgDelay);
        const ratio = points.map((p) => p.avgRatio !== null ? p.avgRatio * 100 : null);

        mountChart('routeTimeline', canvas, {
            type: 'line',
            data: {
                labels,
                datasets: [
                    {
                        label: 'Atraso (s)',
                        data: delay,
                        borderColor: COLORS.blue,
                        backgroundColor: 'rgba(37,99,235,.10)',
                        fill: true,
                        tension: 0.35,
                        pointRadius: 0,
                        borderWidth: 2,
                        yAxisID: 'y',
                    },
                    {
                        label: '% vs histórico',
                        data: ratio,
                        borderColor: COLORS.red,
                        backgroundColor: 'rgba(220,38,38,.08)',
                        fill: false,
                        tension: 0.35,
                        pointRadius: 0,
                        borderWidth: 2,
                        borderDash: [4, 3],
                        yAxisID: 'y1',
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
                            label: (ctx) => ctx.dataset.label === '% vs histórico'
                                ? `${ctx.parsed.y?.toFixed(0)}%`
                                : `+${ctx.parsed.y?.toFixed(0)}s`,
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false } },
                    y:  { position: 'left',  grid: { color: COLORS.grid }, beginAtZero: true, title: { display: true, text: 'segundos' } },
                    y1: { position: 'right', grid: { display: false }, beginAtZero: true, ticks: { callback: (v) => `${v}%` } },
                },
            },
        });
    });

    // ── Por hora ────────────────────────────────────────────────
    root.querySelectorAll('[data-chart="route-by-hour"]').forEach((canvas) => {
        const points = safeJson(canvas.dataset.chartData, []) || [];

        const labels = points.map((p) => `${String(p.hour).padStart(2, '0')}h`);
        const ratios = points.map((p) => p.avgRatio);
        const delays = points.map((p) => p.avgDelay ?? 0);
        const counts = points.map((p) => p.count ?? 0);

        const bgColors = ratios.map((r, i) => {
            if (counts[i] < 2 || r === null) return 'rgba(148,163,184,.35)';
            return ratioToColor(r);
        });

        mountChart('routeByHour', canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Atraso médio (s)',
                    data: delays,
                    backgroundColor: bgColors,
                    borderRadius: 4,
                }],
            },
            options: {
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const i = ctx.dataIndex;
                                const r = ratios[i];
                                const d = delays[i];
                                const c = counts[i];
                                if (c < 2 || r === null) return 'Sem amostras suficientes';
                                return `+${Math.round(d)}s · ${Math.round(r * 100)}% vs histórico · ${c} amostras`;
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

    // ── Por dia da semana ──────────────────────────────────────
    root.querySelectorAll('[data-chart="route-by-dow"]').forEach((canvas) => {
        const points = safeJson(canvas.dataset.chartData, []) || [];

        const labels = points.map((p) => DOW_LABELS[p.dow] ?? '?');
        const ratios = points.map((p) => p.avgRatio);
        const delays = points.map((p) => p.avgDelay ?? 0);
        const counts = points.map((p) => p.count ?? 0);

        const bgColors = ratios.map((r, i) => {
            if (counts[i] < 2 || r === null) return 'rgba(148,163,184,.35)';
            return ratioToColor(r);
        });

        mountChart('routeByDow', canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Atraso médio (s)',
                    data: delays,
                    backgroundColor: bgColors,
                    borderRadius: 4,
                }],
            },
            options: {
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const i = ctx.dataIndex;
                                const r = ratios[i];
                                const d = delays[i];
                                const c = counts[i];
                                if (c < 2 || r === null) return 'Sem amostras suficientes';
                                return `+${Math.round(d)}s · ${Math.round(r * 100)}% vs histórico · ${c} amostras`;
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

    // ── Distribuição de jam ────────────────────────────────────
    root.querySelectorAll('[data-chart="route-jam-dist"]').forEach((canvas) => {
        const points = safeJson(canvas.dataset.chartData, []) || [];

        const filtered = points.filter((p) => p.count > 0);
        const labels = filtered.map((p) => JAM_LABELS[p.level] ?? `Nível ${p.level}`);
        const data   = filtered.map((p) => p.count);
        const bg     = filtered.map((p) => JAM_COLORS[p.level] ?? '#94a3b8');

        mountChart('routeJamDist', canvas, {
            type: 'doughnut',
            data: {
                labels,
                datasets: [{
                    data,
                    backgroundColor: bg,
                    borderWidth: 0,
                }],
            },
            options: {
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: { position: 'right', labels: { usePointStyle: true, boxWidth: 8 } },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                const pct = total > 0 ? Math.round((ctx.parsed / total) * 100) : 0;
                                return `${ctx.parsed} amostras (${pct}%)`;
                            },
                        },
                    },
                },
            },
        });
    });
}

// ─────────────────────────────────────────────────────────────────────────
// Bootstrap
// ─────────────────────────────────────────────────────────────────────────

let bootstrapped = false;

export function initRouteShow(root = document) {
    const page = root.querySelector('[data-route-show]');
    if (!page || bootstrapped) return;
    bootstrapped = true;

    renderHeatmap(page);
    initCharts(page);
}

export default initRouteShow;
