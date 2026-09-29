// assets/js/pages/traffic-light-control.js

'use strict';

const COLOR_CLASS = {
    RED:    'tl-bulb--red',
    YELLOW: 'tl-bulb--yellow',
    GREEN:  'tl-bulb--green',
};

function toast(message, kind = 'info', duration = 3500) {
    let el = document.getElementById('tl-toast');
    if (!el) {
        el = document.createElement('div');
        el.id = 'tl-toast';
        el.className = 'tl-toast';
        document.body.appendChild(el);
    }
    el.textContent = message;
    el.className = `tl-toast tl-toast--${kind} is-visible`;
    clearTimeout(el._t);
    el._t = setTimeout(() => el.classList.remove('is-visible'), duration);
}

function updateFixtureFromCommand(fixture, type, payload) {
    if (!fixture) return;
    fixture.classList.remove('is-flashing', 'is-dark');
    fixture.querySelectorAll('.tl-bulb').forEach((b) => b.classList.remove('is-on'));

    let newColor = 'OFF';

    switch (type) {
        case 'FLASH_YELLOW':
            fixture.classList.add('is-flashing');
            fixture.querySelector('.tl-bulb--yellow')?.classList.add('is-on');
            newColor = 'YELLOW';
            break;
        case 'ALL_RED':
            fixture.querySelector('.tl-bulb--red')?.classList.add('is-on');
            newColor = 'RED';
            break;
        case 'ALL_DARK':
            fixture.classList.add('is-dark');
            newColor = 'OFF';
            break;
        case 'SET_MODE':
            if (String(payload?.mode).toUpperCase() === 'FLASH') {
                fixture.classList.add('is-flashing');
                fixture.querySelector('.tl-bulb--yellow')?.classList.add('is-on');
                newColor = 'YELLOW';
            } else {
                fixture.querySelector('.tl-bulb--green')?.classList.add('is-on');
                newColor = 'GREEN';
            }
            break;
        case 'FORCE_GREEN':
            fixture.querySelector('.tl-bulb--green')?.classList.add('is-on');
            newColor = 'GREEN';
            break;
        case 'FORCE_RED':
            fixture.querySelector('.tl-bulb--red')?.classList.add('is-on');
            newColor = 'RED';
            break;
        case 'SET_PHASE': {
            const n = parseInt(payload?.phase, 10);
            if (Number.isInteger(n)) {
                const bulb = fixture.querySelector(`.tl-bulb[data-phase="${n}"]`);
                bulb?.classList.add('is-on');
                newColor = bulb?.classList.contains('tl-bulb--red')    ? 'RED'
                        : bulb?.classList.contains('tl-bulb--green')  ? 'GREEN'
                        : bulb?.classList.contains('tl-bulb--yellow') ? 'YELLOW'
                        : 'OFF';
            }
            break;
        }
        default:
            break;
    }

    // Atualiza o badge de cor
    const badge = document.getElementById('tl-current-color-badge');
    if (badge) {
        const label = {
            RED:    '🔴 Vermelho',
            YELLOW: '🟡 Amarelo',
            GREEN:  '🟢 Verde',
            OFF:    '⚫ Apagado',
        }[newColor] ?? '⚪ Desconhecido';
        badge.innerHTML = `Cor: <strong>${label}</strong>`;
    }
}

