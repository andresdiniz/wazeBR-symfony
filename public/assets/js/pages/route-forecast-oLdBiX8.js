/**
 * Previsão da rota ao lado do real.
 * Inicializado pelo registro routeForecast em assets/js/app.js.
 *
 * Um único gráfico, eixo de tempo contínuo (fatias de 15 min):
 *   - Real (medido)                 últimas 6 h
 *   - Previsão feita ~1 h antes     o que o modelo disse para essas mesmas fatias
 *   - Previsão                      próximas 6 h
 *   - Referência histórica          tempo "normal" do horário
 * + cartões de precisão do modelo (auditoria dos últimos 7 dias).
 *
 * Fonte: GET /routes/{id}/forecast.json  (items, actual, pastForecasts, accuracy).
 */

const COLORS = {
    actual: '#2563eb',
    past: '#f59e0b',
    future: '#7c3aed',
    historic: '#94a3b8',
};

let forecastChart = null;

function numberOrNull(value) {
    if (value === null || value === undefined || value === '') return null;
    const number = Number(value);
    return Number.isFinite(number) ? number : null;
}

function formatSeconds(value) {
    return value === null || value === undefined ? '—' : `${Math.round(value)} s`;
}

function signedSeconds(value) {
    if (value === null || value === undefined) return '—';
    const rounded = Math.round(value);
    return `${rounded >= 0 ? '+' : '−'}${Math.abs(rounded)} s`;
}

function slotKey(iso) {
    const ms = new Date(iso).getTime();
    return Number.isNaN(ms) ? null : Math.round(ms / 60000) * 60000;
}

function normalizeItem(item) {
    const samples = Math.max(0, Math.trunc(numberOrNull(item.samples) ?? 0));
    const time = numberOrNull(item.avgTime);
    const historic = numberOrNull(item.avgHistoricTime);
    const hasData = samples > 0 && time !== null && time >= 0 && historic !== null && historic > 0;

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
        if (!kpis) throw new Error('Seção de KPIs não encontrada.');

        panel = document.createElement('section');
        panel.id = 'route-forecast';
        panel.className = 'rs-panel';
        kpis.insertAdjacentElement('afterend', panel);
    }

    panel.innerHTML = `
        <header class="rs-panel__header">
            <div>
                <span class="rs-panel__kicker">Últimas 6 horas · próximas 6 horas</span>
                <h2>Previsão ao lado do real</h2>
            </div>
        </header>

        <p class="rs-panel__hint" data-forecast-status role="status" aria-live="polite">
            Carregando estimativa…
        </p>

        <div class="rs-forecast__chips" data-forecast-accuracy hidden></div>
        <section class="rs-forecast__context" data-forecast-context aria-label="Duração estimada e alertas próximos"></section>

        <div data-forecast-chart-wrap style="position: relative; width: 100%; height: 320px;" hidden>
            <canvas data-forecast-chart role="img" aria-label="Tempo real, previsão passada e previsão futura"></canvas>
        </div>

        <p class="rs-panel__hint">
            Azul: tempo medido. Laranja tracejado: o que o modelo havia previsto cerca de 1 h antes.
            Roxo: previsão para as próximas horas. Cinza: referência histórica.
            Horários sem amostras não são tratados como trânsito livre.
        </p>
    `;

    return panel;
}

