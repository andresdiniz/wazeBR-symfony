/**
 * pages/admin-partner.js
 * ─────────────────────────────────────────────────────────────────────
 * Comportamentos das telas de parceiros:
 *
 *   - Preview dinâmico do path do feed
 *   - Uppercase em [data-uppercase]
 *   - Copiar URL do feed para o clipboard
 *   - Confirmação + loading no toggle de status
 *   - Loading state no submit do form de edição/criação
 *   - Modal de exclusão com gerenciamento de foco
 *
 * Disparado pelo registry quando existe [data-admin-partner].
 */

const FEED_PATH      = (code) => `/feed/${code}/feed.json`;
const CODE_SANITIZER = /[^a-zA-Z0-9_-]/g;

export function initAdminPartner(doc = globalThis.document) {
    if (!doc) return;
    if (doc.__adminPartnerInit) return;
    doc.__adminPartnerInit = true;

    bindFeedPreview(doc);
    bindUppercase(doc);
    bindCopyFeed(doc);
    bindToggleConfirm(doc);
    bindSubmitLoading(doc);
    bindDeleteModal(doc);
}

/* ═════════════════════════════════════════════════════════════════════
   PREVIEW DO FEED
   ═════════════════════════════════════════════════════════════════════ */

function bindFeedPreview(doc) {
    const preview = doc.querySelector('[data-feed-preview]');
    if (!preview) return;

    const codeInput = doc.querySelector('[data-code-input]');
    const target    = preview.querySelector('[data-feed-preview-url]');
    const warning   = preview.querySelector('[data-feed-preview-warning]');

    if (!codeInput || !target) return;

    const originalCode = preview.dataset.originalCode
        || doc.querySelector('[data-original-code]')?.dataset.originalCode
        || '';

    const render = () => {
        const safe = codeInput.value
            .trim()
            .toLowerCase()
            .replace(CODE_SANITIZER, '');

        target.textContent = safe ? FEED_PATH(safe) : '—';

        if (warning) {
            warning.hidden = !originalCode || safe === originalCode || safe === '';
        }
    };

    codeInput.addEventListener('input', render);
    render();
}

/* ═════════════════════════════════════════════════════════════════════
   UPPERCASE
   ═════════════════════════════════════════════════════════════════════ */

function bindUppercase(doc) {
    doc.querySelectorAll('[data-uppercase]').forEach((input) => {
        if (input.dataset.uppercaseBound === 'true') return;
        input.dataset.uppercaseBound = 'true';

        input.addEventListener('input', () => {
            const start = input.selectionStart;

            input.value = input.value.toUpperCase();

            try {
                input.setSelectionRange(start, start);
            } catch {
                /* alguns inputs não suportam */
            }
        });
    });
}

/* ═════════════════════════════════════════════════════════════════════
   COPIAR URL DO FEED
   ═════════════════════════════════════════════════════════════════════ */

function bindCopyFeed(doc) {
    doc.addEventListener('click', async (event) => {
        const btn = event.target.closest('[data-copy-feed]');
        if (!btn) return;

        event.preventDefault();

        const value = btn.dataset.copyFeed;
        if (!value) return;

        try {
            if (navigator.clipboard?.writeText) {
                await navigator.clipboard.writeText(value);
            } else {
                legacyCopy(doc, value);
            }

            flashFeedback(btn, 'Copiado!');
        } catch {
            legacyCopy(doc, value);
            flashFeedback(btn, 'Copiado!');
        }
    });
}

function legacyCopy(doc, value) {
    const ta = doc.createElement('textarea');
    ta.value = value;
    ta.setAttribute('readonly', '');
    ta.style.position = 'absolute';
    ta.style.left = '-9999px';
    doc.body.appendChild(ta);
    ta.select();

    try { doc.execCommand('copy'); } catch { /* noop */ }

    ta.remove();
}

function flashFeedback(btn, text) {
    const original = btn.textContent;

    btn.classList.add('is-copied');
    btn.textContent = text;

    window.setTimeout(() => {
        btn.classList.remove('is-copied');
        btn.textContent = original;
    }, 1500);
}

/* ═════════════════════════════════════════════════════════════════════
   TOGGLE — confirmação
   ═════════════════════════════════════════════════════════════════════ */

function bindToggleConfirm(doc) {
    doc.querySelectorAll('[data-toggle-form]').forEach((form) => {
        if (form.dataset.toggleBound === 'true') return;
        form.dataset.toggleBound = 'true';

        form.addEventListener('submit', (event) => {
            const name     = form.dataset.partnerName ?? 'este parceiro';
            const isActive = form.dataset.partnerActive === '1';

            const message = isActive
                ? `Desativar "${name}"?\n\nUsuários e links de coleta serão suspensos.`
                : `Reativar "${name}"?\n\nUsuários e links de coleta serão reativados.`;

            if (!window.confirm(message)) {
                event.preventDefault();
                return;
            }

            const btn = form.querySelector('[data-toggle-btn]');
            if (btn) {
                btn.disabled = true;
                btn.classList.add('is-loading');
            }
        });
    });
}

