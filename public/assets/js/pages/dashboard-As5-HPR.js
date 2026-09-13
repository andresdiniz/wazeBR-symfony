/**
 * pages/dashboard.js
 * Funcionalidades exclusivas da página de dashboard:
 *   - Clock ao vivo
 *   - Contadores animados
 *   - Botão de refresh
 *
 * Filtros, paginação, expand-buttons e charts são inicializados
 * pelos componentes genéricos em app-init.js.
 *
 * Este arquivo NÃO tem um entrypoint próprio no importmap —
 * é importado diretamente pelo app-init.js.
 */

// ── Clock ─────────────────────────────────────────────────────────────────────

export function initDashboardClock(doc = globalThis.document) {
    const clock = doc.querySelector('[data-dashboard-clock]');
    if (!clock) return;

    const fmt    = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short' });
    const update = () => { clock.textContent = fmt.format(new Date()); };

    update();
    globalThis.setInterval(update, 30_000);
}

// ── Contadores animados ───────────────────────────────────────────────────────

export function initDashboardCounters(doc = globalThis.document) {
    doc.querySelectorAll('[data-count-value]').forEach((el) => {
        const target = Number(el.dataset.countValue ?? 0);
        if (!Number.isFinite(target) || target === 0) return;

        const start = performance.now();
        const tick  = (now) => {
            const progress = Math.min((now - start) / 700, 1);
            el.textContent = String(Math.round(target * (1 - Math.pow(1 - progress, 3))));
            if (progress < 1) globalThis.requestAnimationFrame(tick);
        };
        globalThis.requestAnimationFrame(tick);
    });
}

// ── Refresh ───────────────────────────────────────────────────────────────────

export function initDashboardRefresh(doc = globalThis.document) {
    doc.querySelector('[data-action="refresh-dashboard"]')?.addEventListener('click', (e) => {
        const btn = e.currentTarget;
        btn.classList.add('is-loading');
        btn.setAttribute('aria-busy', 'true');
        globalThis.location.reload();
    });
}
