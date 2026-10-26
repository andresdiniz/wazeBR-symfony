// assets/js/core/clipboard.js
//
// Utilitários de área de transferência compartilhados pelas páginas do feed.
// Antes, index e show tinham cada um sua cópia do mesmo fallback.

let liveRegion = null;

/** Anuncia uma mensagem para leitores de tela (aria-label sozinho não é lido ao mudar). */
export function announce(message) {
    if (!liveRegion) {
        liveRegion = document.createElement('div');
        liveRegion.setAttribute('role', 'status');
        liveRegion.setAttribute('aria-live', 'polite');
        liveRegion.style.cssText =
            'position:absolute;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0';
        document.body.appendChild(liveRegion);
    }
    liveRegion.textContent = '';
    // pequeno atraso para o leitor perceber a troca de texto
    setTimeout(() => { liveRegion.textContent = message; }, 30);
}

/**
 * Copia texto. Usa a Clipboard API (exige HTTPS/localhost) e cai para
 * execCommand em HTTP puro. Retorna true/false em vez de lançar.
 */
export async function copyText(text) {
    if (navigator.clipboard?.writeText && window.isSecureContext) {
        try {
            await navigator.clipboard.writeText(text);
            return true;
        } catch {
            // segue para o fallback
        }
    }

    const tmp = document.createElement('textarea');
    tmp.value = text;
    tmp.setAttribute('readonly', '');
    tmp.style.cssText = 'position:fixed;left:-9999px;top:0;opacity:0';
    document.body.appendChild(tmp);
    tmp.select();

    let ok = false;
    try {
        ok = document.execCommand('copy');
    } catch {
        ok = false;
    } finally {
        document.body.removeChild(tmp);
    }
    return ok;
}

/** Marca o botão como "copiado" por um tempo, sem acumular timers em cliques repetidos. */
export function flashButton(btn, { okLabel, idleLabel, ms = 2000, ok = true }) {
    clearTimeout(btn._copyTimer);
    btn.dataset.copied = ok ? 'true' : 'error';
    btn.setAttribute('aria-label', ok ? okLabel : 'Não foi possível copiar');
    announce(ok ? okLabel : 'Não foi possível copiar');

    btn._copyTimer = setTimeout(() => {
        delete btn.dataset.copied;
        btn.setAttribute('aria-label', idleLabel);
    }, ms);
}
