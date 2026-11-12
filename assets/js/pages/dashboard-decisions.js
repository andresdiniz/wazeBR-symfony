'use strict';

(function () {
    function mount() {
        const root = document.querySelector('[data-dashboard]');
        const board = document.querySelector('[data-decision-board]');
        const container = root?.querySelector('.dashboard-container');
        const commandbar = container?.querySelector('.dashboard-commandbar');
        if (!root || !board || !container || !commandbar || board.dataset.mounted === '1') return;
        const now = board.querySelector('[data-decision-slot="now"]');
        const change = board.querySelector('[data-decision-slot="change"]');
        const action = board.querySelector('[data-decision-slot="action"]');
        const kpis = container.querySelector('.dashboard-kpi-grid');
        const operations = container.querySelector('.dashboard-operations-grid');
        const rail = container.querySelector('.dashboard-context-rail');
        const recentPanel = (key) => container.querySelector(`[data-list="${key}"]`)?.closest('.dashboard-panel');
        const alerts = recentPanel('alerts');
        const jams = recentPanel('jams');
        const routes = recentPanel('routes');
        commandbar.after(board);
        if (kpis) now.append(kpis);
        if (rail) action.append(rail);
        if (operations) now.append(operations);
        if (alerts) now.append(alerts);
        if (jams && jams !== alerts) now.append(jams);
        if (routes && routes !== alerts && routes !== jams) change.append(routes);
        if (!routes) {
            const note = document.createElement('p');
            note.className = 'decision-notice';
            note.textContent = 'Lista de rotas indisponível neste layout. Nenhuma conclusão de piora foi calculada.';
            change.append(note);
        }
        const more = document.createElement('h2');
        more.className = 'decision-more-title';
        more.textContent = 'Análises complementares';
        board.after(more);
        container.querySelectorAll('.dashboard-main-grid, .dashboard-recent-grid').forEach((grid) => {
            if (!grid.children.length) grid.remove();
        });
        board.dataset.mounted = '1';
        board.classList.add('is-mounted');
        requestAnimationFrame(() => window.dashboardMap?.invalidateSize());
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mount, { once: true });
    } else {
        mount();
    }
    window.addEventListener('wazebr:ready', mount);
})();
