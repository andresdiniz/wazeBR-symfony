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

/**
 * pages/route-live.js — atualização ao vivo da página /routes/{id}.
 *
 * Fluxo (mesmo desenho do wallboard de TV):
 *   1. GET /routes/live/stream → { hub, topic }
 *   2. EventSource no Mercure (evento "magro", sem dados)
 *   3. a cada aviso: GET /routes/{id}/live.json?since=… (autenticado, com escopo de parceiro)
 *   4. atualiza KPIs de tempo/atraso, horário da leitura, aviso de "desatualizado" e
 *      mostra alertas novos perto do traçado.
 *
 * Sem EventSource, sem hub ou após falhas seguidas: polling a cada 60 s.
 * Só consulta com a aba visível; ao voltar para a aba, atualiza na hora.
 */

const REFETCH_DEBOUNCE_MS = 3000;
const POLL_INTERVAL_MS = 60000;
const SSE_MAX_FAILURES = 3;
const MAX_LISTED_ALERTS = 3;

export async function initRouteLive(page, { knownAlertIds = new Set(), onNewAlerts } = {}) {
    const liveUrl = page.dataset.liveUrl;
    const streamUrl = page.dataset.liveStream;
    if (!liveUrl || !streamUrl || page.dataset.liveInitialized === '1') return null;
    page.dataset.liveInitialized = '1';

    const timeZone = page.dataset.timezone || 'America/Sao_Paulo';
    const els = {
        badge: page.querySelector('[data-live-badge]'),
        updated: page.querySelector('[data-live-updated]'),
        stale: page.querySelector('[data-live-stale]'),
        alerts: page.querySelector('[data-live-alerts]'),
        time: page.querySelector('[data-live-kpi="time"]'),
        timeHint: page.querySelector('[data-live-kpi="time-hint"]'),
        delay: page.querySelector('[data-live-kpi="delay"]'),
        delayHint: page.querySelector('[data-live-kpi="delay-hint"]'),
    };

    const state = {
        since: page.dataset.liveSince || null,
        eventSource: null,
        pollTimer: null,
        refetchTimer: null,
        failures: 0,
        inFlight: false,
        newAlertCount: 0,
        newAlertLabels: [],
    };

    // ── render ───────────────────────────────────────────────────────────

    const LEVEL_CLASSES = ['rs-kpi__value--good', 'rs-kpi__value--warn', 'rs-kpi__value--bad'];

    function setLevel(el, level) {
        if (!el) return;
        el.classList.remove(...LEVEL_CLASSES);
        if (level) el.classList.add(`rs-kpi__value--${level}`);
    }

    function setValueWithUnit(el, text, unit) {
        if (!el) return;
        el.textContent = text;
        if (unit) {
            const small = document.createElement('small');
            small.textContent = unit;
            el.append(small);
        }
    }

    function renderTime(seconds) {
        if (!els.time) return;
        const t = Math.round(seconds);

        if (t >= 60) {
            const rest = String(t % 60).padStart(2, '0');
            els.time.textContent = `${Math.floor(t / 60)}`;
            const min = document.createElement('small');
            min.textContent = 'min';
            els.time.append(min, ` ${rest}`);
            const sec = document.createElement('small');
            sec.textContent = 's';
            els.time.append(sec);
        } else {
            setValueWithUnit(els.time, String(t), 's');
        }
    }

    function applyCurrent(current) {
        if (!current) return;

        if (current.time !== null && current.time !== undefined) {
            renderTime(current.time);
            setLevel(els.time, current.level ?? 'good');
            if (els.timeHint) {
                els.timeHint.textContent = current.historicTime != null
                    ? `${rt("normal")}: ${Math.round(current.historicTime)}s`
                    : rt("sem tempo histórico");
            }
        }

        if (current.delaySeconds !== null && current.delaySeconds !== undefined) {
            setValueWithUnit(els.delay, `+${current.delaySeconds}`, 's');
            setLevel(els.delay, current.level ?? 'good');
            if (els.delayHint) {
                els.delayHint.textContent = current.delayRatio != null
                    ? `${Math.round(current.delayRatio * 100)}${rt("% acima do histórico")}`
                    : '';
            }
        }

        if (current.recordedAt && els.updated) {
            const date = new Date(current.recordedAt);
            if (!Number.isNaN(date.getTime())) {
                els.updated.dateTime = current.recordedAt;
                els.updated.textContent = date.toLocaleTimeString(ROUTES_LOCALE, {
                    timeZone, hour: '2-digit', minute: '2-digit',
                });
            }
        }

        if (els.stale && current.isStale === false) {
            els.stale.hidden = true;
        }
    }

    function applyNewAlerts(alerts) {
        const fresh = alerts.filter((a) => !knownAlertIds.has(a.id));
        if (fresh.length === 0) return;

        fresh.forEach((a) => {
            knownAlertIds.add(a.id);
            const where = a.street ? ` — ${a.street}` : '';
            state.newAlertLabels.unshift(`${a.typeLabel || a.type || rt("Alerta")}${where}`);
        });
        state.newAlertCount += fresh.length;

        onNewAlerts?.(fresh);

        if (!els.alerts) return;

        const shown = state.newAlertLabels.slice(0, MAX_LISTED_ALERTS).join(' · ');
        const extra = state.newAlertCount > MAX_LISTED_ALERTS ? ` · +${state.newAlertCount - MAX_LISTED_ALERTS}` : '';

        els.alerts.replaceChildren();
        const strong = document.createElement('strong');
        strong.textContent = state.newAlertCount === 1
            ? rt("1 novo alerta perto do traçado")
            : rt('ui.new_alerts', {'%count%': state.newAlertCount});
        const span = document.createElement('span');
        span.textContent = ` ${shown}${extra}`;
        els.alerts.append(strong, span);
        els.alerts.hidden = false;
    }

    // ── busca ────────────────────────────────────────────────────────────

    async function refresh() {
        if (state.inFlight || document.hidden) return;
        state.inFlight = true;

        try {
            const url = new URL(liveUrl, window.location.origin);
            if (state.since) url.searchParams.set('since', state.since);

            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const data = await response.json();
            if (!data.ok) throw new Error(rt("resposta inválida"));

            applyCurrent(data.current);
            applyNewAlerts(data.newAlerts ?? []);
            if (data.generatedAt) state.since = data.generatedAt;
        } catch (error) {
            console.debug('[WazeBR route-live] refresh falhou', error);
        } finally {
            state.inFlight = false;
        }
    }

    function scheduleRefresh() {
        clearTimeout(state.refetchTimer);
        state.refetchTimer = setTimeout(refresh, REFETCH_DEBOUNCE_MS);
    }

    // ── transporte ───────────────────────────────────────────────────────

    function setBadge(on) {
        if (els.badge) els.badge.hidden = !on;
    }

    function startPolling() {
        if (state.pollTimer) return;
        state.pollTimer = setInterval(refresh, POLL_INTERVAL_MS);
    }

    function stopPolling() {
        clearInterval(state.pollTimer);
        state.pollTimer = null;
    }

    function stopRealtime() {
        if (state.eventSource) {
            try { state.eventSource.close(); } catch { /* noop */ }
            state.eventSource = null;
        }
        setBadge(false);
    }

    async function startRealtime() {
        if (typeof EventSource === 'undefined') {
            startPolling();
            return;
        }

        let meta;
        try {
            const res = await fetch(streamUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            meta = await res.json();
        } catch (error) {
            console.debug('[WazeBR route-live] stream/info falhou — usando polling', error);
            startPolling();
            return;
        }

        if (!meta?.hub || !meta?.topic) {
            startPolling();
            return;
        }

        try {
            const hubUrl = new URL(meta.hub, window.location.origin);
            hubUrl.searchParams.append('topic', meta.topic);
            state.eventSource = new EventSource(hubUrl.toString(), { withCredentials: true });
        } catch (error) {
            console.debug('[WazeBR route-live] SSE não iniciou — usando polling', error);
            startPolling();
            return;
        }

        state.eventSource.addEventListener('open', () => {
            state.failures = 0;
            stopPolling();
            setBadge(true);
        });

        state.eventSource.addEventListener('message', scheduleRefresh);

        state.eventSource.addEventListener('error', () => {
            setBadge(false);
            state.failures += 1;

            if (state.failures >= SSE_MAX_FAILURES) {
                stopRealtime();
                startPolling();
            }
        });
    }

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) refresh();
    });

    window.addEventListener('pagehide', () => {
        stopRealtime();
        stopPolling();
        clearTimeout(state.refetchTimer);
    }, { once: true });

    // Primeira checagem: pega o que mudou entre o render do servidor e agora.
    refresh();
    await startRealtime();

    return { refresh, stop: () => { stopRealtime(); stopPolling(); } };
}

export default initRouteLive;
