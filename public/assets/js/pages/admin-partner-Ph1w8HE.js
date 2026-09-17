/**
 * pages/admin-partner.js
 * ─────────────────────────────────────────────────────────────────────
 * Comportamentos da tela de parceiros:
 *
 *   - Preview do caminho do feed conforme o código é digitado
 *   - Uppercase automático em inputs [data-uppercase] (ex.: UF)
 *   - Copiar URL do feed para o clipboard
 *
 * Disparado pelo registry quando existe [data-admin-partner].
 */

export function initAdminPartner(doc = globalThis.document) {
    if (!doc) return;
    if (doc.__adminPartnerInit) return;
    doc.__adminPartnerInit = true;

    bindFeedPreview(doc);
    bindUppercase(doc);
    bindCopyFeed(doc);
}

// ── Preview do caminho do feed ────────────────────────────────────────

function bindFeedPreview(doc) {
    const codeInput = doc.querySelector('[data-code-input]');
    const preview   = doc.querySelector('[data-feed-preview]');
    const target    = doc.querySelector('[data-feed-preview-url]');

    if (!codeInput || !preview || !target) return;

    const update = () => {
        const raw  = codeInput.value.trim().toLowerCase();
        const safe = raw.replace(/[^a-z0-9_-]/g, '');

        if (!safe) {
            preview.hidden = true;
            target.textContent = '';
            return;
        }

        target.textContent = `/feed/${safe}/feed.json`;
        preview.hidden = false;
    };

    codeInput.addEventListener('input', update);
    update();
}

// ── Uppercase em campos específicos (UF, etc.) ────────────────────────

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
                // Alguns tipos de input não suportam setSelectionRange.
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

export default initAdminPartner;