export function init(root = document) {
    const sendUrl = document.body.dataset.tlControlUrl
        || document.querySelector('[data-tl-control-url]')?.dataset.tlControlUrl;
    if (!sendUrl) return;

    const fixture = document.getElementById('tl-fixture');
    const feedback = document.getElementById('tl-feedback');
    const reasonInput = document.getElementById('global-reason');

    function showFeedback(msg, kind = 'ok') {
        if (!feedback) {
            toast(msg, kind);
            return;
        }
        feedback.textContent = msg;
        feedback.className = `tl-ctl__feedback tl-ctl__feedback--${kind} is-visible`;
        clearTimeout(feedback._t);
        feedback._t = setTimeout(() => {
            feedback.className = 'tl-ctl__feedback';
        }, 4000);
    }

    async function send(type, payload, opts = {}) {
        const reason = reasonInput?.value?.trim() || null;
        const headers = {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        };
        if (opts.csrf) headers['X-CSRF-Token'] = opts.csrf;

        try {
            const res = await fetch(sendUrl, {
                method: 'POST',
                headers,
                body: JSON.stringify({ type, payload, reason }),
            });
            const data = await res.json();

            if (data.ok) {
                showFeedback(`✓ ${humanCommand(type)} enviado`, 'ok');
                toast(`✓ ${humanCommand(type)} executado`, 'ok', 2500);
                updateFixtureFromCommand(fixture, type, payload);
                if (reasonInput) reasonInput.value = '';
            } else {
                showFeedback(`✗ ${data.error || 'Falha ao enviar'}`, 'error');
                toast(`✗ ${data.error || 'Falha'}`, 'error');
            }
        } catch (err) {
            showFeedback(`✗ Erro de rede: ${err.message}`, 'error');
            toast(`✗ Erro: ${err.message}`, 'error');
        }
    }

    function humanCommand(type) {
        return {
            FLASH_YELLOW: 'Piscante amarelo',
            ALL_RED:      'Tudo vermelho',
            ALL_DARK:     'Apagar tudo',
            SET_MODE:     'Modo',
            SET_PHASE:    'Ativação de fase',
            FORCE_GREEN:  'Verde forçado',
            FORCE_RED:    'Vermelho forçado',
            SET_CYCLE:    'Ciclo',
            SET_OFFSET:   'Offset',
            SET_SPLIT:    'Split',
            CLEAR_FAULT:  'Limpeza de falhas',
        }[type] || type;
    }

    // ── Presets ───────────────────────────────────────────────
    document.querySelectorAll('.tl-preset[data-command]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const type = btn.dataset.command;
            let payload = {};
            if (btn.dataset.payload) {
                try { payload = JSON.parse(btn.dataset.payload); } catch (_) { /* noop */ }
            }

            const requiresConfirm = ['ALL_DARK', 'ALL_RED', 'FLASH_YELLOW'].includes(type);
            if (requiresConfirm) {
                const label = btn.querySelector('.tl-preset__label strong')?.textContent
                    || btn.textContent.trim();
                if (!window.confirm(`Confirma enviar "${label}"?`)) return;
            }

            btn.disabled = true;
            const orig = btn.dataset.originalLabel;
            send(type, payload).finally(() => { btn.disabled = false; if (orig) btn.dataset.originalLabel = orig; });
        });
    });

    // ── Form de fase ──────────────────────────────────────────
    const formPhase = document.getElementById('tl-form-phase');
    formPhase?.addEventListener('submit', (e) => {
        e.preventDefault();
        const action = formPhase.querySelector('[name="action"]').value;
        const phase = parseInt(formPhase.querySelector('[name="phase"]').value, 10);
        const submit = formPhase.querySelector('button[type="submit"]');
        if (submit) submit.disabled = true;
        send(action, { phase }).finally(() => { if (submit) submit.disabled = false; });
    });

    // ── Form de tempos ────────────────────────────────────────
    const timeType = document.getElementById('time-type');
    const phaseWrap = document.getElementById('time-phase-wrap');

    timeType?.addEventListener('change', () => {
        if (phaseWrap) phaseWrap.hidden = timeType.value !== 'SET_SPLIT';
    });

    const formTimes = document.getElementById('tl-form-times');
    formTimes?.addEventListener('submit', (e) => {
        e.preventDefault();
        const type = timeType.value;
        const seconds = parseInt(document.getElementById('time-seconds').value, 10);
        const submit = formTimes.querySelector('button[type="submit"]');
        if (submit) submit.disabled = true;

        const payload = type === 'SET_SPLIT'
            ? { phase: parseInt(document.getElementById('time-phase').value, 10), green: seconds }
            : { seconds };

        send(type, payload).finally(() => { if (submit) submit.disabled = false; });
    });
}

export default init;
