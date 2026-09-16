/**
 * layout/analytics.js
 * ─────────────────────────────────────────────────────────────────────
 * Módulo de analytics do WazeBR. Carregado pelo registry do app.js quando
 * existe `<script data-analytics-config>` no DOM.
 *
 * Responsabilidades:
 *
 *   1. Ler a config injetada pelo partial (data-analytics-config)
 *   2. Bootstrap do provider (GTM / Plausible / Umami / GA4 / none)
 *   3. Expor `window.WazeBR.track(event, props)` para o resto do app
 *   4. Virtual pageviews em navegação SPA (pushState, popstate)
 *   5. Auto-tracking via atributos `data-analytics-*` no HTML
 *   6. Respeitar consentimento (opt-in / opt-out / off)
 *   7. Sample rate para eventos de alta frequência
 *   8. Tudo em try/catch — analytics NUNCA quebra o app
 *
 * Convenções de evento:
 *
 *   - Nome: snake_case, verbo no passado
 *       ✅ dashboard_filter_applied
 *       ❌ ClickFilter
 *
 *   - Props: primitivos (string, number, boolean).
 *     Sem arrays, sem objetos aninhados, sem PII.
 *
 *   - Propriedades comuns (partner_code, user_role, environment) são
 *     injetadas automaticamente em TODOS os eventos.
 *
 * Debug:
 *   window.__WAZEBR_ANALYTICS_DEBUG__ = true;
 *   → loga cada evento no console
 */

// ─────────────────────────────────────────────────────────────────────
// Estado do módulo
// ─────────────────────────────────────────────────────────────────────

let config = null;               // config JSON lida do DOM
let bootstrapped = false;         // idempotência
let consentGranted = false;       // gate de consent
let currentVirtualPath = null;    // dedupe de pageviews

// Buffer de eventos até consent ser dado (opt-in).
const pendingEvents = [];
const MAX_PENDING = 50;

// ─────────────────────────────────────────────────────────────────────
// Utils
// ─────────────────────────────────────────────────────────────────────

function readConfig() {
    const node = document.querySelector('[data-analytics-config]');
    if (!node) return null;

    try {
        const text = (node.textContent || '').trim();
        if (!text) return null;
        return JSON.parse(text);
    } catch (err) {
        console.warn('[WazeBR analytics] config JSON inválida', err);
        return null;
    }
}

function shouldSample(props = {}) {
    if (props.critical === true) return true;

    const rate = Number(config?.sampleRate ?? 100);
    if (!Number.isFinite(rate) || rate >= 100) return true;
    if (rate <= 0) return false;

    return Math.random() * 100 < rate;
}

/**
 * Sanitiza as props antes de mandar para o provider.
 * Remove PII, trunca strings, descarta objetos/arrays.
 */
function sanitizeProps(props = {}) {
    const out = {};
    const BLOCKED = ['email', 'name', 'nome', 'cpf', 'password', 'token'];

    for (const [key, value] of Object.entries(props)) {
        if (BLOCKED.includes(key.toLowerCase())) continue;
        if (value === null || value === undefined) continue;

        if (typeof value === 'string') {
            out[key] = value.slice(0, 100);
        } else if (typeof value === 'number' || typeof value === 'boolean') {
            out[key] = value;
        }
    }

    return out;
}

function withGlobalProps(props = {}) {
    const base = {
        environment: config.environment,
        partner_code: config.partner?.code ?? null,
        partner_id: config.partner?.id ?? null,
        user_role: config.user?.role ?? 'anonymous',
    };

    return Object.fromEntries(
        Object.entries({ ...base, ...props })
            .filter(([, v]) => v !== null && v !== undefined)
    );
}

// ─────────────────────────────────────────────────────────────────────
// Providers
// ─────────────────────────────────────────────────────────────────────

