/**
 * pages/admin-partner.js
 * ─────────────────────────────────────────────────────────────────────
 * Comportamentos das telas de parceiros:
 *
 *   - Preview do caminho do feed conforme o código é digitado
 *     (avisa quando o código muda em relação ao original)
 *   - Uppercase automático em inputs [data-uppercase]
 *   - Copiar URL do feed
 *   - Exclusão via modal (confirmação + opção de apagar a pasta)
 *
 * Disparado pelo registry quando existe [data-admin-partner].
 */

const FEED_URL_TEMPLATE = (code) => `/feed/${code}/feed.json`;
const CODE_SANITIZER    = /[^a-zA-Z0-9_-]/g;

export function initAdminPartner(doc = globalThis.document) {
    if (!doc) return;
    if (doc.__adminPartnerInit) return;
    doc.__adminPartnerInit = true;

    bindFeedPreview(doc);
    bindUppercase(doc);
    bindCopyFeed(doc);
    bindDeleteFlow(doc);
}

// ── Preview do caminho do feed ────────────────────────────────────────

function bindFeedPreview(doc) {
    const codeInput = doc.querySelector('[data-code-input]');
    const preview   = doc.querySelector('[data-feed-preview]');
    const target    = doc.querySelector('[data-feed-preview-url]');
    const warning   = doc.querySelector('[data-feed-preview-warning]');

    if (!codeInput || !preview || !target) return;

    const originalCode = codeInput.dataset.originalCode
        ?? doc.querySelector('[data-original-code]')?.dataset.originalCode
        ?? null;

    const update = () => {
        const raw  = codeInput.value.trim().toLowerCase();
        const safe = raw.replace(CODE_SANITIZER, '');

        if (!safe) {
            target.textContent = '—';

            if (warning) warning.hidden = true;
            return;
        }

        target.textContent = FEED_URL_TEMPLATE(safe);

        if (warning && originalCode) {
            warning.hidden = safe === originalCode;
        }
    };

    codeInput.addEventListener('input', update);
    update();
}

// ── Uppercase ─────────────────────────────────────────────────────────

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
                /* input type não suporta */
            }
        });
    });
}

// ── Copiar URL do feed ────────────────────────────────────────────────

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
        } catch {
            legacyCopy(doc, value);
        }

        flashCopied(btn);
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

function flashCopied(btn) {
    const original = btn.textContent;
    btn.classList.add('is-copied');
    btn.textContent = 'Copiado!';

    window.setTimeout(() => {
        btn.classList.remove('is-copied');
        btn.textContent = original;
    }, 1500);
}

// ── Fluxo de exclusão ─────────────────────────────────────────────────

function bindDeleteFlow(doc) {
    const modal = doc.querySelector('[data-modal="partner-delete"]');
    if (!modal) return;

    const deleteForm   = modal.querySelector('[data-delete-form]');
    const tokenInput   = deleteForm?.querySelector('input[name="_token"]');
    const flagInput    = deleteForm?.querySelector('[data-delete-flag]');
    const feedCheckbox = modal.querySelector('input[type="checkbox"][name="deleteFeed"]');
    const nameTarget   = modal.querySelector('[data-delete-partner-name]');
    const codeTarget   = modal.querySelector('[data-delete-partner-code]');
    const feedLabel    = modal.querySelector('.admin-modal__checkbox');

    if (!deleteForm || !tokenInput) return;

    const syncFlag = () => {
        if (!flagInput) return;
        flagInput.value = feedCheckbox?.checked ? '1' : '0';
    };

    feedCheckbox?.addEventListener('change', syncFlag);

    doc.addEventListener('click', (event) => {
        const btn = event.target.closest('[data-partner-delete]');
        if (!btn) return;

        event.preventDefault();

        const name = btn.dataset.partnerName ?? '—';
        const code = btn.dataset.partnerCode ?? '—';
        const feed = btn.dataset.partnerFeed ?? '';
        const url  = btn.dataset.deleteUrl;
        const token = btn.dataset.deleteToken;

        if (!url || !token) return;

        deleteForm.action = url;
        tokenInput.value  = token;

        if (nameTarget) nameTarget.textContent = name;
        if (codeTarget) codeTarget.textContent = code;

        if (feedLabel) feedLabel.hidden = !feed;
        if (feedCheckbox) feedCheckbox.checked = Boolean(feed);

        syncFlag();

        openModal(doc, modal);
    });
}

function openModal(doc, modal) {
    if (window.WazeBR?.openModal) {
        window.WazeBR.openModal('partner-delete');
        return;
    }

    // Fallback caso modals.js ainda não tenha rodado
    modal.hidden = false;
    doc.body.style.overflow = 'hidden';

    const focusable = modal.querySelector(
        '[autofocus], button:not([disabled]), [href], input, select, textarea'
    );
    focusable?.focus?.();
}

export default initAdminPartner;
