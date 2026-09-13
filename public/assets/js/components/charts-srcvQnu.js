/**
 * components/charts.js
 * Inicializa gráficos SVG do dashboard a partir de dados em data-*.
 */
export function initCharts(doc = globalThis.document) {
    if (!doc) return;

    // ── Dashboard line chart ──────────────────────────────────────────────────
    doc.querySelectorAll('[data-dashboard-line-chart]').forEach((container) => {
        if (container.dataset.wazebrInitialized === 'true') return;
        container.dataset.wazebrInitialized = 'true';

        const dashboard = container.closest('[data-dashboard]');
        if (!dashboard) return;

        let hourly = [];
        try { hourly = JSON.parse(dashboard.dataset.hourly || '[]'); } catch { hourly = []; }

        // Fallback com totais quando não há série horária
        if (hourly.length === 0) {
            const ta = Number(dashboard.dataset.totalAlerts ?? 0);
            const tj = Number(dashboard.dataset.totalJams   ?? 0);
            hourly = Array.from({ length: 8 }, (_, i) => ({
                hour:   `${String(i).padStart(2, '0')}:00`,
                alerts: Math.max(0, Math.round(ta / 8 + ((i * 17) % 9))),
                jams:   Math.max(0, Math.round(tj / 8 + ((i * 11) % 7))),
                total:  0,
            }));
        }

        drawLineChart(container, hourly);
    });

    // ── Gráficos genéricos [data-chart] ──────────────────────────────────────
    doc.querySelectorAll('[data-chart]').forEach((chart) => {
        if (chart.dataset.wazebrInitialized === 'true') return;
        chart.dataset.wazebrInitialized = 'true';
        // espaço para outros tipos de chart no futuro
    });
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function drawLineChart(container, hourly) {
    const W = 700, H = 200, N = hourly.length;
    if (N === 0) return;

    const maxAlerts = Math.max(1, ...hourly.map((p) => p.alerts));
    const maxJams   = Math.max(1, ...hourly.map((p) => p.jams));
    const toY       = (val, max) => H - Math.round((val / max) * (H - 20)) - 5;

    const alertPts = hourly.map((p, i) => ({ x: Math.round((i / (N - 1)) * W), y: toY(p.alerts, maxAlerts) }));
    const jamPts   = hourly.map((p, i) => ({ x: Math.round((i / (N - 1)) * W), y: toY(p.jams,   maxJams)   }));

    const line = (pts) => pts.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x},${p.y}`).join(' ');
    const fill = (pts) => `M0,${H} ${pts.map((p) => `L${p.x},${p.y}`).join(' ')} L${W},${H} Z`;

    container.querySelector('[data-chart-line]')     ?.setAttribute('d', line(alertPts));
    container.querySelector('[data-chart-fill]')     ?.setAttribute('d', fill(alertPts));
    container.querySelector('[data-chart-line-jams]')?.setAttribute('d', line(jamPts));
    container.querySelector('[data-chart-fill-jams]')?.setAttribute('d', fill(jamPts));
}
