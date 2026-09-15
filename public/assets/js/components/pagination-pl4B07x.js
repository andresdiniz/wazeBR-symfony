/**
 * pagination.js — Controle de paginação client-side simples.
 *
 * Se existir [data-pagination] em uma lista, ele pagina em memória os
 * itens filhos. Não interfere quando a paginação é server-side (links <a>).
 */

export default function initPagination(root = document) {
    root.querySelectorAll('[data-pagination]').forEach((nav) => {
        const targetSel = nav.dataset.pagination;
        if (!targetSel) return;

        const list = root.querySelector(targetSel);
        if (!list) return;

        const perPage = Number(nav.dataset.perPage) || 10;
        const items = Array.from(list.children);
        const pages = Math.ceil(items.length / perPage);
        if (pages <= 1) {
            nav.innerHTML = '';
            return;
        }

        let current = 1;

        const render = () => {
            const start = (current - 1) * perPage;
            items.forEach((item, i) => {
                item.hidden = i < start || i >= start + perPage;
            });
            nav.querySelectorAll('button').forEach((btn) => {
                btn.classList.toggle('is-active', Number(btn.dataset.page) === current);
            });
        };

        nav.innerHTML = Array.from({ length: pages }, (_, i) => {
            const page = i + 1;
            return `<button type="button" data-page="${page}"${page === current ? ' class="is-active"' : ''}>${page}</button>`;
        }).join('');

        nav.addEventListener('click', (e) => {
            const btn = e.target.closest('button[data-page]');
            if (!btn) return;
            current = Number(btn.dataset.page);
            render();
        });

        render();
    });
}
