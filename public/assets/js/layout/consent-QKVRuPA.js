/**
 * layout/consent.js
 * ─────────────────────────────────────────────────────────────────────
 * Gerenciador de consentimento (LGPD/GDPR).
 *
 * Responsabilidades:
 *
 *   1. Ler consent do localStorage (com TTL)
 *   2. Expor `window.WazeBR.consent.granted()` / `.denied()`
 *   3. Emitir eventos DOM quando o usuário decide:
 *        wazebr:consent:granted
 *        wazebr:consent:denied
 *   4. Controlar visibilidade do banner
 *
 * Uso no Twig (já pronto no partial consent-banner.html.twig):
 *
 *   <div class="consent-banner" data-consent-banner hidden>
 *       ...
 *       <button data-consent-grant>Aceitar</button>
 *       <button data-consent-deny>Recusar</button>
 *   </div>
 *
 * Este módulo é DELIBERADAMENTE separado do analytics.js, porque precisa
 * rodar ANTES dele e não depende de provider nenhum.
 */

const STORAGE_KEY = 'wazebr:consent';
const TTL_DAYS = 180; // 6 meses

// ─────────────────────────────────────────────────────────────────────
// Utils
// ─────────────────────────────────────────────────────────────────────

function readConsent() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return null;

        const parsed = JSON.parse(raw);
        if (!parsed || typeof parsed !== 'object') return null;
        if (parsed.expiresAt && Date.now() > parsed.expiresAt) {
            localStorage.removeItem(STORAGE_KEY);
            return null;
        }
        if (parsed.value === 'granted' || parsed.value === 'denied') {
            return parsed.value;
        }
        return null;
    } catch {
        return null;
    }
}

function writeConsent(value) {
    try {
        const expiresAt = Date.now() + TTL_DAYS * 86400 * 1000;
        localStorage.setItem(STORAGE_KEY, JSON.stringify({ value, expiresAt }));
    } catch {
        // localStorage indisponível (modo anônimo, quota cheia)
    }
}

// ─────────────────────────────────────────────────────────────────────
// API pública
// ─────────────────────────────────────────────────────────────────────

export function isGranted() {
    return readConsent() === 'granted';
}

export function isDenied() {
    return readConsent() === 'denied';
}

export function grant() {
    writeConsent('granted');
    hideBanner();
    document.dispatchEvent(new CustomEvent('wazebr:consent:granted'));
}

export function deny() {
    writeConsent('denied');
    hideBanner();
    document.dispatchEvent(new CustomEvent('wazebr:consent:denied'));
}

export function revoke() {
    try { localStorage.removeItem(STORAGE_KEY); } catch { /* noop */ }
    document.dispatchEvent(new CustomEvent('wazebr:consent:revoked'));
}

// ─────────────────────────────────────────────────────────────────────
// Banner
// ─────────────────────────────────────────────────────────────────────

function hideBanner() {
    const banner = document.querySelector('[data-consent-banner]');
    if (banner) banner.hidden = true;
}

function showBanner() {
    const banner = document.querySelector('[data-consent-banner]');
    if (banner) banner.hidden = false;
}

// ─────────────────────────────────────────────────────────────────────
// Bootstrap
// ─────────────────────────────────────────────────────────────────────

export function initConsent(root = document) {
    if (root.__consentInit) return;
    root.__consentInit = true;

    // Liga botões do banner
    root.querySelectorAll('[data-consent-grant]').forEach((btn) => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            grant();
        });
    });

    root.querySelectorAll('[data-consent-deny]').forEach((btn) => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            deny();
        });
    });

    // Decide se mostra o banner
    const current = readConsent();
    if (current === null) {
        showBanner();
    } else {
        hideBanner();
        const evt = current === 'granted'
            ? 'wazebr:consent:granted'
            : 'wazebr:consent:denied';
        document.dispatchEvent(new CustomEvent(evt));
    }

    // API pública
    window.WazeBR = window.WazeBR || {};
    window.WazeBR.consent = {
        granted: isGranted,
        denied: isDenied,
        grant,
        deny,
        revoke,
    };
}

export default initConsent;