/* ═════════════════════════════════════════════════════════════════════
   LOADING STATE EM SUBMITS
   ═════════════════════════════════════════════════════════════════════ */

function bindSubmitLoading(doc) {
    doc.querySelectorAll('form.admin-form').forEach((form) => {
        if (form.dataset.submitBound === 'true') return;
        form.dataset.submitBound = 'true';

        form.addEventListener('submit', () => {
            const btn = form.querySelector('[data-submit-btn]');
            if (!btn) return;

            btn.disabled = true;
            btn.classList.add('is-loading');

            const original = btn.textContent;
            btn.textContent = 'Salvando…';

            // Segurança: libera em 10s caso a navegação falhe
            window.setTimeout(() => {
                btn.disabled = false;
                btn.classList.remove('is-loading');
                btn.textContent = original;
            }, 10_000);
        });
    });
}

/* ═════════════════════════════════════════════════════════════════════
   MODAL DE EXCLUSÃO
   ═════════════════════════════════════════════════════════════════════ */

function bindDeleteModal(doc) {
    const modal = doc.querySelector('[data-modal="partner-delete"]');
    if (!modal) return;

    const dialog       = modal.querySelector('.admin-modal__dialog');
    const deleteForm   = modal.querySelector('[data-delete-form]');
    const tokenInput   = deleteForm?.querySelector('input[name="_token"]');
    const flagInput    = deleteForm?.querySelector('[data-delete-flag]');
    const feedCheckbox = modal.querySelector('[data-delete-feed]');
    const nameTarget   = modal.querySelector('[data-delete-partner-name]');
    const codeTarget   = modal.querySelector('[data-delete-partner-code]');
    const submitBtn    = modal.querySelector('[data-delete-submit]');
    const feedLabel    = modal.querySelector('.admin-modal__checkbox');

    if (!deleteForm || !tokenInput) return;

    let lastFocused = null;

    const syncFlag = () => {
        if (flagInput) flagInput.value = feedCheckbox?.checked ? '1' : '0';
    };

    const closeModal = () => {
        modal.hidden = true;
        doc.body.style.overflow = '';

        // Reseta estado visual
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.classList.remove('is-loading');
        }

        lastFocused?.focus?.();
    };

    feedCheckbox?.addEventListener('change', syncFlag);

    // Abrir via botão "Excluir" em qualquer lugar da página
    doc.addEventListener('click', (event) => {
        const btn = event.target.closest('[data-partner-delete]');
        if (!btn) return;

        event.preventDefault();

        const name  = btn.dataset.partnerName ?? '—';
        const code  = btn.dataset.partnerCode ?? '—';
        const feed  = btn.dataset.partnerFeed ?? '';
        const url   = btn.dataset.deleteUrl;
        const token = btn.dataset.deleteToken;

        if (!url || !token) return;

        deleteForm.action = url;
        tokenInput.value  = token;

        if (nameTarget) nameTarget.textContent = name;
        if (codeTarget) codeTarget.textContent = code;

        if (feedLabel)    feedLabel.hidden = !feed;
        if (feedCheckbox) {
            feedCheckbox.checked  = Boolean(feed);
            feedCheckbox.disabled = !feed;
        }

        syncFlag();

        lastFocused = doc.activeElement;
        modal.hidden = false;
        doc.body.style.overflow = 'hidden';

        // Foco inicial
        window.requestAnimationFrame(() => {
            const focusable = modal.querySelector(
                '[autofocus], button:not([disabled]), [href], input, select, textarea'
            );
            focusable?.focus?.();
        });
    });

    // Fechar (backdrop, X, Cancelar)
    doc.addEventListener('click', (event) => {
        const closer = event.target.closest('[data-modal-close]');
        if (!closer) return;
        if (!modal.contains(closer) && closer !== modal) return;

        event.preventDefault();
        closeModal();
    });

    // ESC
    doc.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || modal.hidden) return;

        event.preventDefault();
        closeModal();
    });

    // Focus trap
    dialog?.addEventListener('keydown', (event) => {
        if (event.key !== 'Tab') return;

        const focusable = [
            ...dialog.querySelectorAll(
                'button:not([disabled]), [href], input:not([disabled]), select, textarea'
            ),
        ].filter((el) => el.offsetParent !== null);

        if (!focusable.length) return;

        const first = focusable[0];
        const last  = focusable[focusable.length - 1];

        if (event.shiftKey && doc.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && doc.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    // Loading ao confirmar
    deleteForm.addEventListener('submit', () => {
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.classList.add('is-loading');
            submitBtn.textContent = 'Excluindo…';
        }
    });
}

export default initAdminPartner;
