// assets/js/pages/partner-feed-index.js

/**
 * Inicialização da página de índice do Feed de Parceiros.
 *
 * A página é predominantemente server-rendered. Este entrypoint existe para
 * manter o JavaScript separado e permitir futuras interações sem inline script.
 */
export function initPartnerFeedIndex(root = document) {
    const page = root.querySelector('[data-page="partner-feed-index"]');

    if (!page) {
        return;
    }

    page.querySelectorAll('.partner-card').forEach((card) => {
        card.addEventListener('mouseenter', () => {
            card.dataset.hovered = 'true';
        });

        card.addEventListener('mouseleave', () => {
            delete card.dataset.hovered;
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initPartnerFeedIndex();
});
