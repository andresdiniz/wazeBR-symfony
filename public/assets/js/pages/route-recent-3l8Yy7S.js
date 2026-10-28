const ROUTES_LOCALE = document.documentElement.lang || 'pt-BR';
const ROUTES_MESSAGES = (() => {
    try { return JSON.parse(document.querySelector('[data-routes-i18n]')?.dataset.routesI18n || '{}'); }
    catch { return {}; }
})();
function rt(message, parameters = {}) {
    let text = Object.hasOwn(ROUTES_MESSAGES, message) ? ROUTES_MESSAGES[message] : message;
    for (const [key, value] of Object.entries(parameters)) text = text.replaceAll(key, String(value));
    return text;
}
function routeLabel(value, fallback = '—') {
    if (value == null || value === '') return fallback;
    const text = String(value);
    for (const key of [text, `code.${text}`, `level.${text}`]) {
        if (Object.hasOwn(ROUTES_MESSAGES, key)) return ROUTES_MESSAGES[key];
    }
    return text;
}

document.addEventListener('DOMContentLoaded', () => {
    const canvas = document.querySelector('[data-chart="route-recent"]');

    if (!canvas || typeof Chart === 'undefined') {
        return;
    }

    const raw = canvas.dataset.chartData ? JSON.parse(canvas.dataset.chartData) : [];
    const labels = raw.map((point) => point.label);
    const values = raw.map((point) => point.time !== null ? point.time / 60 : null);

    const hasData = values.some((value) => value !== null);

    if (!hasData) {
        canvas.closest('.rs-chart-wrap').innerHTML = `
            <div class="rs-empty">
                <strong>${rt("Sem leituras nas últimas 6 horas")}</strong>
                <small>${rt("Os dados aparecerão quando novas coletas forem registradas.")}</small>
            </div>
        `;
        return;
    }

    new Chart(canvas, {
        type: 'line',
        data: {
            labels,
            datasets: [{
                label: rt("Tempo (min)"),
                data: values,
                borderColor: '#2563eb',
                backgroundColor: 'rgba(37, 99, 235, .12)',
                fill: true,
                tension: .35,
                spanGaps: true,
                pointRadius: 2,
                pointHoverRadius: 5,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (context) => {
                            const minutes = context.parsed.y;
                            const seconds = Math.round((minutes % 1) * 60);

                            return ` ${Math.floor(minutes)}min ${seconds}s`;
                        },
                    },
                },
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 8 },
                },
                y: {
                    beginAtZero: false,
                    title: {
                        display: true,
                        text: rt("Tempo (min)"),
                    },
                },
            },
        },
    });
});
