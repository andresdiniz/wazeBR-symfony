/**
 * accordions.js — Um <details> aberto por grupo (data-accordion-group).
 */

export default function initAccordions(root = document) {
    root.querySelectorAll('details[data-accordion-group]').forEach((detail) => {
        detail.addEventListener('toggle', () => {
            if (!detail.open) return;
            const group = detail.dataset.accordionGroup;
            if (!group) return;

            root.querySelectorAll(
                `details[data-accordion-group="${CSS.escape(group)}"]`
            ).forEach((other) => {
                if (other !== detail) other.open = false;
            });
        });
    });
}
