/**
 * Previsão da rota: comparação entre tempo estimado e histórico.
 * Inicializado pelo registro routeForecast em assets/js/app.js.
 */

let forecastChart = null;

function numberOrNull(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const number = Number(value);
    return Number.isFinite(number) ? number : null;
}

function formatSeconds(value) {
    return value === null
        ? '—'
        : `${Math.round(value)} s`;
}

function formatDate(value, options) {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return 'Horário inválido';
    }

    return date.toLocaleString('pt-BR', {
        timeZone: 'America/Sao_Paulo',
        ...options,
    });
}

function normalizeItem(item) {
    const samples = Math.max(
        0,
        Math.trunc(numberOrNull(item.samples) ?? 0)
    );

    const time = numberOrNull(item.avgTime);
    const historic = numberOrNull(item.avgHistoricTime);

    const hasData =
        samples > 0 &&
        time !== null &&
        time >= 0 &&
        historic !== null &&
        historic > 0;

    return {
        forecastAt: item.forecastAt,
        samples,
        hasData,
        time: hasData ? time : null,
        historic: hasData ? historic : null,
        delay: hasData ? time - historic : null,
    };
}

function createPanel(page) {
    let panel = page.querySelector('#route-forecast');

    if (!panel) {
        const kpis = page.querySelector('.rs-kpis');

        if (!kpis) {
            throw new Error('Seção de KPIs não encontrada.');
        }

        panel = document.createElement('section');
        panel.id = 'route-forecast';
        panel.className = 'rs-panel';

        kpis.insertAdjacentElement('afterend', panel);
    }

    panel.innerHTML = `
        <header class="rs-panel__header">
            <div>
                <span class="rs-panel__kicker">
                    Próximas 6 horas · média do mesmo dia e horário
                </span>
                <h2>Estimativa de tempo de percurso</h2>
            </div>
        </header>

        <p class="rs-panel__hint"
           data-forecast-status
           role="status"
           aria-live="polite">
            Carregando estimativa…
        </p>

        <div data-forecast-chart-wrap
             style="position: relative; width: 100%; height: 300px;"
             hidden>
            <canvas
                data-forecast-chart
                role="img"
                aria-label="Comparação entre tempo estimado e histórico">
            </canvas>
        </div>

        <p class="rs-panel__hint">
            Linha azul: estimativa histórica para o horário.
            Linha tracejada: referência histórica.
            Horários sem amostras não são tratados como trânsito livre.
        </p>
    `;

    return panel;
}

