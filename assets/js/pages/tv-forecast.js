/**
 * pages/tv-forecast.js — Card autônomo de previsão de congestionamento.
 *
 * Usa /tv/api/data, cruza data.routes.items com
 * data.congestionForecast.routes[routeId] e exibe as rotas com maior risco.
 */

const REFRESH_INTERVAL_MS = 120000;
const MAX_ITEMS = 5;

const RISK_LABELS = {
    low: 'Tende a fluir',
    medium: 'Pode piorar',
    high: 'Risco alto',
    critical: 'Congestionamento provável',
};

const state = {
    endpoint: null,
    timer: null,
    observer: null,
    items: [],
};

function $(selector, root = document) {
    return root.querySelector(selector);
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&')
        .replaceAll('<', '<')
        .replaceAll('>', '>')
        .replaceAll('"', '"')
        .replaceAll("'", '&#039;');
}

function ensureStyles() {
    if ($('#wazebr-tv-forecast-styles')) {
        return;
    }

    const style = document.createElement('style');
    style.id = 'wazebr-tv-forecast-styles';
    style.textContent = `
        .tv-card--forecast { display: flex; flex-direction: column; }
        .tv-forecast__list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: .45rem; }
        .tv-forecast__item { display: flex; align-items: center; justify-content: space-between; gap: .6rem; padding: .45rem .55rem; border-radius: .5rem; background: rgba(148,163,184,.08); }
        .tv-forecast__name { font-size: .82rem; font-weight: 700; color: #e2e8f0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .tv-forecast__meta { font-size: .68rem; color: #94a3b8; }
        .tv-forecast__risk { font-size: .7rem; font-weight: 800; padding: .22rem .45rem; border-radius: .4rem; white-space: nowrap; }
        .tv-forecast__risk--low { background: rgba(34,197,94,.16); color: #4ade80; }
        .tv-forecast__risk--medium { background: rgba(234,179,8,.18); color: #facc15; }
        .tv-forecast__risk--high { background: rgba(249,115,22,.2); color: #fb923c; }
        .tv-forecast__risk--critical { background: rgba(239,68,68,.22); color: #f87171; }
        .tv-forecast__empty { font-size: .78rem; color: #94a3b8; }
    `;

    document.head.appendChild(style);
}

function ensureCard() {
    let card = $('[data-tv-forecast-card]');

    if (card) {
        return card;
    }

    const routesCard = $('[data-tv-routes-card]');

    if (!routesCard || !routesCard.parentElement) {
        return null;
    }

    card = document.createElement('section');
    card.className = 'tv-card tv-card--forecast';
    card.setAttribute('data-tv-forecast-card', '');
    card.hidden = true;
    card.innerHTML = `
        <header class="tv-card__head">
            <span class="tv-card__icon">🔮</span>
            <span class="tv-card__title">Previsão de tráfego</span>
        </header>
        <ul class="tv-forecast__list" data-tv-forecast-list>
            <li class="tv-forecast__empty">Sem previsões disponíveis</li>
        </ul>
    `;

    routesCard.parentElement.insertBefore(card, routesCard.nextSibling);

    return card;
}

function buildItems(routes, forecasts) {
    const routeItems = Array.isArray(routes?.items) ? routes.items : [];
    const forecastByRoute = forecasts?.routes || {};
    const items = [];

    routeItems.forEach((route) => {
        const forecast = forecastByRoute[String(route.id)] || forecastByRoute[route.id];

        if (!forecast) {
            return;
        }

        items.push({
            routeId: route.id,
            routeName: route.name || `Rota #${route.id}`,
            ...forecast,
        });
    });

    return items.sort((a, b) => Number(b.riskScore || 0) - Number(a.riskScore || 0));
}

function renderForecasts(items) {
    const card = ensureCard();
    const list = $('[data-tv-forecast-list]', card);

    if (!card || !list) {
        return;
    }

    const relevant = (items || []).slice(0, MAX_ITEMS);

    if (relevant.length === 0) {
        list.innerHTML = '<li class="tv-forecast__empty">Sem risco relevante nas próximas horas</li>';
        card.hidden = false;
        return;
    }

    list.innerHTML = relevant.map((item) => {
        const level = escapeHtml(item.riskLevel || 'low');
        const label = escapeHtml(RISK_LABELS[item.riskLevel] || '—');
        const name = escapeHtml(item.routeName || `Rota #${item.routeId}`);
        const minutes = Number(item.slotMinutes) || 30;
        const score = escapeHtml(String(Number(item.riskScore || 0)));

        return `
            <li class="tv-forecast__item">
                <div>
                    <div class="tv-forecast__name">${name}</div>
                    <div class="tv-forecast__meta">+${minutes} min · índice ${score}</div>
                </div>
                <span class="tv-forecast__risk tv-forecast__risk--${level}">${label}</span>
            </li>
        `;
    }).join('');

    card.hidden = false;
}

async function loadForecast() {
    if (!state.endpoint) {
        return;
    }

    try {
        const response = await fetch(state.endpoint, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const payload = await response.json();
        const data = payload?.data || {};

        state.items = buildItems(data.routes, data.congestionForecast);
        renderForecasts(state.items);
    } catch (error) {
        console.warn('[TV Forecast] não foi possível atualizar a previsão', error);
    }
}

export function init(document) {
    const wallboard = $('[data-tv-wallboard]', document);

    if (!wallboard) {
        return;
    }

    state.endpoint = wallboard.dataset.apiEndpoint || '/tv/api/data';

    ensureStyles();
    ensureCard();

    const routesList = $('[data-tv-routes]', document);

    if (routesList) {
        state.observer = new MutationObserver(() => {
            renderForecasts(state.items);
        });

        state.observer.observe(routesList, { childList: true });
    }

    loadForecast();
    state.timer = setInterval(loadForecast, REFRESH_INTERVAL_MS);
}

export default init;