function renderAccuracy(panel, accuracy) {
    const box = panel.querySelector('[data-forecast-accuracy]');
    if (!box) return;

    box.replaceChildren();

    const chip = (label, value, hint) => {
        const el = document.createElement('div');
        el.className = 'rs-forecast__chip';
        const l = document.createElement('span');
        l.textContent = label;
        const v = document.createElement('strong');
        v.textContent = value;
        el.append(l, v);
        if (hint) {
            const h = document.createElement('small');
            h.textContent = hint;
            el.append(h);
        }
        return el;
    };

    if (!accuracy) {
        box.append(chip('Precisão do modelo', '—', 'Ainda sem previsões avaliadas para esta rota (a avaliação roda diariamente).'));
        box.hidden = false;
        return;
    }

    const bias = accuracy.bias;
    const biasText = Math.abs(bias) < 1
        ? 'sem viés'
        : bias > 0 ? 'costuma superestimar o tempo' : 'costuma subestimar o tempo';

    box.append(
        chip('Erro médio', `±${Math.round(accuracy.mae)} s`, `${accuracy.samples} previsões · ${accuracy.days} dias`),
        chip('Dentro da tolerância', accuracy.hitRate !== null ? `${Math.round(accuracy.hitRate)}%` : '—', 'acerto do modelo'),
        chip('Viés', signedSeconds(bias), biasText)
    );

    (accuracy.bands ?? []).forEach((band) => {
        box.append(chip(`Horizonte ${band.label}`, `±${Math.round(band.mae)} s`, `${band.samples} previsões`));
    });

    box.hidden = false;
}

function renderContext(panel, duration, signals) {
    const box = panel.querySelector('[data-forecast-context]');
    if (!box) return;
    box.replaceChildren();
    const title = document.createElement('h3');
    title.textContent = 'Duração e sinais próximos';
    box.append(title);
    const summary = document.createElement('p');
    if (duration?.active) {
        const elapsed = duration.elapsedMinutes ?? 0;
        summary.textContent = duration.expectedRemainingMinutes !== null
            ? `Lentidão ativa há cerca de ${elapsed} min; estimativa de mais ${duration.expectedRemainingMinutes} min. Duração típica: ${duration.typicalDurationMinutes ?? 'indisponível'} min (${duration.episodes ?? 0} episódios, confiança ${duration.confidence ?? 'baixa'}).`
            : `Lentidão ativa há cerca de ${elapsed} min. Ainda não há histórico suficiente para estimar o fim.`;
    } else {
        summary.textContent = duration?.typicalDurationMinutes != null
            ? `Sem lentidão confirmada agora. A duração típica de episódios semelhantes foi cerca de ${duration.typicalDurationMinutes} min (${duration.episodes ?? 0} episódios, confiança ${duration.confidence ?? 'baixa'}).`
            : 'Sem lentidão confirmada agora e sem episódios históricos suficientes para estimar duração.';
    }
    box.append(summary);
    const note = document.createElement('small');
    note.textContent = 'Estimativa aproximada; alertas próximos são indícios de contexto, não confirmação de causa.';
    box.append(note);

    const list = document.createElement('ul');
    list.className = 'rs-forecast__signal-list';
    (signals ?? []).slice(0, 8).forEach((signal) => {
        const li = document.createElement('li');
        li.className = 'rs-forecast__signal';
        const place = [signal.street, signal.city].filter(Boolean).join(' · ');
        const distance = signal.distanceMeters !== null ? `${Math.round(signal.distanceMeters)} m` : '';
        li.textContent = [signal.type, signal.subtype, place, distance].filter(Boolean).join(' — ');
        list.append(li);
    });
    if (!list.children.length) {
        const empty = document.createElement('li');
        empty.className = 'rs-forecast__signal';
        empty.textContent = 'Nenhum alerta Waze recente próximo desta rota.';
        list.append(empty);
    }
    box.append(list);
}
/** Linha vertical tracejada separando passado e futuro. */
const nowLinePlugin = {
    id: 'rsNowLine',
    afterDatasetsDraw(chart, args, options) {
        const index = options?.index;
        const x = chart.scales.x;
        if (index === undefined || index === null || !x) return;

        const px = x.getPixelForValue(index - 0.5);
        const { ctx, chartArea } = chart;
        if (!Number.isFinite(px) || px < chartArea.left || px > chartArea.right) return;

        ctx.save();
        ctx.setLineDash([5, 4]);
        ctx.strokeStyle = 'rgba(100, 116, 139, 0.7)';
        ctx.lineWidth = 1.5;
        ctx.beginPath();
        ctx.moveTo(px, chartArea.top);
        ctx.lineTo(px, chartArea.bottom);
        ctx.stroke();
        ctx.setLineDash([]);
        ctx.fillStyle = 'rgba(100, 116, 139, 0.95)';
        ctx.font = '11px system-ui, sans-serif';
        ctx.textAlign = 'left';
        ctx.fillText('agora', px + 5, chartArea.top + 11);
        ctx.restore();
    },
};

