// assets/js/pages/traffic-light-form.js

'use strict';

export function init(root = document) {
    const textarea = root.querySelector('textarea[id$="_optionsJson"]') ||
                    root.querySelector('textarea[name$="[optionsJson]"]');
    if (!textarea) return;

    const preview = document.createElement('div');
    preview.className = 'tl-form__options-preview';
    preview.textContent = '{}';
    textarea.parentNode.appendChild(preview);

    const update = () => {
        const raw = textarea.value.trim() || '{}';
        try {
            const parsed = JSON.parse(raw);
            preview.textContent = JSON.stringify(parsed, null, 2);
            preview.classList.remove('is-error');
            preview.classList.add('is-ok');
        } catch (e) {
            preview.textContent = `JSON inválido: ${e.message}`;
            preview.classList.remove('is-ok');
            preview.classList.add('is-error');
        }
    };

    textarea.addEventListener('input', update);
    update();

    // Dica dinâmica por protocolo
    const protocolSelect = root.querySelector('select[id$="_protocol"]');
    const hint = document.createElement('div');
    hint.className = 'tl-form__protocol-info';
    if (protocolSelect) {
        const hints = {
            NTCIP:      'Ex.: {"community":"public","timeout":2,"vendor":"tesc"}',
            MODBUS_TCP: 'Ex.: {"unitId":1,"timeout":2,"vendor":"generic"}',
            HTTP_REST:  'Ex.: {"statePath":"/state","commandPath":"/command","headers":{"X-Api-Key":"..."}}',
            FAKE:       'Sem opções obrigatórias — usado em desenvolvimento.',
        };
        protocolSelect.parentNode.appendChild(hint);
        const updateHint = () => {
            hint.textContent = hints[protocolSelect.value] ?? '';
        };
        protocolSelect.addEventListener('change', updateHint);
        updateHint();
    }
}

export default init;
