export function openModal(modal) {
    if (!modal) return;
    modal.hidden = false;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
}

export function closeModal(modal) {
    if (!modal) return;
    modal.hidden = true;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
}
