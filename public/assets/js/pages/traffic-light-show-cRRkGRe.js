// assets/js/pages/traffic-light-show.js

'use strict';

const COLOR_CLASS = {
    RED:    'tl-bulb--red',
    YELLOW: 'tl-bulb--yellow',
    GREEN:  'tl-bulb--green',
};

const COLOR_LABEL = {
    RED:    '🔴 Vermelho',
    YELLOW: '🟡 Amarelo',
    GREEN:  '🟢 Verde',
    OFF:    '⚫ Apagado',
};

/**
 * Re-renderiza a fixture com base no state retornado pela API.
 * ✅ Busca o bulb pela CLASSE DE COR (não por data-phase, que é igual nos 3).
 */
function renderFixture(fixture, state) {
    if (!fixture || !state) return;

    // Reseta tudo
    fixture.classList.remove('is-flashing', 'is-dark');
    fixture.querySelectorAll('.tl-bulb').forEach((b) => b.classList.remove('is-on'));

    const phases = Array.isArray(state.phases) ? state.phases : [];
    const mode   = String(state.mode || '').toUpperCase();

    // Modo piscante: acende amarelo e deixa a animação
    if (mode === 'FLASH') {
        fixture.classList.add('is-flashing');
        fixture.querySelector('.tl-bulb--yellow')?.classList.add('is-on');
        return;
    }

    // Nenhuma fase ativa → modo escuro
    const anyOn = phases.some((p) => p && p.color && p.color !== 'OFF');
    if (!anyOn) {
        fixture.classList.add('is-dark');
        return;
    }

    // Acende a bulb correspondente à cor da fase
    phases.forEach((p) => {
        if (!p || !p.color || p.color === 'OFF') return;

        const cls = COLOR_CLASS[p.color];
        if (!cls) return;

        // ✅ Correto: busca pelo seletor da cor
        const bulb = fixture.querySelector(`.${cls}`);
        if (bulb) {
            bulb.classList.add('is-on');
        }
    });
}

function applyState(state, opts = {}) {
    const fixture = document.getElementById('tl-fixture');
    renderFixture(fixture, state);

    const kpiPhase  = document.getElementById('tl-kpi-phase');
    const kpiColor  = document.getElementById('tl-kpi-color');
    const kpiMode   = document.getElementById('tl-kpi-mode');
    const kpiFaults = document.getElementById('tl-kpi-faults');

    const phases = Array.isArray(state.phases) ? state.phases : [];
    const first  = phases[0] ?? null;

    if (kpiPhase)  kpiPhase.textContent  = first?.number ?? state.currentPhase ?? '—';
    if (kpiColor && first) kpiColor.textContent = (first.color || 'off').toLowerCase();
    if (kpiMode)   kpiMode.textContent   = state.mode ?? '—';
    if (kpiFaults) kpiFaults.textContent = Array.isArray(state.faults) ? state.faults.length : 0;

    const badge = document.getElementById('tl-current-color-badge');
    if (badge && first) {
        badge.innerHTML = `Cor atual: <strong>${COLOR_LABEL[first.color] ?? '⚪ Desconhecido'}</strong>`;
    }

    const pre = document.getElementById('tl-state');
    if (pre) pre.textContent = JSON.stringify(state, null, 2);

    const label = document.getElementById('tl-last-read-label');
    if (label) {
        const now = new Date();
        label.textContent = `${now.toLocaleDateString('pt-BR')} ${now.toLocaleTimeString('pt-BR')}`;
    }

    // Flash sutil quando a cor muda
    if (!opts.silent && fixture) {
        const newColor = first?.color ?? 'OFF';
        const oldColor = fixture.dataset.currentColor ?? null;
        if (oldColor !== null && oldColor !== newColor) {
            fixture.classList.remove('tl-fixture--changed');
            void fixture.offsetWidth; // força reflow
            fixture.classList.add('tl-fixture--changed');
        }
        fixture.dataset.currentColor = newColor;
        fixture.dataset.currentMode  = state.mode ?? 'UNKNOWN';
    }
}

