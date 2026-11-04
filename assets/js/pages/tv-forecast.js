const REFRESH_INTERVAL_MS = 120000;
const RISK_LABELS = { low: 'Tende a fluir', medium: 'Pode piorar', high: 'Risco alto', critical: 'Congestionamento provável' };
const state = { endpoint: null, timer: null, observer: null, items: [], busy: false };
const $ = (selector, root = document) => root?.querySelector(selector);
function escapeHtml(value) {
    return String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
}
function ensureStyles() {
    if ($('#wazebr-tv-forecast-styles')) return;
    const style = document.createElement('style');
    style.id = 'wazebr-tv-forecast-styles';
    style.textContent = `.tv-card--forecast{display:flex;flex-direction:column}.tv-forecast__list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:.45rem}.tv-forecast__item{display:flex;align-items:center;justify-content:space-between;gap:.6rem;padding:.45rem .55rem;border-radius:.5rem;background:rgba(148,163,184,.08)}.tv-forecast__name{font-size:.82rem;font-weight:700;color:#e2e8f0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.tv-forecast__meta{font-size:.68rem;color:#94a3b8}.tv-forecast__risk{font-size:.7rem;font-weight:800;padding:.22rem .45rem;border-radius:.4rem;white-space:nowrap}.tv-forecast__risk--low{background:rgba(34,197,94,.16);color:#4ade80}.tv-forecast__risk--medium{background:rgba(234,179,8,.18);color:#facc15}.tv-forecast__risk--high{background:rgba(249,115,22,.2);color:#fb923c}.tv-forecast__risk--critical{background:rgba(239,68,68,.22);color:#f87171}.tv-forecast__empty{font-size:.78rem;color:#94a3b8}`;
    document.head.appendChild(style);
}
function ensureCard() {
    let card = $('[data-tv-forecast-card]');
    if (card) return card;
    const routes = $('[data-tv-routes-card]');
    if (!routes?.parentElement) return null;
    card = document.createElement('section');
    card.className = 'tv-card tv-card--forecast';
    card.setAttribute('data-tv-forecast-card', '');
    card.innerHTML = '<header class="tv-card__head"><span class="tv-card__icon">🔮</span><span class="tv-card__title">Previsão de tráfego</span></header><ul class="tv-forecast__list" data-tv-forecast-list></ul>';
    routes.after(card);
    return card;
}
function buildItems(routes, forecasts) {
    return (Array.isArray(routes?.items) ? routes.items : []).flatMap(route => {
        const forecast = forecasts?.routes?.[route.id];
        return forecast ? [{ ...forecast, routeId: route.id, routeName: route.name || `Rota #${route.id}` }] : [];
    }).sort((a,b) => Number(b.riskScore || 0) - Number(a.riskScore || 0));
}
function renderForecasts(items) {
    const card = ensureCard();
    const list = $('[data-tv-forecast-list]', card);
    if (!list) return;
    list.innerHTML = items.length ? items.slice(0,5).map(item => {
        const level = Object.hasOwn(RISK_LABELS, item.riskLevel) ? item.riskLevel : 'low';
        return `<li class="tv-forecast__item"><div><div class="tv-forecast__name">${escapeHtml(item.routeName)}</div><div class="tv-forecast__meta">+${Number(item.slotMinutes) || 30} min · índice ${Number(item.riskScore) || 0}</div></div><span class="tv-forecast__risk tv-forecast__risk--${level}">${RISK_LABELS[level]}</span></li>`;
    }).join('') : '<li class="tv-forecast__empty">Sem previsões disponíveis nos dados recebidos</li>';
    card.hidden = false;
}
function showPriority(level, label, title, meta = '', action = '') {
    const card = $('[data-tv-priority-card]');
    if (!card) return;
    card.hidden = false;
    card.dataset.priorityState = level;
    const set = (selector, text) => { const el = $(selector, card); if (el) { el.textContent = text; el.hidden = !text; } };
    set('[data-tv-priority-badge]', label);
    const empty = $('[data-tv-priority-empty]', card);
    const incident = $('[data-tv-priority-incident]', card);
    if (empty) empty.hidden = true;
    if (incident) incident.hidden = false;
    set('[data-tv-priority-title]', title);
    set('[data-tv-priority-meta]', meta);
    set('[data-tv-priority-action]', action);
    const historical = $('[data-tv-last-critical]', card);
    if (historical) historical.hidden = true;
}
function renderPriority(data) {
    const feed = Array.isArray(data.feed) ? data.feed : [];
    const recent = Array.isArray(data.alerts?.recent) ? data.alerts.recent : [];
    const weights = { ROAD_CLOSED: 100, HAZARD_WEATHER_FLOOD: 95, ACCIDENT: 90 };
    const now = Date.now();
    const candidates = [...recent, ...feed.filter(e => e.kind === 'alert')].filter(a => {
        const stamp = Date.parse(a.when);
        return weights[a.subtype || a.type] && Number.isFinite(stamp) && now - stamp >= 0 && now - stamp <= 7200000;
    }).sort((a,b) => (weights[b.subtype || b.type] - weights[a.subtype || a.type]) || Date.parse(b.when) - Date.parse(a.when));
    const alert = candidates[0];
    if (alert) {
        const type = alert.subtype || alert.type;
        const labels = { ROAD_CLOSED: 'Via fechada reportada', HAZARD_WEATHER_FLOOD: 'Alagamento reportado', ACCIDENT: 'Acidente reportado' };
        const minutes = Math.floor((now - Date.parse(alert.when)) / 60000);
        showPriority('critical', 'Verificar', labels[type], [alert.street, alert.city, `Reportado há ${minutes} min`].filter(Boolean).join(' · '), 'Validar a ocorrência com a operação antes de acionar recursos.');
        return;
    }
    const routes = (Array.isArray(data.routes?.items) ? data.routes.items : []).filter(r => Number(r.slownessPct) >= 30).sort((a,b) => Number(b.slownessPct) - Number(a.slownessPct));
    if (routes.length) {
        const route = routes[0];
        showPriority('attention', 'Atenção', route.name || 'Rota com lentidão', `${Math.round(Number(route.slownessPct))}% acima do tempo histórico`, 'Conferir o trecho e acompanhar a evolução da lentidão.');
        return;
    }
    if (Number(data.jams?.level4plus) > 0) {
        showPriority('attention', 'Atenção', 'Congestionamento elevado', `${Number(data.jams.level4plus)} trechos em nível 4 ou superior`, 'Consultar o mapa para localizar os trechos afetados.');
        return;
    }
    if (Number(data.hydro?.atRisk) > 0) {
        showPriority('attention', 'Atenção', 'Estações hidrológicas em atenção', `${Number(data.hydro.atRisk)} estações sinalizadas pela fonte`, 'Verificar leituras e horários no painel de rios.');
        return;
    }
    const sources = ['alerts', 'jams', 'hydro', 'tvt'];
    const fresh = sources.every(key => { const time = Date.parse(data.fetch?.[key]); return Number.isFinite(time) && now - time >= 0 && now - time <= 600000; });
    showPriority(fresh ? 'normal' : 'unavailable', fresh ? 'Sem prioridade' : 'Dados limitados', 'Nenhuma prioridade identificada nos dados recebidos', fresh ? 'Verificados alertas recentes, congestionamentos, rotas e rios.' : 'Há fontes ausentes ou sem atualização nos últimos 10 minutos.', 'Ausência de prioridade não confirma ausência de incidentes.');
}
async function loadForecast() {
    if (!state.endpoint || state.busy) return;
    state.busy = true;
    try {
        const response = await fetch(state.endpoint, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store', signal: AbortSignal.timeout(20000) });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const payload = await response.json();
        if (payload.ok !== true || !payload.data || typeof payload.data !== 'object') throw new Error('Resposta inválida');
        state.items = buildItems(payload.data.routes, payload.data.congestionForecast);
        renderForecasts(state.items);
        renderPriority(payload.data);
    } catch (error) {
        showPriority('unavailable', 'Indisponível', 'Prioridades indisponíveis', 'Não foi possível atualizar os dados.', 'Verifique a conexão e a atualização das fontes.');
        console.warn('[TV Forecast/Priority]', error);
    } finally { state.busy = false; }
}
export function init(root = document) {
    const wallboard = $('[data-tv-wallboard]', root);
    if (!wallboard || state.timer) return;
    state.endpoint = wallboard.dataset.apiEndpoint || '/tv/api/data';
    ensureStyles();
    ensureCard();
    showPriority('unavailable', 'Carregando', 'Verificando prioridades…');
    const historical = $('[data-tv-last-critical]');
    if (historical) {
        historical.removeAttribute('data-tv-last-critical');
        historical.hidden = true;
    }
    loadForecast();
    state.timer = setInterval(loadForecast, REFRESH_INTERVAL_MS);
}
export default init;
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => init(), { once: true });
else init();
