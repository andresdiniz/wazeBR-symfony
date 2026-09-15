/**
 * app.js — Entry point para Symfony AssetMapper
 *
 * ⚠️ Importante:
 *   - CSS NUNCA é importado daqui. Use `app.css` (via @import) + <link> no Twig.
 *   - Imports dinâmicos SÓ funcionam com string literal (o AssetMapper
 *     varre o código em build-time).
 */

// ── Estilos críticos (sempre) ──────────────────────────────────────────
// NÃO importamos CSS aqui. Tudo vive em styles/app.css.

// ── Registry de componentes JS ─────────────────────────────────────────
//
// Cada entrada tem:
//   match  → seletor no DOM
//   init   → import dinâmico (string literal! o AssetMapper precisa achar)
//   always → ignora match (roda sempre)
//
const COMPONENTS = {
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

    // ─── Página: Dashboard ──────────────────────────────────────────
    // Só carrega em páginas que têm [data-dashboard].
    dashboard: {
        match: '[data-dashboard]',
        init: () => import('./pages/dashboard.js'),
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
 * Encontra a função de init no módulo.
 * Suporta: default, init, initXxx (named), ou o próprio módulo sendo função.
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