const PROVIDERS = {

    gtm: {
        ready: () => Array.isArray(window.dataLayer),
        send: (event, props) => {
            window.dataLayer.push({ event, ...props });
        },
    },

    ga4: {
        ready: () => typeof window.gtag === 'function',
        send: (event, props) => {
            window.gtag('event', event, props);
        },
    },

    plausible: {
        ready: () => typeof window.plausible === 'function',
        send: (event, props) => {
            window.plausible(event, { props });
        },
    },

    umami: {
        ready: () => typeof window.umami?.track === 'function',
        send: (event, props) => {
            window.umami.track(event, props);
        },
    },

    none: {
        ready: () => true,
        send: () => {},
    },
};

// ─────────────────────────────────────────────────────────────────────
// Bootstrap do provider
// ─────────────────────────────────────────────────────────────────────

function loadProviderScript() {
    const provider = config.provider;
    const id = config.id;

    switch (provider) {
        case 'gtm':
            // Partial já colocou o snippet. Nada a fazer.
            break;

        case 'ga4': {
            if (window.gtag) return;

            const script = document.createElement('script');
            script.async = true;
            script.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(id)}`;
            document.head.appendChild(script);

            window.dataLayer = window.dataLayer || [];
            window.gtag = function gtag() { window.dataLayer.push(arguments); };
            window.gtag('js', new Date());
            window.gtag('config', id, { send_page_view: false });
            break;
        }

        case 'plausible': {
            if (window.plausible) return;

            const script = document.createElement('script');
            script.async = true;
            script.defer = true;
            script.setAttribute('data-domain', id);
            script.src = 'https://plausible.io/js/script.js';
            document.head.appendChild(script);
            break;
        }

        case 'umami': {
            if (window.umami?.track) return;
            if (!config.host) {
                console.warn('[WazeBR analytics] Umami sem host configurado');
                return;
            }

            const script = document.createElement('script');
            script.async = true;
            script.defer = true;
            script.setAttribute('data-website-id', id);
            script.src = `${config.host.replace(/\/$/, '')}/script.js`;
            document.head.appendChild(script);
            break;
        }

        case 'none':
        default:
            break;
    }
}

function waitForProvider(timeoutMs = 3000) {
    const provider = PROVIDERS[config.provider] ?? PROVIDERS.none;
    if (provider.ready()) return Promise.resolve(true);

    return new Promise((resolve) => {
        const start = Date.now();
        const check = () => {
            if (provider.ready()) return resolve(true);
            if (Date.now() - start > timeoutMs) return resolve(false);
            setTimeout(check, 50);
        };
        check();
    });
}

// ─────────────────────────────────────────────────────────────────────
// API principal — track()
// ─────────────────────────────────────────────────────────────────────

export function track(event, props = {}) {
    try {
        if (!config) return;
        if (typeof event !== 'string' || !event) return;

        // Gate de consent
        if (config.consent !== 'off' && !consentGranted) {
            if (pendingEvents.length < MAX_PENDING) {
                pendingEvents.push({ event, props });
            }
            return;
        }

        // Sample rate
        if (!shouldSample(props)) return;

        const cleanProps = withGlobalProps(sanitizeProps(props));
        const provider = PROVIDERS[config.provider] ?? PROVIDERS.none;

        provider.send(event, cleanProps);

        if (window.__WAZEBR_ANALYTICS_DEBUG__) {
            console.debug('[WazeBR analytics]', event, cleanProps);
        }
    } catch (err) {
        console.warn('[WazeBR analytics] track falhou', event, err);
    }
}

export function pageview(path = null) {
    try {
        if (!config) return;

        const url = path ?? (window.location.pathname + window.location.search);
        if (url === currentVirtualPath) return;
        currentVirtualPath = url;

        if (config.consent !== 'off' && !consentGranted) return;

        const provider = config.provider;

        if (provider === 'plausible' && typeof window.plausible === 'function') {
            window.plausible('pageview', { u: url });
            return;
        }
        if (provider === 'umami' && typeof window.umami?.track === 'function') {
            window.umami.track({ url });
            return;
        }

        track('virtual_pageview', { path: url });
    } catch (err) {
        console.warn('[WazeBR analytics] pageview falhou', err);
    }
}

// ─────────────────────────────────────────────────────────────────────
// Virtual pageviews — interceptar pushState / popstate
// ─────────────────────────────────────────────────────────────────────

function installVirtualPageviews() {
    // Pageview inicial
    pageview();

    // Intercepta pushState
    const originalPush = history.pushState;
    history.pushState = function patchedPushState(...args) {
        originalPush.apply(this, args);
        setTimeout(() => pageview(), 0);
    };

    // Intercepta replaceState
    const originalReplace = history.replaceState;
    history.replaceState = function patchedReplaceState(...args) {
        originalReplace.apply(this, args);
        setTimeout(() => pageview(), 0);
    };

    // Botão voltar/avançar do browser
    window.addEventListener('popstate', () => pageview());
}

// ─────────────────────────────────────────────────────────────────────
// Auto-tracking via atributos HTML
// ─────────────────────────────────────────────────────────────────────

function installAutoTracking(root = document) {
    root.addEventListener('click', (evt) => {
        const el = evt.target.closest('[data-analytics-event]');
        if (!el) return;

        const event = el.dataset.analyticsEvent;
        if (!event) return;

        const props = {};
        for (const [key, value] of Object.entries(el.dataset)) {
            if (!key.startsWith('analytics')) continue;
            if (key === 'analyticsEvent' || key === 'analyticsProps') continue;

            const propKey = key
                .replace(/^analytics/, '')
                .replace(/[A-Z]/g, (m) => '_' + m.toLowerCase())
                .replace(/^_/, '');

            if (propKey) props[propKey] = value;
        }

        if (el.dataset.analyticsProps) {
            try {
                Object.assign(props, JSON.parse(el.dataset.analyticsProps));
            } catch { /* ignora */ }
        }

        track(event, props);
    });
}

// ─────────────────────────────────────────────────────────────────────
// Consent
// ─────────────────────────────────────────────────────────────────────

function installConsentHandling() {
    if (config.consent === 'off') {
        consentGranted = true;
        flushPending();
        return;
    }

    if (config.consent === 'opt-out') {
        consentGranted = true;
        flushPending();
    }

    // Modo 'opt-in': off até consent
    document.addEventListener('wazebr:consent:granted', () => {
        consentGranted = true;
        flushPending();
        currentVirtualPath = null;
        pageview();
    });

    document.addEventListener('wazebr:consent:denied', () => {
        consentGranted = false;
        pendingEvents.length = 0;
    });

    // Se o consent.js já rodou antes deste módulo
    if (window.WazeBR?.consent?.granted?.()) {
        consentGranted = true;
        flushPending();
    }
}

function flushPending() {
    if (pendingEvents.length === 0) return;
    const batch = pendingEvents.splice(0);
    batch.forEach(({ event, props }) => track(event, props));
}

// ─────────────────────────────────────────────────────────────────────
// Bootstrap
// ─────────────────────────────────────────────────────────────────────

export function initAnalytics(root = document) {
    if (bootstrapped) return;

    config = readConfig();
    if (!config) return;

    if (!config.provider || config.provider === 'none') return;

    bootstrapped = true;

    installConsentHandling();
    loadProviderScript();
    installVirtualPageviews();
    installAutoTracking(root);

    waitForProvider().then((ready) => {
        if (!ready) {
            console.warn('[WazeBR analytics] provider não respondeu a tempo');
            return;
        }
        if (consentGranted) flushPending();
    });

    window.WazeBR = window.WazeBR || {};
    window.WazeBR.track = track;
    window.WazeBR.pageview = pageview;

    console.debug('[WazeBR analytics] pronto', {
        provider: config.provider,
        consent: config.consent,
        sampleRate: config.sampleRate,
    });
}

export default initAnalytics;
