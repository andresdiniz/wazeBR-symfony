/**
 * menus.js — Dropdowns acessíveis.
 */

const OPEN_CLASS = 'is-open';

export function initMenus(root = document) {
    if (root.__menusInit) return;
    root.__menusInit = true;

    const closeAll = (except) => {
        root.querySelectorAll('[data-dropdown]:not([hidden])').forEach((menu) => {
            if (menu === except) return;
            menu.hidden = true;
            menu.classList.remove(OPEN_CLASS);

            const id = menu.dataset.dropdown;
            const trigger = root.querySelector(`[data-dropdown-trigger="${CSS.escape(id)}"]`);
            trigger?.setAttribute('aria-expanded', 'false');
        });
    };

    root.querySelectorAll('[data-dropdown-trigger]').forEach((trigger) => {
        trigger.addEventListener('click', (e) => {
            e.stopPropagation();
            const id = trigger.dataset.dropdownTrigger;
            const menu = root.querySelector(`[data-dropdown="${CSS.escape(id)}"]`);
            if (!menu) return;

            const willOpen = menu.hidden;
            closeAll(willOpen ? menu : null);

            menu.hidden = !willOpen;
            menu.classList.toggle(OPEN_CLASS, willOpen);
            trigger.setAttribute('aria-expanded', String(willOpen));
        });
    });

    document.addEventListener('click', () => closeAll());
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeAll();
    });
}

export default initMenus;
