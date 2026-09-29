// assets/js/pages/traffic-light-list.js

'use strict';

export function init(root = document) {
    const table = root.querySelector('.tl-list');
    if (!table) return;

    // Delegação de evento para os botões de excluir
    table.addEventListener('submit', (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (!form.matches('form[data-action="delete"]')) return;

        const code = form.dataset.code || 'este controlador';
        if (!window.confirm(`Remover "${code}"? Esta ação não pode ser desfeita.`)) {
            e.preventDefault();
        }
    });

    // Atualiza o tempo relativo "há X minutos"
    const times = table.querySelectorAll('[data-tl-time]');
    const now = Date.now();
    times.forEach((el) => {
        const iso = el.dataset.tlTime;
        if (!iso) return;
        const t = new Date(iso).getTime();
        if (Number.isNaN(t)) return;

        const diffSec = Math.max(0, Math.floor((now - t) / 1000));
        let label = '';
        if (diffSec < 60)         label = `${diffSec}s atrás`;
        else if (diffSec < 3600)  label = `${Math.floor(diffSec / 60)} min atrás`;
        else if (diffSec < 86400) label = `${Math.floor(diffSec / 3600)} h atrás`;
        else                      label = `${Math.floor(diffSec / 86400)} d atrás`;

        el.title = new Date(iso).toLocaleString();
        el.textContent = label;
    });
}

export default init;
