import { rsT, rsLabel, RS_LOCALE } from './route-show-i18n.js';

/**
 * Previsão da rota (aba "Previsão" da página de detalhes).
 *
 * Em vez de parágrafos de texto, mostra números objetivos em cartões:
 *   Agora · Em 1 hora · Pico previsto · Lentidão  →  confiabilidade da previsão  →  gráfico  →
 *   (recolhido) maiores diferenças recentes  →  irregularidades ativas, se houver.
 */

const STEP = 15 * 60 * 1000;
const HOUR = 60 * 60 * 1000;
let chart = null;

const num = (value) => (value === null || value === undefined || value === '' || !Number.isFinite(Number(value)) ? null : Number(value));

/** 50 → "50 s"; 125 → "2 min 05 s". */
const fmtDuration = (value) => {
    const n = num(value);
    if (n === null) return '—';
    const s = Math.round(n);
    return s < 120 ? `${s} s` : `${Math.floor(s / 60)} min ${String(s % 60).padStart(2, '0')} s`;
};
const fmtSigned = (value) => {
    const n = num(value);
    return n === null ? '—' : `${n >= 0 ? '+' : '−'}${Math.round(Math.abs(n))} s`;
};
const fmtPercent = (ratio) => (ratio === null ? '—' : `${ratio >= 0 ? '+' : '−'}${Math.round(Math.abs(ratio * 100))}%`);

/** Verde / âmbar / vermelho conforme a distância para o tempo histórico. */
const toneFor = (ratio) => (ratio === null ? 'muted' : ratio >= 0.5 ? 'bad' : ratio >= 0.2 ? 'warn' : 'good');

function el(tag, className, text, attrs = {}) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = text;
    Object.entries(attrs).forEach(([key, value]) => node.setAttribute(key, value));
    return node;
}

function card({ label, value, sub, tone = 'muted', hint }) {
    const node = el('article', `rs-fc__card rs-fc__card--${tone}`);
    if (hint) node.title = hint;
    node.append(el('span', 'rs-fc__label', label), el('strong', 'rs-fc__value', value));
    if (sub) node.append(el('span', 'rs-fc__sub', sub));
    return node;
}

function skeleton() {
    const wrap = el('div', 'rs-fc__cards', undefined, { 'aria-hidden': 'true' });
    for (let i = 0; i < 4; i += 1) wrap.append(el('div', 'rs-fc__card rs-fc__card--skeleton'));
    return wrap;
}

