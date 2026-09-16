/**
 * pages/weather-analysis.js — Página /weather/analysis
 *
 * - Scatter: variável climática × métrica de trânsito
 * - Barras: médias da métrica por faixa da variável
 * - Picker auto-submit
 */

const COLORS = {
    blue:   '#2563eb',
    red:    '#dc2626',
    green:  '#16a34a',
    amber:  '#f59e0b',
    grid:   'rgba(148, 163, 184, .25)',
    text:   '#475569',
};

const charts = new Map();

const VARIABLE_LABELS = {
    temperature:       'Temperatura (°C)',
    precipitation:     'Chuva (mm)',
    relative_humidity: 'Umidade (%)',
    wind_speed:        'Vento (km/h)',
};

const METRIC_LABELS = {
    route_delay: 'Atraso médio (%)',
    alert_count: 'Alertas (por hora)',
    jam_count:   'Jams (por hora)',
};

function safeJson(str, fallback) {
    try { return JSON.parse(str); } catch { return fallback; }
}

function resolvePage(root) {
    if (!root) return null;
    if (root.matches?.('[data-weather-analysis]')) return root;
    return root.querySelector?.('[data-weather-analysis]') ?? null;
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
 * Calcula linha de tendência simples via mínimos quadrados.
 * Retorna { slope, intercept } ou null.
 */
function trendLine(points) {
    const n = points.length;
    if (n < 3) return null;

    const xs = points.map((p) => p.x);
    const ys = points.map((p) => p.y);

    const meanX = xs.reduce((a, b) => a + b, 0) / n;
    const meanY = ys.reduce((a, b) => a + b, 0) / n;

    let num = 0, den = 0;
    for (let i = 0; i < n; i++) {
        num += (xs[i] - meanX) * (ys[i] - meanY);
        den += (xs[i] - meanX) * (xs[i] - meanX);
    }

    if (den <= 1e-12) return null;

    const slope = num / den;
    const intercept = meanY - slope * meanX;

    return { slope, intercept };
}

// ─────────────────────────────────────────────────────────────────────────
// Scatter
// ─────────────────────────────────────────────────────────────────────────

function initScatter(page, params) {
    page.querySelectorAll('[data-chart="wx-scatter"]').forEach((canvas) => {
        const points = safeJson(canvas.dataset.chartData, []) || [];
        if (points.length === 0) return;

        const data = points.map((p) => ({ x: p.x, y: p.y }));
        const trend = trendLine(data);

        const datasets = [
            {
                label: 'Horas',
                data,
                backgroundColor: 'rgba(37, 99, 235, .55)',
                borderColor: 'rgba(37, 99, 235, .9)',
                pointRadius: 4,
                pointHoverRadius: 6,
                type: 'scatter',
            },
        ];

        // Linha de tendência
        if (trend) {
            const xs = data.map((p) => p.x);
            const minX = Math.min(...xs);
            const maxX = Math.max(...xs);

            datasets.push({
                label: 'Tendência',
                data: [
                    { x: minX, y: trend.slope * minX + trend.intercept },
                    { x: maxX, y: trend.slope * maxX + trend.intercept },
                ],
                type: 'line',
                borderColor: COLORS.red,
                backgroundColor: 'transparent',
                borderWidth: 2,
                borderDash: [6, 4],
                pointRadius: 0,
                tension: 0,
                fill: false,
            });
        }

        mountChart('wxScatter', canvas, {
            type: 'scatter',
            data: { datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                resizeDelay: 120,
                animation: { duration: 300 },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { usePointStyle: true, boxWidth: 8 },
                    },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const p = ctx.raw;
                                return `${VARIABLE_LABELS[params.variable] ?? 'X'}: ${p.x.toFixed(1)} · ${METRIC_LABELS[params.metric] ?? 'Y'}: ${p.y.toFixed(2)}`;
                            },
                        },
                    },
                },
                scales: {
                    x: {
                        grid: { color: COLORS.grid },
                        title: { display: true, text: VARIABLE_LABELS[params.variable] ?? params.variable },
                    },
                    y: {
                        grid: { color: COLORS.grid },
                        title: { display: true, text: METRIC_LABELS[params.metric] ?? params.metric },
                        beginAtZero: true,
                    },
                },
            },
        });
    });
}

// ─────────────────────────────────────────────────────────────────────────
// Bins
// ─────────────────────────────────────────────────────────────────────────

function initBins(page) {
    page.querySelectorAll('[data-chart="wx-bins"]').forEach((canvas) => {
        const bins = safeJson(canvas.dataset.chartData, []) || [];
        if (bins.length === 0) return;

        const labels = bins.map((b) => b.label);
        const values = bins.map((b) => b.avgY);
        const counts = bins.map((b) => b.count);

        const maxY = Math.max(...values, 1);
        const bg = values.map((v) => {
            const t = Math.max(0, Math.min(1, v / maxY));
            const hue = 210 - t * 210;
            return `hsl(${hue}, 65%, 55%)`;
        });

        mountChart('wxBins', canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Média',
                    data: values,
                    backgroundColor: bg,
                    borderRadius: 4,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                resizeDelay: 120,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const i = ctx.dataIndex;
                                return `Média: ${values[i].toFixed(2)} · ${counts[i]} amostras`;
                            },
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false } },
                    y: {
                        grid: { color: COLORS.grid },
                        beginAtZero: true,
                    },
                },
            },
        });
    });
}

// ─────────────────────────────────────────────────────────────────────────
// Picker
// ─────────────────────────────────────────────────────────────────────────

function initPicker(page) {
    const form = page.querySelector('[data-wx-form]');
    if (!form || form.dataset.wxPickerInit === '1') return;
    form.dataset.wxPickerInit = '1';

    // Auto-submit ao trocar qualquer select
    form.querySelectorAll('select').forEach((sel) => {
        sel.addEventListener('change', () => form.submit());
    });

    form.addEventListener('submit', (e) => {
        // Nada de especial — submit normal
    });
}

// ─────────────────────────────────────────────────────────────────────────
// Bootstrap
// ─────────────────────────────────────────────────────────────────────────

let bootstrapped = false;

export function initWeatherAnalysis(root = document) {
    const page = resolvePage(root);
    if (!page || bootstrapped) return;
    bootstrapped = true;

    const params = {
        variable: page.dataset.wxVariable || 'temperature',
        metric:   page.dataset.wxMetric   || 'route_delay',
    };

    initPicker(page);
    initScatter(page, params);
    initBins(page);
}

export default initWeatherAnalysis;
