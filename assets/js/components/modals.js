/**
 * modals.js — Modais nativos.
 */

let lastFocused = null;

export function initModals(root = document) {
    if (root.__modalsInit) return;
    root.__modalsInit = true;

    const open = (id, trigger) => {
        const modal = root.querySelector(`[data-modal="${CSS.escape(id)}"]`);
        if (!modal) return;

        lastFocused = trigger || document.activeElement;
        modal.hidden = false;
        modal.classList.add('fade-in');
        document.body.style.overflow = 'hidden';

        const focusTarget = modal.querySelector('[autofocus]')
            || modal.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
        focusTarget?.focus();
    };

    const close = (modal) => {
        if (!modal) return;
        modal.hidden = true;
        document.body.style.overflow = '';
        lastFocused?.focus?.();
    };

    root.querySelectorAll('[data-modal-open]').forEach((btn) => {
        btn.addEventListener('click', () => open(btn.dataset.modalOpen, btn));
    });

    root.addEventListener('click', (e) => {
        const closeBtn = e.target.closest('[data-modal-close]');
        if (closeBtn) {
            close(closeBtn.closest('.modal'));
            return;
        }
        if (e.target.classList.contains('modal')) {
            close(e.target);
        }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        root.querySelectorAll('.modal:not([hidden])').forEach(close);
    });

    window.WazeBR = window.WazeBR || {};
    window.WazeBR.openModal  = (id) => open(id);
    window.WazeBR.closeModal = (id) => close(root.querySelector(`[data-modal="${CSS.escape(id)}"]`));
}

export default initModals;
