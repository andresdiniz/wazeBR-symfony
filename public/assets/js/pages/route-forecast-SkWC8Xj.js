/**
 * pages/route-forecast.js — Previsão horária dentro da página da rota.
 *
 * Cria a seção "Previsão de congestionamento" após os KPIs e busca
 * /routes/{id}/forecast.json.
 */

function qs(sel, root = document) {
    return root.querySelector(sel);
}

function esc(v) {
    return String(v ?? '')
        .replaceAll('&', '&')
        .replaceAll('<', '<')
        .replaceAll('>', '>')
        .replaceAll('"', '"')
        .replaceAll("'", '&#039;');
}

const LABELS = {
    low: 'Tende a fluir',
    medium: 'Pode piorar',
    high: 'Risco alto',
    critical: 'Congestionamento provável',
};

function styles() {
    if (qs('#route-forecast-styles')) return;
    const s = document.createElement('style');
    s.id = 'route-forecast-styles';
    s.textContent = `
        .rs-forecast { display: grid; gap: .6rem; }
        .rs-forecast__item { display: flex; align-items: center; justify-content: space-between; gap: .8rem; padding: .55rem .7rem; border-radius: .6rem; background: rgba(148,163,184,.08); }
        .rs-forecast__hour { font-weight: 800; font-size: .95rem; color: #e2e8f0; min-width: 3.2rem; }
        .rs-forecast__meta { font-size: .75rem; color: #94a3b8; }
        .rs-forecast__risk { font-size: .72rem; font-weight: 800; padding: .25rem .55rem; border-radius: .45rem; white-space: nowrap; }
        .rs-forecast__risk--low { background: rgba(34,197,94,.16); color: #4ade80; }
        .rs-forecast__risk--medium { background: rgba(234,179,8,.18); color: #facc15; }
        .rs-forecast__risk--high { background: rgba(249,115,22,.2); color: #fb923c; }
        .rs-forecast__risk--critical { background: rgba(239,68,68,.22); color: #f87171; }
        .rs-forecast__empty { color: #94a3b8; font-size: .85rem; }
    `;
    document.head.appendChild(s);
}

function section() {
    let el = qs('#route-forecast');
    if (el) return el;
    const kpis = qs('.rs-kpis');
    if (!kpis || !kpis.parentElement) return null;
    el = document.createElement('section');
    el.className = 'rs-panel';
    el.id = 'route-forecast';
    el.innerHTML = `
        <header class="rs-panel__header">
            <div>
                <span class="rs-panel__kicker">Próximas 6 horas · histórico por dia e hora</span>
                <h2>Previsão de congestionamento</h2>
            </div>
        </header>
        <div class="rs-forecast" data-forecast-list>
            <div class="rs-forecast__item rs-forecast__empty">Carregando previsão…</div>
        </div>
    `;
    kpis.parentElement.insertBefore(el, kpis.nextSibling);
    return el;
}

function render(items) {
    const box = qs('[data-forecast-list]');
    if (!box) return;
    if (!items || items.length === 0) {
        box.innerHTML = '<div class="rs-forecast__item rs-forecast__empty">Sem amostras suficientes para prever as próximas horas.</div>';
        return;
    }
    box.innerHTML = items.map((i) => {
        const lvl = esc(i.riskLevel || 'low');
        const label = esc(LABELS[i.riskLevel] || '—');
        const now = i.avgTime !== null ? Math.round(i.avgTime / 60) + ' min' : '—';
        const hist = i.avgHistoricTime !== null ? Math.round(i.avgHistoricTime / 60) + ' min' : '—';
        const score = esc(String(Number(i.riskScore || 0)));
        return `
            <div class="rs-forecast__item">
                <div>
                    <div class="rs-forecast__hour">${esc(String(i.hour).padStart(2, '0'))}h</div>
                    <div class="rs-forecast__meta">${now} vs ${hist} · índice ${score} · ${esc(String(i.samples))} amostras</div>
                </div>
                <span class="rs-forecast__risk rs-forecast__risk--${lvl}">${label}</span>
            </div>
        `;
    }).join('');
}

export function init(document) {
    const page = qs('[data-route-show]', document);
    if (!page) return;
    const id = page.dataset.routeId;
    if (!id) return;
    styles();
    section();
    fetch(`/routes/${id}/forecast.json`, { credentials: 'same-origin' })
        .then((r) => r.json())
        .then((d) => render(d?.items || []))
        .catch(() => render([]));
}

export default init;