export async function init(root = document) {
    const page = root.matches?.('[data-route-show]') ? root : root.querySelector('[data-route-show]');
    if (!page || page.dataset.forecastInitialized === '1' || !page.dataset.routeId) return;
    page.dataset.forecastInitialized = '1';

    let panel = page.querySelector('#route-forecast');
    if (!panel) {
        // Fallback para templates antigos sem o contêiner: insere depois dos KPIs.
        panel = el('section', 'rs-panel rs-fc');
        panel.id = 'route-forecast';
        const anchor = page.querySelector('.rs-kpis');
        if (!anchor) { delete page.dataset.forecastInitialized; return; }
        anchor.after(panel);
    }
    panel.setAttribute('aria-busy', 'true');

    const head = el('header', 'rs-fc__head');
    const title = el('h2', 'rs-fc__title', rsT('Previsão: próximas 6 horas'));
    title.id = 'rs-fc-title';
    const coverage = el('span', 'rs-fc__coverage', rsT('Carregando previsão…'), { role: 'status' });
    head.append(title, coverage);
    panel.replaceChildren(head, skeleton());

    const abort = new AbortController();
    const timer = setTimeout(() => abort.abort(), 20000);

    try {
        const response = await fetch(`/routes/${encodeURIComponent(page.dataset.routeId)}/forecast.json`, {
            credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: abort.signal,
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const data = await response.json();
        if (!data.ok || !Array.isArray(data.items)) throw new Error(rsT('Resposta inválida'));

        const tz = data.timezone || 'America/Sao_Paulo';
        const now = Date.parse(data.generatedAt) || Date.now();
        const dateTime = (value) => {
            const t = Date.parse(value);
            return Number.isFinite(t) ? new Date(t).toLocaleString(RS_LOCALE, { timeZone: tz, day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '—';
        };
        const clock = (value) => {
            const t = Date.parse(value);
            return Number.isFinite(t) ? new Date(t).toLocaleTimeString(RS_LOCALE, { timeZone: tz, hour: '2-digit', minute: '2-digit' }) : '—';
        };

        coverage.textContent = rsT('%available% de %total% horários com dados', {
            '%available%': data.availableSlots, '%total%': data.slotsAhead,
        });
        coverage.classList.toggle('is-partial', data.availableSlots < data.slotsAhead);

        // ───────── Cartões: o que esperar ─────────
        const usable = data.items.filter((item) => item.hasData && !item.stale && num(item.avgTime) !== null);
        const actualRows = (data.actual || []).filter((row) => Number(row.samples) > 0 && num(row.avgTime) !== null);
        const last = actualRows.reduce((best, row) => (!best || Date.parse(row.at) > Date.parse(best.at) ? row : best), null);
        const hasCurrent = last && (now - Date.parse(last.at)) <= 45 * 60000;

        const ratioOf = (time, historic) => (num(time) !== null && num(historic) > 0 ? Number(time) / Number(historic) - 1 : null);

        const cards = el('div', 'rs-fc__cards');

        const nowRatio = hasCurrent ? ratioOf(last.avgTime, last.avgHistoricTime) : null;
        cards.append(card({
            label: rsT('Agora'),
            value: hasCurrent ? fmtDuration(last.avgTime) : '—',
            sub: hasCurrent ? (nowRatio === null ? rsT('sem referência histórica') : `${fmtPercent(nowRatio)} ${rsT('vs histórico')}`) : rsT('Sem leitura recente'),
            tone: hasCurrent ? toneFor(nowRatio) : 'muted',
            hint: rsT('Último tempo de viagem medido nesta rota.'),
        }));

        const inOneHour = usable
            .map((item) => ({ item, gap: Math.abs(Date.parse(item.forecastAt) - (now + HOUR)) }))
            .filter(({ gap }) => gap <= 30 * 60000)
            .sort((a, b) => a.gap - b.gap)[0]?.item;
        if (inOneHour) {
            const delta = hasCurrent ? Number(inOneHour.avgTime) - Number(last.avgTime) : null;
            const trend = delta === null ? null : Math.abs(delta) < 3 ? rsT('sem mudança relevante') : `${delta > 0 ? '▲' : '▼'} ${fmtSigned(delta)} ${rsT('vs agora')}`;
            cards.append(card({
                label: rsT('Em 1 hora'),
                value: fmtDuration(inOneHour.avgTime),
                sub: trend ?? `${fmtPercent(ratioOf(inOneHour.avgTime, inOneHour.avgHistoricTime))} ${rsT('vs histórico')}`,
                tone: toneFor(ratioOf(inOneHour.avgTime, inOneHour.avgHistoricTime)),
                hint: `${rsT('Previsão para')} ${clock(inOneHour.forecastAt)}.`,
            }));
        } else {
            cards.append(card({ label: rsT('Em 1 hora'), value: '—', sub: rsT('Sem previsão disponível'), tone: 'muted' }));
        }

        const peak = usable.reduce((best, item) => (!best || Number(item.avgTime) > Number(best.avgTime) ? item : best), null);
        cards.append(peak
            ? card({
                label: rsT('Pico previsto'),
                value: fmtDuration(peak.avgTime),
                sub: `${rsT('às')} ${clock(peak.forecastAt)} · ${fmtPercent(ratioOf(peak.avgTime, peak.avgHistoricTime))} ${rsT('vs histórico')}`,
                tone: toneFor(ratioOf(peak.avgTime, peak.avgHistoricTime)),
                hint: rsT('Maior tempo previsto nas próximas 6 horas.'),
            })
            : card({ label: rsT('Pico previsto'), value: '—', sub: rsT('Sem previsão disponível'), tone: 'muted' }));

        const duration = data.congestionDuration;
        if (duration) {
            if (duration.stale) {
                cards.append(card({ label: rsT('Lentidão'), value: rsT('Leitura antiga'), sub: rsT('não dá para confirmar agora'), tone: 'muted' }));
            } else if (duration.active) {
                const remaining = num(duration.expectedRemainingMinutes);
                cards.append(card({
                    label: rsT('Lentidão'),
                    value: `${rsT('há')} ${duration.elapsedMinutes ?? '—'} min`,
                    sub: remaining === null ? rsT('em andamento') : `${rsT('restante típico')}: ~${remaining} min`,
                    tone: 'warn',
                    hint: rsT('Estimativa estatística; não é garantia de término.'),
                }));
            } else {
                cards.append(card({ label: rsT('Lentidão'), value: rsT('Nenhuma'), sub: rsT('na última leitura'), tone: 'good' }));
            }
        }

        // ───────── Confiabilidade da previsão ─────────
        const accuracy = data.accuracy;
        const reliability = el('section', 'rs-fc__reliability', undefined, { 'aria-label': rsT('Confiabilidade da previsão') });
        reliability.append(el('h3', 'rs-fc__subtitle', rsT('Confiabilidade da previsão')));
        if (accuracy) {
            const stats = el('div', 'rs-fc__stats');
            const stat = (label, value, hint) => {
                const node = el('div', 'rs-fc__stat');
                if (hint) node.title = hint;
                node.append(el('span', 'rs-fc__stat-label', label), el('strong', 'rs-fc__stat-value', value));
                return node;
            };
            stats.append(
                stat(rsT('Erro médio'), fmtDuration(accuracy.mae), rsT('Diferença média, em valor absoluto, entre o previsto e o medido.')),
                stat(rsT('Viés'), fmtSigned(accuracy.bias), rsT('Previsto − medido. Positivo = a previsão costuma ser alta.')),
                stat(rsT('Janelas avaliadas'), String(accuracy.samples ?? 0), rsT('Quantidade de previsões comparadas com o tempo medido depois.')),
            );

            const hit = num(accuracy.hitRate);
            const hitNode = el('div', 'rs-fc__stat rs-fc__stat--bar');
            hitNode.title = rsT('Parcela das previsões que ficaram dentro da margem de tolerância.');
            hitNode.append(el('span', 'rs-fc__stat-label', rsT('Dentro da tolerância')), el('strong', 'rs-fc__stat-value', hit === null ? '—' : `${Math.round(hit)}%`));
            if (hit !== null) {
                const bar = el('div', 'rs-fc__bar', undefined, { role: 'presentation' });
                const fill = el('i');
                fill.style.width = `${Math.max(0, Math.min(100, hit))}%`;
                fill.className = hit >= 80 ? 'is-good' : hit >= 60 ? 'is-warn' : 'is-bad';
                bar.append(fill);
                hitNode.append(bar);
            }
            stats.prepend(hitNode);
            reliability.append(stats);

            const bands = (accuracy.bands || []).filter((band) => band && band.label);
            if (bands.length) {
                const chips = el('div', 'rs-fc__chips');
                bands.forEach((band) => chips.append(el('span', 'rs-fc__chip', `${rsT('Horizonte')} ${band.label}: ${rsT('erro')} ${fmtDuration(band.mae)} (${band.samples})`)));
                reliability.append(chips);
            }
        } else {
            reliability.append(el('p', 'rs-fc__empty', rsT('Ainda não há histórico suficiente para medir a precisão.')));
        }

        // ───────── Gráfico ─────────
        const rows = new Map();
        const slot = (iso) => {
            const ms = Date.parse(iso);
            if (!Number.isFinite(ms)) return null;
            const key = Math.floor(ms / STEP) * STEP;
            if (!rows.has(key)) rows.set(key, { key, actual: null, historic: null, past: null, future: null, samples: 0, issued: null, horizon: null });
            return rows.get(key);
        };
        for (const r of data.actual || []) {
            const p = slot(r.at);
            if (p && Number(r.samples) > 0) { p.actual = num(r.avgTime); p.historic = num(r.avgHistoricTime); p.samples = r.samples; }
        }
        for (const r of data.pastForecasts || []) {
            const p = slot(r.at);
            if (p) { p.past = num(r.predicted); p.issued = r.issuedAt; p.horizon = r.horizonMinutes; }
        }
        for (const r of data.items) {
            const p = slot(r.forecastAt);
            if (p && r.hasData && !r.stale) { p.future = num(r.avgTime); p.historic = num(r.avgHistoricTime); p.issued = r.updatedAt; }
        }
        const keys = [...rows.keys()];
        const begin = Math.min(Math.floor((now - 6 * HOUR) / STEP) * STEP, ...keys);
        const end = Math.max(Math.ceil((now + 6 * HOUR) / STEP) * STEP, ...keys);
        for (let key = begin; key <= end; key += STEP) {
            if (!rows.has(key)) rows.set(key, { key, actual: null, historic: null, past: null, future: null, samples: 0 });
        }
        const sorted = [...rows.values()].sort((x, y) => x.key - y.key);

        const chartBox = el('section', 'rs-fc__chart');
        const chartHead = el('div', 'rs-fc__chart-head');
        chartHead.append(el('h3', 'rs-fc__subtitle', rsT('Tempo de viagem: medido x previsto')));
        const wrap = el('div', 'rs-fc__canvas');
        const canvas = el('canvas', undefined, undefined, { role: 'img', 'aria-label': rsT('Tempo medido, previsões e lacunas de coleta') });
        wrap.append(canvas);
        chartBox.append(chartHead, wrap);

        // ───────── Maiores diferenças (recolhido, sem texto repetido) ─────────
        const errors = [...(data.forecastErrors || [])]
            .filter((e) => num(e.errorSeconds) !== null)
            .sort((a, b) => Math.abs(num(b.errorSeconds)) - Math.abs(num(a.errorSeconds)))
            .slice(0, 5);

        let diffs = null;
        if (errors.length) {
            diffs = el('details', 'rs-fc__diffs');
            diffs.append(el('summary', undefined, `${rsT('Maiores diferenças recentes')} (${errors.length})`));
            const table = el('table', 'rs-fc__table');
            const thead = el('thead');
            const headRow = el('tr');
            [rsT('Horário'), rsT('Previsto'), rsT('Medido'), rsT('Diferença')].forEach((text) => headRow.append(el('th', undefined, text, { scope: 'col' })));
            thead.append(headRow);
            const tbody = el('tbody');
            errors.forEach((e) => {
                const actualTime = num(e.actualTime);
                const rel = actualTime ? Math.abs(num(e.errorSeconds)) / actualTime : 0;
                const tone = rel >= 0.25 ? 'bad' : rel >= 0.12 ? 'warn' : 'muted';
                const tr = el('tr');
                const when = el('td', undefined, dateTime(e.targetStartAt));
                if (num(e.horizonMinutes) !== null) when.title = `${rsT('Antecedência')}: ${Math.round(e.horizonMinutes)} min`;
                const diff = el('td');
                diff.append(el('span', `rs-fc__badge rs-fc__badge--${tone}`, fmtSigned(e.errorSeconds)));
                tr.append(when, el('td', undefined, fmtDuration(e.predictedTime)), el('td', undefined, fmtDuration(e.actualTime)), diff);
                tbody.append(tr);

                // Só mostra o contexto quando ele diz algo; "nenhum sinal relevante" era o texto padrão repetido em todas as linhas.
                const context = (e.explanation || '').trim();
                if (context && !/nenhum sinal relevante|no relevant signal/i.test(context)) {
                    const ctxRow = el('tr', 'rs-fc__ctx');
                    const ctxCell = el('td', undefined, context, { colspan: '4' });
                    ctxRow.append(ctxCell);
                    tbody.append(ctxRow);
                }
            });
            table.append(thead, tbody);
            diffs.append(table);
        }

        // ───────── Irregularidades ativas (só se houver) ─────────
        let signals = null;
        const active = data.nearbySignals || [];
        if (active.length) {
            signals = el('section', 'rs-fc__signals');
            signals.append(el('h3', 'rs-fc__subtitle', rsT('Irregularidades ativas na rota')));
            const chips = el('div', 'rs-fc__chips');
            active.forEach((s) => {
                const label = [s.subtype ? rsLabel(s.subtype) : rsLabel(s.type), s.street].filter(Boolean).join(' · ');
                chips.append(el('span', 'rs-fc__chip rs-fc__chip--signal', label));
            });
            signals.append(chips);
        }

        const note = el('p', 'rs-fc__note', rsT('Previsão estatística: use como referência, não como garantia.'));

        panel.replaceChildren(head, cards, reliability, chartBox, ...[diffs, signals].filter(Boolean), note);
        panel.removeAttribute('aria-busy');

        // ───────── Chart.js ─────────
        if (typeof window.Chart !== 'function') throw new Error(rsT('Chart.js indisponível'));
        if (chart) chart.destroy();

        const defs = [
            ['historic', rsT('Histórico'), '#94a3b8', [3, 4], 1.5],
            ['past', rsT('Previsão passada'), '#f59e0b', [6, 4], 2],
            ['actual', rsT('Real'), '#2563eb', [], 2.5],
            ['future', rsT('Previsão futura'), '#7c3aed', [], 2.5],
        ];
        chart = new window.Chart(canvas, {
            type: 'line',
            data: {
                labels: sorted.map((r) => dateTime(r.key)),
                datasets: defs.map(([field, label, color, dash, width]) => ({
                    label, data: sorted.map((r) => r[field]), borderColor: color, backgroundColor: color,
                    borderDash: dash, borderWidth: width, spanGaps: false, tension: 0.2, fill: false,
                    pointRadius: 0, pointHoverRadius: 4, pointHitRadius: 10,
                })),
            },
            plugins: [{
                id: 'forecastNow',
                afterDraw(c) {
                    const scale = c.scales.x;
                    if (!scale || sorted.length < 2) return;
                    const pos = (now - begin) / STEP;
                    const x = scale.getPixelForValue(0) + (scale.getPixelForValue(1) - scale.getPixelForValue(0)) * pos;
                    if (x < c.chartArea.left || x > c.chartArea.right) return;
                    const ctx = c.ctx;
                    ctx.save();
                    // Futuro levemente sombreado + linha "agora".
                    ctx.fillStyle = 'rgba(124, 58, 237, .05)';
                    ctx.fillRect(x, c.chartArea.top, c.chartArea.right - x, c.chartArea.bottom - c.chartArea.top);
                    ctx.strokeStyle = '#94a3b8';
                    ctx.setLineDash([4, 4]);
                    ctx.beginPath(); ctx.moveTo(x, c.chartArea.top); ctx.lineTo(x, c.chartArea.bottom); ctx.stroke();
                    ctx.setLineDash([]);
                    ctx.fillStyle = '#94a3b8';
                    ctx.fillText(rsT('agora'), x + 4, c.chartArea.top + 12);
                    ctx.restore();
                },
            }],
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom', labels: { color: '#94a3b8', usePointStyle: true, boxWidth: 8, boxHeight: 8 } },
                    tooltip: {
                        callbacks: {
                            label: (c) => (c.parsed.y === null ? null : `${c.dataset.label}: ${fmtDuration(c.parsed.y)}`),
                            afterBody(contexts) {
                                const r = sorted[contexts[0].dataIndex];
                                return [
                                    `${rsT('Amostras observadas')}: ${r.samples || 0}`,
                                    r.horizon ? `${rsT('Antecedência')}: ${Math.round(r.horizon)} min` : '',
                                    r.issued ? `${rsT('Emissão')}: ${dateTime(r.issued)}` : '',
                                ].filter(Boolean);
                            },
                        },
                    },
                },
                scales: {
                    x: { ticks: { color: '#94a3b8', maxTicksLimit: 8, maxRotation: 0 }, grid: { display: false } },
                    y: { ticks: { color: '#94a3b8', callback: (v) => `${v} s` }, grid: { color: 'rgba(148,163,184,.15)' } },
                },
            },
        });
    } catch (error) {
        console.error('[route-forecast]', error);
        coverage.textContent = rsT('Não foi possível exibir a previsão. Recarregue a página.');
        coverage.classList.add('is-partial');
        panel.querySelector('.rs-fc__cards')?.remove();
        panel.removeAttribute('aria-busy');
        delete page.dataset.forecastInitialized;
    } finally {
        clearTimeout(timer);
    }
}
