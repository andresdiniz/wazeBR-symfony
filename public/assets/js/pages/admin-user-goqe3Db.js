/**
 * pages/admin-user.js
 * ─────────────────────────────────────────────────────────────────────
 * Comportamentos da tela de usuários:
 *
 *   - Mostra/esconde o select de parceiro conforme o papel escolhido
 *     (ROLE_PARTNER_ADMIN e ROLE_OPERATOR exigem parceiro).
 *
 * Disparado pelo registry quando existe [data-admin-user].
 */

const DEFAULT_REQUIRING = ['ROLE_PARTNER_ADMIN', 'ROLE_OPERATOR'];

export function initAdminUser(doc = globalThis.document) {
    if (!doc) return;
    if (doc.__adminUserInit) return;
    doc.__adminUserInit = true;

    bindRoleToggle(doc);
    bindInlineEditors(doc);
}

function bindInlineEditors(doc) {
    doc.querySelectorAll('[data-user-edit-toggle]').forEach((button) => {
        const panel = doc.getElementById(button.dataset.userEditToggle);
        if (!panel) return;

        const sync = () => {
            button.setAttribute('aria-expanded', String(panel.open));
            button.textContent = panel.open ? 'Fechar edição' : 'Editar aqui';
        };

        button.addEventListener('click', () => {
            panel.open = !panel.open;
            sync();
            if (panel.open) {
                panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        });
        panel.addEventListener('toggle', sync);
        sync();
    });
}

function bindRoleToggle(doc) {
    doc.querySelectorAll('[data-admin-user] form').forEach((form) => {
        const roleInputs = Array.from(form.querySelectorAll('input[type="radio"]'))
            .filter((input) => input.name.endsWith('[roles]'));
        const partnerField = form.querySelector('[data-partner-field]');
        const partnerInput = form.querySelector('[data-partner-select]');

        if (!roleInputs.length || !partnerField || !partnerInput) return;

        const update = () => {
            const selectedRole = roleInputs.find((input) => input.checked)?.value;
            const requiresPartner = DEFAULT_REQUIRING.includes(selectedRole);
            const isGlobalRole = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'].includes(selectedRole);

            partnerField.hidden = isGlobalRole;
            partnerInput.required = requiresPartner;
            if (isGlobalRole) partnerInput.value = '';
        };

        roleInputs.forEach((input) => input.addEventListener('change', update));
        update();
    });
}
export default initAdminUser;
