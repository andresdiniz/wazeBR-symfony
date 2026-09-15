/**
 * alerts.js — Auto-dismiss de .alert[data-autodismiss] e botão de fechar.
 */

export default function initAlerts(root = document) {
    const dismiss = (el) => {
        el.classList.add('fade-out');
        el.addEventListener('transitionend', () => el.remove(), { once: true });
        // fallback caso não haja transição
        setTimeout(() => el.remove(), 400);
    };

    root.querySelectorAll('.alert[data-autodismiss]').forEach((el) => {
        const delay = Number(el.dataset.autodismiss) || 5000;
        setTimeout(() => dismiss(el), delay);
    });

    root.addEventListener('click', (e) => {
        const btn = e.target.closest('.alert [data-dismiss], .alert .alert-close');
        if (!btn) return;
        const alert = btn.closest('.alert');
        if (alert) dismiss(alert);
    });
}