/**
 * Loop de polling "Live".
 */
function createLiveLoop({ url, intervalMs, onState, onError, onTick }) {
    let active      = false;
    let timerId     = null;
    let inFlight    = false;
    let currentMs   = intervalMs;
    let errorStreak = 0;

    async function tick() {
        if (!active) return;
        if (inFlight) { schedule(); return; }

        inFlight = true;
        const t0 = performance.now();

        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!res.ok) throw new Error(`HTTP ${res.status}`);

            const data = await res.json();
            const state = data.state ?? data;
            if (state) onState?.(state);

            errorStreak = 0;
            currentMs = intervalMs;
            onTick?.({ ok: true, durationMs: Math.round(performance.now() - t0) });
        } catch (err) {
            errorStreak++;
            const factor = Math.min(10, Math.pow(2, errorStreak));
            currentMs = intervalMs * factor;
            onError?.(err, { errorStreak, nextMs: currentMs });
        } finally {
            inFlight = false;
            schedule();
        }
    }

    function schedule() {
        if (!active) return;
        clearTimeout(timerId);
        timerId = setTimeout(tick, currentMs);
    }

    return {
        start() { if (active) return; active = true; currentMs = intervalMs; errorStreak = 0; schedule(); },
        stop()  { active = false; clearTimeout(timerId); timerId = null; },
        isActive() { return active; },
    };
}

export function init(root = document) {
    const page = root.querySelector('.tl-show') || document.querySelector('.tl-show');
    if (!page) return;

    const liveUrl  = page.dataset.tlLiveUrl;
    const interval = parseInt(page.dataset.tlLiveInterval || '1000', 10);

    const toggle    = document.getElementById('tl-live-toggle');
    const toggleTxt = toggle?.querySelector('.tl-live-text');
    const readBtn   = document.getElementById('tl-read-btn');

    // ── Loop "Live" ─────────────────────────────────────────────────
    let loop = null;

    if (liveUrl && toggle) {
        loop = createLiveLoop({
            url: liveUrl,
            intervalMs: interval,
            onState: (state) => applyState(state),
            onError: (err, meta) => {
                if (toggleTxt) toggleTxt.textContent = `Live (retry em ${Math.round(meta.nextMs / 1000)}s)`;
                console.warn('[tl-show] live falhou:', err.message);
            },
            onTick: () => {
                if (toggleTxt && loop?.isActive()) toggleTxt.textContent = 'Live';
            },
        });

        toggle.addEventListener('click', () => {
            if (loop.isActive()) {
                loop.stop();
                toggle.setAttribute('aria-pressed', 'false');
                toggle.classList.remove('is-active');
                if (toggleTxt) toggleTxt.textContent = 'Live';
            } else {
                loop.start();
                toggle.setAttribute('aria-pressed', 'true');
                toggle.classList.add('is-active');
                if (toggleTxt) toggleTxt.textContent = 'Live';
            }
        });

        document.addEventListener('visibilitychange', () => {
            if (document.hidden && loop.isActive()) {
                loop.stop();
                toggle.classList.remove('is-active');
                toggle.setAttribute('aria-pressed', 'false');
                if (toggleTxt) toggleTxt.textContent = 'Live (pausado)';
            }
        });
    }

    // ── "Ler agora" manual ──────────────────────────────────────────
    if (readBtn) {
        const url = readBtn.dataset.url;
        readBtn.addEventListener('click', async () => {
            readBtn.disabled = true;
            const prev = readBtn.textContent;
            readBtn.textContent = 'Lendo…';

            try {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await res.json();
                const state = data.state ?? data;
                if (state) applyState(state);
            } catch (err) {
                const pre = document.getElementById('tl-state');
                if (pre) pre.textContent = `Erro: ${err.message}`;
            } finally {
                readBtn.disabled = false;
                readBtn.textContent = prev;
            }
        });
    }
}

export default init;