function drawChart(panel, items) {
    const canvas = panel.querySelector('[data-forecast-chart]');
    const wrapper = panel.querySelector('[data-forecast-chart-wrap]');
    const status = panel.querySelector('[data-forecast-status]');

    const validItems = items.filter((item) => item.hasData);

    if (forecastChart) {
        forecastChart.destroy();
        forecastChart = null;
    }

    if (validItems.length === 0) {
        wrapper.hidden = true;
        status.textContent =
            'Sem amostras suficientes para estimar os próximos horários.';
        return;
    }

    if (typeof window.Chart !== 'function') {
        throw new Error('Chart.js não está disponível na página.');
    }

    wrapper.hidden = false;

    const labels = items.map((item) =>
        formatDate(item.forecastAt, {
            day: '2-digit',
            month: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
        })
    );

    const worst = validItems.reduce((current, item) =>
        item.delay > current.delay ? item : current
    );

    const missing = items.length - validItems.length;

    const summary = worst.delay > 0
        ? `Maior atraso estimado: +${Math.round(worst.delay)} s em ${
            formatDate(worst.forecastAt, {
                day: '2-digit',
                month: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
            })
        }.`
        : 'Os horários com dados não indicam tempo acima da referência histórica.';

    status.textContent = [
        `${validItems.length} de ${items.length} horários com dados.`,
        summary,
        missing > 0
            ? `${missing} horário(s) sem amostras suficientes.`
            : '',
    ].filter(Boolean).join(' ');

    canvas.setAttribute(
        'aria-label',
        items.map((item, index) =>
            item.hasData
                ? `${labels[index]}: estimativa ${formatSeconds(item.time)}, histórico ${formatSeconds(item.historic)}, ${item.samples} amostras`
                : `${labels[index]}: sem dados`
        ).join('; ')
    );

    forecastChart = new window.Chart(canvas.getContext('2d'), {
        type: 'line',

        data: {
            labels,

            datasets: [
                {
                    label: 'Tempo estimado',
                    data: items.map((item) => item.time),
                    borderColor: '#60a5fa',
                    backgroundColor: 'rgba(96, 165, 250, 0.10)',
                    pointBackgroundColor: '#60a5fa',
                    borderWidth: 2.5,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    tension: 0,
                    fill: false,
                    spanGaps: false,
                },
                {
                    label: 'Referência histórica',
                    data: items.map((item) => item.historic),
                    borderColor: '#94a3b8',
                    pointBackgroundColor: '#94a3b8',
                    borderDash: [6, 4],
                    borderWidth: 2,
                    pointRadius: 3,
                    pointHoverRadius: 5,
                    tension: 0,
                    fill: false,
                    spanGaps: false,
                },
            ],
        },

        options: {
            responsive: true,
            maintainAspectRatio: false,
            devicePixelRatio: Math.max(
                2,
                window.devicePixelRatio || 1
            ),

            interaction: {
                mode: 'index',
                intersect: false,
            },

            plugins: {
                legend: {
                    position: 'top',

                    labels: {
                        color: '#94a3b8',
                        usePointStyle: true,
                        padding: 18,

                        font: {
                            size: 12,
                        },
                    },
                },

                tooltip: {
                    callbacks: {
                        title(contexts) {
                            const item = items[contexts[0].dataIndex];

                            return formatDate(item.forecastAt, {
                                weekday: 'short',
                                day: '2-digit',
                                month: '2-digit',
                                hour: '2-digit',
                                minute: '2-digit',
                            });
                        },

                        label(context) {
                            return `${context.dataset.label}: ${
                                formatSeconds(context.parsed.y)
                            }`;
                        },

                        afterBody(contexts) {
                            const item = items[contexts[0].dataIndex];

                            if (!item.hasData) {
                                return ['Sem amostras suficientes.'];
                            }

                            const delayText = item.delay >= 0
                                ? `+${Math.round(item.delay)} s`
                                : `${Math.round(item.delay)} s`;

                            return [
                                `Diferença: ${delayText}`,
                                `Amostras: ${item.samples}`,
                            ];
                        },
                    },
                },
            },

            scales: {
                x: {
                    grid: {
                        display: false,
                    },

                    ticks: {
                        color: '#94a3b8',
                        autoSkip: true,
                        maxTicksLimit: 6,
                        maxRotation: 0,
                        minRotation: 0,

                        font: {
                            size: 12,
                        },
                    },
                },

                y: {
                    beginAtZero: true,

                    title: {
                        display: true,
                        text: 'Tempo de percurso (segundos)',
                        color: '#94a3b8',
                    },

                    grid: {
                        color: 'rgba(148, 163, 184, 0.15)',
                    },

                    ticks: {
                        color: '#94a3b8',
                        precision: 0,

                        callback(value) {
                            return `${value} s`;
                        },
                    },
                },
            },
        },
    });
}

export async function init(root = document) {
    const page = root.matches?.('[data-route-show]')
        ? root
        : root.querySelector('[data-route-show]');

    if (!page) return;

    const id = page.dataset.routeId;

    if (!id || page.dataset.forecastInitialized === '1') {
        return;
    }

    page.dataset.forecastInitialized = '1';

    let panel;

    try {
        panel = createPanel(page);

        const response = await fetch(
            `/routes/${encodeURIComponent(id)}/forecast.json`,
            {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                },
            }
        );

        if (!response.ok) {
            throw new Error(`Endpoint retornou HTTP ${response.status}.`);
        }

        const data = await response.json();

        if (data.ok !== true || !Array.isArray(data.items)) {
            throw new Error('Resposta de previsão inválida.');
        }

        const items = data.items.map(normalizeItem);

        drawChart(panel, items);
    } catch (error) {
        console.error('[WazeBR route-forecast]', error);

        const status = panel?.querySelector('[data-forecast-status]');

        if (status) {
            status.textContent =
                'Não foi possível carregar a estimativa. Consulte o console.';
        }

        delete page.dataset.forecastInitialized;
    }
}

export default init;