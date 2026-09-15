/**
 * modals.js — Modais nativos.
 *
 * Markup esperado:
 *   <button data-modal-open="id">Abrir</button>
 *   <div class="modal" data-modal="id" hidden>...</div>
 *
 * Fecha ao clicar no backdrop, [data-modal-close], ou Esc.
 * Move o foco para dentro do modal ao abrir e devolve ao trigger ao fechar.
 */

let lastFocused = null;

export default function initModals(root = document) {
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
        // clique no backdrop
        if (e.target.classList.contains('modal')) {
            close(e.target);
        }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        root.querySelectorAll('.modal:not([hidden])').forEach(close);
    });

    // API global para abrir via JS
    window.WazeBR = window.WazeBR || {};
    window.WazeBR.openModal  = (id) => open(id);
    window.WazeBR.closeModal = (id) => close(root.querySelector(`[data-modal="${CSS.escape(id)}"]`));
}
