/**
 * pages/weather-show.js — Página /weather/{id}
 *
 * - Heatmap dia×hora (temperatura)
 * - Timeline (temperatura + umidade, eixos duplos)
 * - Barras: por hora, por dia, chuva
 */

const COLORS = {
    blue:   '#2563eb',
    red:    '#dc2626',
    cyan:   '#0891b2',
    amber:  '#f59e0b',
    grid:   'rgba(148, 163, 184, .25)',
    text:   '#475569',
};

const DOW_LABELS = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];

const charts = new Map();

function safeJson(str, fallback) {
    try { return JSON.parse(str); } catch { return fallback; }
}

function resolvePage(root) {
    if (!root) return null;
    if (root.matches?.('[data-weather-show]')) return root;
    return root.querySelector?.('[data-weather-show]') ?? null;
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
 * Mapeia temperatura para uma cor HSL — azul (frio) → verde → amarelo → vermelho (quente).
 * Assumimos faixa útil entre -5 e 40°C.
 */
function tempToColor(temp) {
    if (temp === null || temp === undefined) return null;
    const min = -5, max = 40;
    const t = Math.max(0, Math.min(1, (temp - min) / (max - min)));

    // hue 210 (azul) → 0 (vermelho), passando por verde/amarelo
    const hue = 210 - t * 210;
    const sat = 65 + t * 10;
    const light = 55 - t * 8;

    return `hsl(${hue}, ${sat}%, ${light}%)`;
}

// ─────────────────────────────────────────────────────────────────────────
// Heatmap
// ─────────────────────────────────────────────────────────────────────────

function renderHeatmap(root) {
    const page = resolvePage(root);
    if (!page) return;

    const container = page.querySelector('[data-wx-heatmap]');
    if (!container) return;

    const data = safeJson(container.dataset.heatmapData, []) || [];

    const lookup = new Map();
    data.forEach((c) => lookup.set(`${c.dow}:${c.hour}`, c));

    const html = [];

    html.push('<div class="wx-heatmap__corner"></div>');
    for (let h = 0; h < 24; h++) {
        html.push(`<div class="wx-heatmap__hour-label">${String(h).padStart(2, '0')}</div>`);
    }

    for (let d = 0; d < 7; d++) {
        html.push(`<div class="wx-heatmap__dow-label">${DOW_LABELS[d]}</div>`);

        for (let h = 0; h < 24; h++) {
            const c = lookup.get(`${d}:${h}`);
            const hasData = c && c.count >= 2 && c.avgTemp !== null;

            if (!hasData) {
                html.push(`<div class="wx-heatmap__cell wx-heatmap__cell--empty" title="${DOW_LABELS[d]} ${String(h).padStart(2,'0')}h · sem amostras"></div>`);
                continue;
            }

            const color = tempToColor(c.avgTemp);
            const title = [
                `${DOW_LABELS[d]} ${String(h).padStart(2, '0')}h`,
                `Temp média: ${c.avgTemp.toFixed(1)}°C`,
                c.avgHum !== null ? `Umidade: ${c.avgHum}%` : null,
                c.totalRain > 0 ? `Chuva acumulada: ${c.totalRain.toFixed(1)} mm` : null,
                `${c.count} amostra${c.count !== 1 ? 's' : ''}`,
            ].filter(Boolean).join(' · ');

            html.push(`<div class="wx-heatmap__cell" style="background:${color}" title="${title}"></div>`);
        }
    }

    container.innerHTML = html.join('');
}

// ─────────────────────────────────────────────────────────────────────────
// Gráficos
// ─────────────────────────────────────────────────────────────────────────

function initCharts(root) {
    const page = resolvePage(root);
    if (!page) return;
    if (typeof Chart === 'undefined') return;

    Chart.defaults.color = COLORS.text;
    Chart.defaults.font.family = "Inter, system-ui, -apple-system, 'Segoe UI', sans-serif";
    Chart.defaults.font.size = 12;

    // ── Timeline ──────────────────────────────────────────────
    page.querySelectorAll('[data-chart="wx-timeline"]').forEach((canvas) => {
        const points = safeJson(canvas.dataset.chartData, []) || [];

        const labels = points.map((p) => {
            const t = (p.time || '');
            return t.length >= 16 ? `${t.slice(8, 10)}/${t.slice(5, 7)} ${t.slice(11, 16)}` : t;
        });
        const temp = points.map((p) => p.temperature);
        const hum  = points.map((p) => p.humidity);

        mountChart('wxTimeline', canvas, {
            type: 'line',
            data: {
                labels,
                datasets: [
                    {
                        label: 'Temperatura (°C)', data: temp,
                        borderColor: COLORS.red,
                        backgroundColor: 'rgba(220,38,38,.08)',
                        fill: true, tension: 0.35, pointRadius: 0, borderWidth: 2,
                        yAxisID: 'y',
                    },
                    {
                        label: 'Umidade (%)', data: hum,
                        borderColor: COLORS.cyan,
                        backgroundColor: 'rgba(8,145,178,.06)',
                        fill: false, tension: 0.35, pointRadius: 0, borderWidth: 2,
                        borderDash: [4, 3],
                        yAxisID: 'y1',
                    },
                ],
            },
            options: {
                responsive: true, maintainAspectRatio: false, resizeDelay: 120,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => ctx.dataset.label.includes('Temperatura')
                                ? `${ctx.parsed.y?.toFixed(1)}°C`
                                : `${ctx.parsed.y?.toFixed(0)}%`,
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false } },
                    y:  { position: 'left',  grid: { color: COLORS.grid }, title: { display: true, text: '°C' } },
                    y1: { position: 'right', grid: { display: false }, title: { display: true, text: '%' }, ticks: { max: 100 } },
                },
            },
        });
    });

    // ── Por hora ──────────────────────────────────────────────
    page.querySelectorAll('[data-chart="wx-by-hour"]').forEach((canvas) => {
        const points = safeJson(canvas.dataset.chartData, []) || [];

        const labels = points.map((p) => `${String(p.hour).padStart(2, '0')}h`);
        const temps  = points.map((p) => p.avgTemp ?? 0);
        const counts = points.map((p) => p.count ?? 0);

        const bg = temps.map((t, i) => {
            if (counts[i] < 2 || t === null) return 'rgba(148,163,184,.35)';
            return tempToColor(t);
        });

        mountChart('wxByHour', canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Temp média (°C)',
                    data: temps,
                    backgroundColor: bg,
                    borderRadius: 4,
                }],
            },
            options: {
                responsive: true, maintainAspectRatio: false, resizeDelay: 120,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const i = ctx.dataIndex;
                                const c = counts[i];
                                if (c < 2) return 'Sem amostras suficientes';
                                const hum = points[i].avgHum;
                                let s = `${ctx.parsed.y.toFixed(1)}°C`;
                                if (hum !== null && hum !== undefined) s += ` · ${hum}% umid.`;
                                return `${s} · ${c} amostras`;
                            },
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { grid: { color: COLORS.grid }, title: { display: true, text: '°C' } },
                },
            },
        });
    });

    // ── Por dia ──────────────────────────────────────────────
    page.querySelectorAll('[data-chart="wx-by-dow"]').forEach((canvas) => {
        const points = safeJson(canvas.dataset.chartData, []) || [];

        const labels = points.map((p) => DOW_LABELS[p.dow] ?? '?');
        const temps  = points.map((p) => p.avgTemp ?? 0);
        const counts = points.map((p) => p.count ?? 0);

        const bg = temps.map((t, i) => {
            if (counts[i] < 2 || t === null) return 'rgba(148,163,184,.35)';
            return tempToColor(t);
        });

        mountChart('wxByDow', canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Temp média (°C)',
                    data: temps,
                    backgroundColor: bg,
                    borderRadius: 4,
                }],
            },
            options: {
                responsive: true, maintainAspectRatio: false, resizeDelay: 120,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => `${ctx.parsed.y.toFixed(1)}°C · ${counts[ctx.dataIndex]} amostras`,
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { grid: { color: COLORS.grid }, title: { display: true, text: '°C' } },
                },
            },
        });
    });

    // ── Chuva ─────────────────────────────────────────────────
    page.querySelectorAll('[data-chart="wx-rain"]').forEach((canvas) => {
        const points = safeJson(canvas.dataset.chartData, []) || [];

        const labels = points.map((p) => {
            const t = (p.time || '');
            return t.length >= 16 ? t.slice(11, 16) : t;
        });
        const rain = points.map((p) => p.rain);

        mountChart('wxRain', canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Chuva (mm)',
                    data: rain,
                    backgroundColor: rain.map((v) => v > 0
                        ? 'rgba(8, 145, 178, .75)'
                        : 'rgba(148,163,184,.35)'),
                    borderRadius: 4,
                }],
            },
            options: {
                responsive: true, maintainAspectRatio: false, resizeDelay: 120,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => ctx.parsed.y > 0
                                ? `${ctx.parsed.y.toFixed(2)} mm`
                                : 'Sem chuva',
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false } },
                    y: {
                        grid: { color: COLORS.grid },
                        beginAtZero: true,
                        title: { display: true, text: 'mm' },
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

export function initWeatherShow(root = document) {
    const page = resolvePage(root);
    if (!page || bootstrapped) return;
    bootstrapped = true;

    renderHeatmap(page);
    initCharts(page);
}

export default initWeatherShow;
