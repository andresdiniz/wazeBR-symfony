// assets/js/pages/traffic-light-show.js

'use strict';

const COLOR_CLASS = {
    RED:    'tl-bulb--red',
    YELLOW: 'tl-bulb--yellow',
    GREEN:  'tl-bulb--green',
    OFF:    null,
};

/**
 * Atualiza a fixture (widget do semáforo) com base no state da API.
 * @param {HTMLElement} fixture
 * @param {object} state
 */
function renderFixture(fixture, state) {
    if (!fixture || !state) return;

    // Reseta
    fixture.classList.remove('is-flashing', 'is-dark');
    fixture.querySelectorAll('.tl-bulb').forEach((b) => b.classList.remove('is-on'));

    const phases = Array.isArray(state.phases) ? state.phases : [];
    const mode = String(state.mode || '').toUpperCase();

    if (mode === 'FLASH') {
        fixture.classList.add('is-flashing');
        const yellow = fixture.querySelector('.tl-bulb--yellow');
        yellow?.classList.add('is-on');
        return;
    }

    // Se não há fase acesa, considera "dark"
    const anyOn = phases.some((p) => p && p.color && p.color !== 'OFF');
    if (!anyOn) {
        fixture.classList.add('is-dark');
        return;
    }

    // Fase corrente: marca as bulbs da primeira fase "verde", ou todas ativas
    phases.forEach((p) => {
        if (!p || !p.color) return;
        const cls = COLOR_CLASS[p.color];
        if (!cls) return;
        const n = parseInt(p.number, 10);
        if (!Number.isInteger(n)) return;

        const bulb = fixture.querySelector(`.tl-bulb[data-phase="${n}"]`);
        if (bulb) {
            // Só acende a bulb que combina com a cor da fase
            if (
                (p.color === 'RED'    && bulb.classList.contains('tl-bulb--red')) ||
                (p.color === 'YELLOW' && bulb.classList.contains('tl-bulb--yellow')) ||
                (p.color === 'GREEN'  && bulb.classList.contains('tl-bulb--green'))
            ) {
                bulb.classList.add('is-on');
            }
        }
    });
}

function applyState(state) {
    const fixture = document.getElementById('tl-fixture');
    renderFixture(fixture, state);

    // KPIs
    const kpiPhase = document.getElementById('tl-kpi-phase');
    const kpiCycle = document.getElementById('tl-kpi-cycle');
    const kpiMode  = document.getElementById('tl-kpi-mode');
    const kpiFaults = document.getElementById('tl-kpi-faults');

    if (kpiPhase)  kpiPhase.textContent  = state.currentPhase ?? '—';
    if (kpiCycle)  kpiCycle.textContent  = state.cycleSeconds ? `${state.cycleSeconds}s` : '—';
    if (kpiMode)   kpiMode.textContent   = state.mode ?? '—';
    if (kpiFaults) kpiFaults.textContent = Array.isArray(state.faults) ? state.faults.length : 0;

    // JSON bruto
    const pre = document.getElementById('tl-state');
    if (pre) pre.textContent = JSON.stringify({ ok: true, state }, null, 2);
}

export function init(root = document) {
    const stateEl = document.getElementById('tl-state');
    if (stateEl) {
        try {
            const parsed = JSON.parse(stateEl.textContent);
            if (parsed && parsed.state) {
                applyState(parsed.state);
            } else if (parsed && parsed.phases) {
                applyState(parsed);
            }
        } catch (_) {
            // texto inicial não é JSON válido, ignora
        }
    }

    const btn = root.getElementById ? root.getElementById('tl-read-btn')
                                   : document.getElementById('tl-read-btn');
    if (!btn) return;

    const url = btn.dataset.url;

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

            if (data.ok && data.state) {
                applyState(data.state);
            } else if (data.state) {
                applyState(data.state);
            } else {
                const pre = document.getElementById('tl-state');
                if (pre) pre.textContent = JSON.stringify(data, null, 2);
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