function drawChart(panel, data, timeZone) {
    const canvas = panel.querySelector('[data-forecast-chart]');
    const wrapper = panel.querySelector('[data-forecast-chart-wrap]');
    const status = panel.querySelector('[data-forecast-status]');

    if (forecastChart) {
        forecastChart.destroy();
        forecastChart = null;
    }

    const items = (data.items ?? []).map(normalizeItem);
    const validItems = items.filter((item) => item.hasData);
    const actual = data.actual ?? [];
    const past = (data.pastForecasts ?? []).filter((p) => numberOrNull(p.predicted) !== null);

    if (validItems.length === 0 && actual.length === 0) {
        wrapper.hidden = true;
        status.textContent = 'Sem amostras suficientes para estimar ou comparar os horários.';
        return;
    }

    if (typeof window.Chart !== 'function') throw new Error('Chart.js não está disponível na página.');

    // Eixo de tempo: união de todas as fatias, em ordem.
    const rows = new Map();
    const row = (iso) => {
        const key = slotKey(iso);
        if (key === null) return null;
        if (!rows.has(key)) rows.set(key, { key, actual: null, historic: null, past: null, future: null, samples: 0, isFuture: false });
        return rows.get(key);
    };

    actual.forEach((a) => {
        const r = row(a.at);
        if (!r) return;
        r.actual = numberOrNull(a.avgTime);
        r.historic = numberOrNull(a.avgHistoricTime) ?? r.historic;
        r.samples = a.samples ?? 0;
    });

    past.forEach((p) => {
        const r = row(p.at);
        if (r) r.past = numberOrNull(p.predicted);
    });

    items.forEach((item) => {
        const r = row(item.forecastAt);
        if (!r) return;
        r.future = item.time;
        r.historic = item.historic ?? r.historic;
        r.isFuture = true;
    });

    const sorted = [...rows.values()].sort((a, b) => a.key - b.key);
    const firstFuture = sorted.findIndex((r) => r.isFuture);

    const labels = sorted.map((r) => new Date(r.key).toLocaleTimeString('pt-BR', {
        timeZone, hour: '2-digit', minute: '2-digit',
    }));

    const worst = validItems.length
        ? validItems.reduce((cur, item) => (item.delay > cur.delay ? item : cur))
        : null;

    const parts = [];
    if (validItems.length) parts.push(`${validItems.length} de ${items.length} horários futuros com dados.`);
    if (worst && worst.delay > 0) {
        parts.push(`Maior atraso estimado: +${Math.round(worst.delay)} s às ${new Date(worst.forecastAt).toLocaleTimeString('pt-BR', {
            timeZone, hour: '2-digit', minute: '2-digit',
        })}.`);
    } else if (validItems.length) {
        parts.push('Os horários futuros com dados não indicam tempo acima da referência histórica.');
    }
    const missing = items.length - validItems.length;
    if (missing > 0) parts.push(`${missing} horário(s) sem amostras suficientes.`);
    status.textContent = parts.join(' ');

    canvas.setAttribute('aria-label', `Gráfico com ${sorted.length} fatias de 15 minutos: tempo medido, previsão passada e previsão futura. ${status.textContent}`);

    wrapper.hidden = false;

    forecastChart = new window.Chart(canvas.getContext('2d'), {
        type: 'line',
        plugins: [nowLinePlugin],
        data: {
            labels,
            datasets: [
                {
                    label: 'Real (medido)',
                    data: sorted.map((r) => r.actual),
                    borderColor: COLORS.actual,
                    backgroundColor: 'rgba(37, 99, 235, 0.10)',
                    pointBackgroundColor: COLORS.actual,
                    borderWidth: 2.5, pointRadius: 3, pointHoverRadius: 5,
                    tension: 0.25, fill: true, spanGaps: true,
                },
                {
                    label: 'Previsão feita ~1 h antes',
                    data: sorted.map((r) => r.past),
                    borderColor: COLORS.past,
                    pointBackgroundColor: COLORS.past,
                    borderDash: [6, 4], borderWidth: 2, pointRadius: 2.5, pointHoverRadius: 5,
                    tension: 0.25, fill: false, spanGaps: true,
                },
                {
                    label: 'Previsão (próximas horas)',
                    data: sorted.map((r) => r.future),
                    borderColor: COLORS.future,
                    pointBackgroundColor: COLORS.future,
                    borderWidth: 2.5, pointRadius: 4, pointHoverRadius: 6,
                    tension: 0, fill: false, spanGaps: false,
                },
                {
                    label: 'Referência histórica',
                    data: sorted.map((r) => r.historic),
                    borderColor: COLORS.historic,
                    pointBackgroundColor: COLORS.historic,
                    borderDash: [2, 4], borderWidth: 1.5, pointRadius: 0, pointHoverRadius: 4,
                    tension: 0, fill: false, spanGaps: true,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            devicePixelRatio: Math.max(2, window.devicePixelRatio || 1),
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    position: 'top',
                    labels: { color: '#94a3b8', usePointStyle: true, padding: 16, font: { size: 12 } },
                },
                rsNowLine: { index: firstFuture >= 0 ? firstFuture : null },
                tooltip: {
                    callbacks: {
                        title(contexts) {
                            const r = sorted[contexts[0].dataIndex];
                            return new Date(r.key).toLocaleString('pt-BR', {
                                timeZone, weekday: 'short', day: '2-digit', month: '2-digit',
                                hour: '2-digit', minute: '2-digit',
                            });
                        },
                        label(context) {
                            return `${context.dataset.label}: ${formatSeconds(context.parsed.y)}`;
                        },
                        afterBody(contexts) {
                            const r = sorted[contexts[0].dataIndex];
                            const lines = [];
                            if (r.actual !== null && r.past !== null) {
                                lines.push(`Erro da previsão: ${signedSeconds(r.past - r.actual)}`);
                            }
                            if (r.samples) lines.push(`Amostras no horário: ${r.samples}`);
                            return lines;
                        },
                    },
                },
            },
            scales: {
                x: { ticks: { color: '#94a3b8', maxRotation: 0, autoSkip: true, maxTicksLimit: 14 }, grid: { display: false } },
                y: {
                    beginAtZero: false,
                    ticks: { color: '#94a3b8', callback: (v) => `${v} s` },
                    grid: { color: 'rgba(148, 163, 184, 0.18)' },
                    title: { display: true, text: 'tempo de percurso (s)', color: '#94a3b8' },
                },
            },
        },
    });
}

export async function init(root = document) {
    const page = root.matches?.('[data-route-show]') ? root : root.querySelector('[data-route-show]');
    if (!page) return;

    const id = page.dataset.routeId;
    if (!id || page.dataset.forecastInitialized === '1') return;

    page.dataset.forecastInitialized = '1';

    let panel;

    try {
        panel = createPanel(page);

        const response = await fetch(`/routes/${encodeURIComponent(id)}/forecast.json`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) throw new Error(`Endpoint retornou HTTP ${response.status}.`);

        const data = await response.json();
        if (data.ok !== true || !Array.isArray(data.items)) throw new Error('Resposta de previsão inválida.');

        const timeZone = data.timezone || page.dataset.timezone || 'America/Sao_Paulo';

        renderAccuracy(panel, data.accuracy ?? null);
        renderContext(panel, data.congestionDuration ?? null, data.nearbySignals ?? []);
        drawChart(panel, data, timeZone);
    } catch (error) {
        console.error('[WazeBR route-forecast]', error);

        const status = panel?.querySelector('[data-forecast-status]');
        if (status) status.textContent = 'Não foi possível carregar a estimativa. Consulte o console.';

        delete page.dataset.forecastInitialized;
    }
}
