// assets/js/pages/partner-feed-show.js

import { copyText, flashButton } from '../core/clipboard.js';

const TOKEN_RE = /("(?:\\.|[^"\\])*")(\s*:)?|\b(true|false)\b|\bnull\b|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?/g;

/**
 * Colore o JSON da prévia sem innerHTML: monta nós de texto e <span>.
 * O textContent final continua idêntico ao original, então "Copiar" não é afetado.
 */
function highlightJson(pre) {
    const text = pre.textContent ?? '';
    if (!text.trim() || text.length > 200_000) return; // evita travar com feeds enormes

    const frag = document.createDocumentFragment();
    let last = 0;

    for (const match of text.matchAll(TOKEN_RE)) {
        if (match.index > last) frag.append(text.slice(last, match.index));

        const [full, str, colon, bool] = match;
        const span = document.createElement('span');

        if (str !== undefined) {
            span.className = colon ? 'pf-json__key' : 'pf-json__string';
            span.textContent = str;
            frag.append(span);
            if (colon) frag.append(colon);
        } else {
            span.className = bool !== undefined ? 'pf-json__bool'
                : full === 'null' ? 'pf-json__null' : 'pf-json__number';
            span.textContent = full;
            frag.append(span);
        }
        last = match.index + full.length;
    }

    if (last < text.length) frag.append(text.slice(last));
    pre.replaceChildren(frag);
}

/** Resumo da prévia: valida o JSON e mostra nº de incidentes e tamanho. */
function renderMeta(pre, metaEl) {
    if (!metaEl) return;
    const text = pre.textContent ?? '';
    const kb = (new Blob([text]).size / 1024).toFixed(1);

    try {
        const data = JSON.parse(text);
        const n = Array.isArray(data?.incidents) ? data.incidents.length : 0;
        metaEl.textContent = `${n} ${n === 1 ? 'incidente' : 'incidentes'} · ${kb} KB`;
        metaEl.dataset.state = 'ok';
    } catch {
        metaEl.textContent = 'JSON inválido';
        metaEl.dataset.state = 'error';
    }
}

function initCopyJson(root) {
    root.querySelectorAll('[data-copy-json]').forEach((btn) => {
        const target = document.getElementById(btn.dataset.copyJson ?? '');
        if (!target) return;

        btn.addEventListener('click', async () => {
            const ok = await copyText(target.textContent ?? '');
            flashButton(btn, {
                okLabel: 'JSON copiado!',
                idleLabel: 'Copiar JSON do feed',
                ms: 2200,
                ok,
            });
        });
    });
}

/**
 * Ponto de entrada chamado pelo registry do app.js.
 */
export function init(root = document) {
    const page = root.querySelector('[data-partner-feed-show]');
    if (!page || page.dataset.pfShowInit === '1') return;
    page.dataset.pfShowInit = '1';

    const pre = page.querySelector('#pf-feed-preview');
    if (pre) {
        renderMeta(pre, page.querySelector('[data-preview-meta]'));
        highlightJson(pre);
    }
    initCopyJson(page);
}

export default init;
