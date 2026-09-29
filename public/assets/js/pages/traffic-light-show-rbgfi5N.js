// assets/js/pages/traffic-light-show.js

'use strict';

const COLOR_CLASS = {
    RED:    'tl-bulb--red',
    YELLOW: 'tl-bulb--yellow',
    GREEN:  'tl-bulb--green',
};

/**
 * Re-renderiza a fixture com base no state retornado pela API.
 * Só é chamado após um "Ler agora" ou uma resposta fresca.
 * @param {HTMLElement} fixture
 * @param {object} state
 */
function renderFixture(fixture, state) {
    if (!fixture || !state) return;

    fixture.classList.remove('is-flashing', 'is-dark');
    fixture.querySelectorAll('.tl-bulb').forEach((b) => b.classList.remove('is-on'));

    const phases = Array.isArray(state.phases) ? state.phases : [];
    const mode   = String(state.mode || '').toUpperCase();

    if (mode === 'FLASH') {
        fixture.classList.add('is-flashing');
        fixture.querySelector('.tl-bulb--yellow')?.classList.add('is-on');
        return;
    }

    const anyOn = phases.some((p) => p && p.color && p.color !== 'OFF');
    if (!anyOn) {
        fixture.classList.add('is-dark');
        return;
    }

    phases.forEach((p) => {
        if (!p || !p.color) return;
        const cls = COLOR_CLASS[p.color];
        if (!cls) return;

        const n = parseInt(p.number, 10);
        if (!Number.isInteger(n)) return;

        const bulb = fixture.querySelector(`.tl-bulb[data-phase="${n}"]`);
        if (!bulb) return;

        if (
            (p.color === 'RED'    && bulb.classList.contains('tl-bulb--red')) ||
            (p.color === 'YELLOW' && bulb.classList.contains('tl-bulb--yellow')) ||
            (p.color === 'GREEN'  && bulb.classList.contains('tl-bulb--green'))
        ) {
            bulb.classList.add('is-on');
        }
    });
}

function colorLabelFor(color) {
    return {
        RED:    '🔴 Vermelho',
        YELLOW: '🟡 Amarelo',
        GREEN:  '🟢 Verde',
        OFF:    '⚫ Apagado',
    }[color] ?? '⚪ Desconhecido';
}

function applyState(state) {
    const fixture = document.getElementById('tl-fixture');
    renderFixture(fixture, state);

    // Atualiza os KPIs
    const kpiPhase = document.getElementById('tl-kpi-phase');
    const kpiColor = document.getElementById('tl-kpi-color');
    const kpiMode  = document.getElementById('tl-kpi-mode');
    const kpiFaults = document.getElementById('tl-kpi-faults');

    const phases = Array.isArray(state.phases) ? state.phases : [];
    const first  = phases[0] ?? null;

    if (kpiPhase)  kpiPhase.textContent  = first?.number ?? state.currentPhase ?? '—';
    if (kpiColor && first) kpiColor.textContent = (first.color || 'off').toLowerCase();
    if (kpiMode)   kpiMode.textContent   = state.mode ?? '—';
    if (kpiFaults) kpiFaults.textContent = Array.isArray(state.faults) ? state.faults.length : 0;

    // Badge de cor ao lado do nome
    const badge = document.getElementById('tl-current-color-badge');
    if (badge && first) {
        badge.innerHTML = `Cor atual: <strong>${colorLabelFor(first.color)}</strong>`;
    }

    // JSON bruto
    const pre = document.getElementById('tl-state');
    if (pre) pre.textContent = JSON.stringify(state, null, 2);
}

export function init(root = document) {
    // ⚠️ NÃO renderiza no init — o template já veio com a cor correta.
    // Só atualiza quando o usuário clica em "Ler agora".

    const btn = document.getElementById('tl-read-btn');
    if (!btn) return;

    const url = btn.dataset.url;
    if (!url) return;

    btn.addEventListener('click', async () => {
        btn.disabled = true;
        const prevText = btn.textContent;
        btn.textContent = 'Lendo…';

        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await res.json();

            const state = data.state ?? data;
            if (state) {
                applyState(state);
            }
        } catch (err) {
            const pre = document.getElementById('tl-state');
            if (pre) pre.textContent = `Erro: ${err.message}`;
        } finally {
            btn.disabled = false;
            btn.textContent = prevText;
        }
    });
}

export default init;
