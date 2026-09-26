/**
 * app.js — Entry point para Symfony AssetMapper
 *
 * ⚠️ Regras:
 *   1. CSS NUNCA é importado daqui. Vive em assets/styles/app.css.
 *   2. Imports dinâmicos DEVEM ser string literal (AssetMapper varre em build).
 *   3. Só carrega o que está presente no DOM (via `match`).
 *
 * Debug: defina window.__WAZEBR_FORCE_ALL__ = true antes pra forçar tudo.
 */

// ── Registry de componentes ────────────────────────────────────────────
//
// match  → seletor CSS que precisa existir no DOM
// always → ignora match (roda sempre)
// init   → import dinâmico do módulo de comportamento
//
const COMPONENTS = {
    // ─── Layout (presente em todas as páginas) ────────────────────────
    baseLayout: {
        always: true,
        init: () => import('./layout/base-layout.js'),
    },
    header: {
        match: '[data-app-header]',
        init: () => import('./layout/header.js'),
    },
    sidebar: {
        match: '[data-sidebar]',
        init: () => import('./layout/sidebar.js'),
    },
    footer: {
        match: '[data-component="footer"]',
        init: () => import('./layout/footer.js'),
    },

    // ─── Componentes genéricos ────────────────────────────────────────
    buttons: {
        match: '.btn, .dashboard-button, .expand-button, .dashboard-expand',
        init: () => import('./components/buttons.js'),
    },
    forms: {
        match: '.form-group, .form-control, .form-select, .form-input',
        init: () => import('./components/forms.js'),
    },
    accordions: {
        match: 'details',
        init: () => import('./components/accordions.js'),
    },
    alerts: {
        match: '.alert, .badge',
        init: () => import('./components/alerts.js'),
    },
    menus: {
        match: '.menu, .dropdown-menu, [data-dropdown]',
        init: () => import('./components/menus.js'),
    },
    modals: {
        match: '.modal, [data-modal-open]',
        init: () => import('./components/modals.js'),
    },
    pagination: {
        match: '.pagination',
        init: () => import('./components/pagination.js'),
    },

    // ─── Página: Dashboard ────────────────────────────────────────────
    dashboard: {
        match: '[data-dashboard]',
        init: () => import('./pages/dashboard.js'),
    },

    // ─── Página: Rotas ────────────────────────────────────────────────
    routes: {
        match: '[data-routes-page]',
        init: () => import('./pages/routes.js'),
    },
    routeShow: {
        match: '[data-route-show]',
        init: () => import('./pages/route-show.js'),
    },
    routeCompare: {
        match: '[data-route-compare]',
        init: () => import('./pages/route-compare.js'),
    },

    // ─── Consentimento (roda antes do analytics) ──────────────────────
    consent: {
        match: '[data-consent-banner]',
        init: () => import('./layout/consent.js'),
    },

    // ─── Analytics ────────────────────────────────────────────────────
    analytics: {
        match: '[data-analytics-config]',
        init: () => import('./layout/analytics.js'),
    },

    // ─── Página: Clima ────────────────────────────────────────────────
    weatherIndex: {
        match: '[data-weather-index]',
        init: () => import('./pages/weather-index.js'),
    },
    weatherShow: {
        match: '[data-weather-show]',
        init: () => import('./pages/weather-show.js'),
    },
    weatherAnalysis: {
        match: '[data-weather-analysis]',
        init: () => import('./pages/weather-analysis.js'),
    },

    // ─── Página: TV / wallboard ───────────────────────────────────────
    tv: {
        match: '[data-tv-wallboard]',
        init: () => import('./pages/tv.js'),
    },

    // ─── Admin: Parceiros ─────────────────────────────────────────────
    adminPartner: {
        match: '[data-admin-partner]',
        init: () => import('./pages/admin-partner.js'),
    },

    // ─── Admin: Usuários ──────────────────────────────────────────────
    adminUser: {
        match: '[data-admin-user]',
        init: () => import('./pages/admin-user.js'),
    },

    // ─── Página: Congestionamentos (lista) ────────────────────────────
    jam: {
        match: '[data-jam-page]',
        init: () => import('./pages/jam.js'),
    },

    // ─── Página: Congestionamento (detalhes + impacto) ────────────────
    jamShow: {
        match: '[data-jam-show]',
        init: () => import('./pages/jam-show.js'),
    },

    // ─── Página: Feed de Parceiros (índice) ────────────────────────────
    partnerFeedIndex: {
        match: '[data-partner-feed-index]',
        init: () => import('./pages/partner-feed-index.js'),
    },
    partnerFeedShow: {
        match: '[data-partner-feed-show]',
        init: () => import('./pages/partner-feed-show.js'),
    },

    // ─── Página: Feed de Parceiros (form novo/editar evento) ──────────────
    partnerFeedForm: {
        match: '[data-partner-feed-form]',
        init: () => import('./pages/partner-feed-form.js'),
    },

    // ─── Página: Eventos do Feed de Parceiros ─────────────────────────
    partnerFeedEventsList: {
        match: '[data-partner-events-list]',
        init: () => import('./pages/partner-feed-events-list.js'),
    },
};

// ── Infra ──────────────────────────────────────────────────────────────

const LOG = '[WazeBR]';
const loaded = new Set();

function once(name) {
    if (loaded.has(name)) return false;
    loaded.add(name);
    return true;
}

/**
 * Acha a função de init no módulo.
 * Ordem: default → init → qualquer initXxx → o próprio módulo (função).
 */
function findInit(mod) {
    if (!mod) return null;
    if (typeof mod === 'function') return mod;
    if (typeof mod.default === 'function') return mod.default;
    if (typeof mod.init === 'function') return mod.init;

    for (const [key, val] of Object.entries(mod)) {
        if (typeof val === 'function' && /^init[A-Z]/.test(key)) {
            return val;
        }
    }
    return null;
}

function resolveMatches(doc) {
    const force = typeof window !== 'undefined' && window.__WAZEBR_FORCE_ALL__ === true;
    const out = [];

    for (const [name, cfg] of Object.entries(COMPONENTS)) {
        if (!cfg) continue;
        if (cfg.always || force) { out.push([name, cfg]); continue; }
        if (cfg.match && doc.querySelector(cfg.match)) {
            out.push([name, cfg]);
        }
    }
    return out;
}

async function loadOne(name, cfg) {
    if (!once(name)) return;

    if (cfg.init) {
        try {
            const mod = await cfg.init();
            const init = findInit(mod);

            if (typeof init !== 'function') {
                console.warn(`${LOG} "${name}" não expôs init() — ignorando`, mod);
                return;
            }

            await init(document);
        } catch (err) {
            console.error(`${LOG} falha ao inicializar "${name}"`, err);
        }
    }

    console.debug(`${LOG} componente pronto: ${name}`);
}

// ── Bootstrap ──────────────────────────────────────────────────────────

async function bootstrap(doc = document) {
    const matches = resolveMatches(doc);

    if (matches.length === 0) {
        console.debug(`${LOG} nenhum componente para carregar`);
        return;
    }

    await Promise.allSettled(matches.map(([name, cfg]) => loadOne(name, cfg)));

    window.dispatchEvent(new CustomEvent('wazebr:ready', {
        detail: { components: [...loaded] },
    }));
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => bootstrap(), { once: true });
    } else {
        bootstrap();
    }
}

console.debug(`${LOG} app.js carregado`);

export { bootstrap };
