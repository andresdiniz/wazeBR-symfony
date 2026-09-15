/**
 * app.js — Entry point do WazeBR
 *
 * Carrega apenas os CSS/JS necessários para a página atual, baseado em
 * presença de seletores no DOM. Cada componente se registra aqui e, se
 * precisar de comportamento, expõe um `init()` que é chamado uma única vez.
 *
 * Para desabilitar a detecção automática e forçar carregar tudo, defina
 * `window.__WAZEBR_FORCE_ALL__ = true` antes deste módulo rodar.
 */

// ── 1. Estilos críticos (sempre) ───────────────────────────────────────
import '../css/layout/base-layout.css';
import '../css/layout/header.css';
import '../css/layout/sidebar.css';
import '../css/layout/footer.css';
import '../css/components/typography.css';
import '../css/components/animations.css';

// ── 2. Registro de componentes ─────────────────────────────────────────
//
// match: seletor CSS. Se presente no DOM, o componente é carregado.
// always: ignora match (sempre carrega).
// css: import dinâmico do CSS (opcional).
// init: import dinâmico do JS de comportamento (opcional). O módulo deve
//       exportar `default` (função) OU `init` (função nomeada).
//
// Observação: páginas "pesadas" (dashboard) mantêm o CSS como <link>
// crítico no template — o `<link>` garante FCP rápido e evita FOUC.
// Só o JS de comportamento é lazy-loaded aqui.
//
const COMPONENTS = {
    scrollbars: {
        always: true,
        css: () => import('../css/components/scrollbars.css'),
    },
    buttons: {
        match: '.btn, .dashboard-button, .expand-button, .dashboard-expand',
        css: () => import('../css/components/buttons.css'),
        init: () => import('./components/buttons.js'),
    },
    cards: {
        match: '.card, .panel, .dashboard-panel, .dashboard-kpi-card',
        css: () => import('../css/components/cards.css'),
    },
    forms: {
        match: '.form-group, .form-control, .form-select, .form-input',
        css: () => import('../css/components/forms.css'),
        init: () => import('./components/forms.js'),
    },
    filters: {
        match: '.filter-bar, .dashboard-filter-panel',
        css: () => import('../css/components/filters.css'),
    },
    tables: {
        match: '.table, .api-docs, .api-documentation',
        css: () => import('../css/components/tables.css'),
    },
    charts: {
        match: '.chart-container, [data-chart]',
        css: () => import('../css/components/charts.css'),
    },
    accordions: {
        match: 'details',
        css: () => import('../css/components/accordions.css'),
        init: () => import('./components/accordions.js'),
    },
    alerts: {
        match: '.alert, .badge',
        css: () => import('../css/components/alerts.css'),
        init: () => import('./components/alerts.js'),
    },
    notifications: {
        match: '.notification-list, .notification-item',
        css: () => import('../css/components/notifications.css'),
    },
    avatars: {
        match: '.avatar',
        css: () => import('../css/components/avatars.css'),
    },
    menus: {
        match: '.menu, .dropdown-menu, [data-dropdown]',
        css: () => import('../css/components/menus.css'),
        init: () => import('./components/menus.js'),
    },
    modals: {
        match: '.modal, [data-modal-open]',
        css: () => import('../css/components/modals.css'),
        init: () => import('./components/modals.js'),
    },
    pagination: {
        match: '.pagination',
        css: () => import('../css/components/pagination.css'),
        init: () => import('./components/pagination.js'),
    },
    emptyStates: {
        match: '.empty-state, .dashboard-empty',
        css: () => import('../css/components/empty-states.css'),
    },

    // ─── Página: Dashboard ──────────────────────────────────────────
    // O CSS é carregado via <link> no template (crítico, FCP).
    // O JS de comportamento é lazy-loaded só quando [data-dashboard] existe.
    dashboard: {
        match: '[data-dashboard]',
        init: () => import('./pages/dashboard.js'),
    },
};

// ── 3. Infra interna ───────────────────────────────────────────────────

const LOG = '[WazeBR]';
const loaded = new Set();

/** Garante que o mesmo init não rode duas vezes (HMR / re-bootstrap). */
function once(name) {
    if (loaded.has(name)) return false;
    loaded.add(name);
    return true;
}

/** Chama `default` ou `init` — o que existir no módulo. */
function runInit(mod) {
    if (!mod) return;
    if (typeof mod.default === 'function') return mod.default();
    if (typeof mod.init === 'function')    return mod.init();
}

/** Descobre quais componentes casam com o DOM atual. */
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

/** Carrega CSS + init (se houver). Nunca deixa uma falha derrubar as outras. */
async function loadOne(name, cfg) {
    if (!once(name)) return;

    try {
        if (cfg.css) await cfg.css();
    } catch (err) {
        console.error(`${LOG} falha ao carregar CSS de "${name}"`, err);
        return;
    }

    if (cfg.init) {
        try {
            const mod = await cfg.init();
            await runInit(mod);
        } catch (err) {
            console.error(`${LOG} falha ao inicializar "${name}"`, err);
        }
    }

    console.debug(`${LOG} componente pronto: ${name}`);
}

// ── 4. Bootstrap ───────────────────────────────────────────────────────

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
