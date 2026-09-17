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
}

function bindRoleToggle(doc) {
    const form         = doc.querySelector('[data-admin-user] form');
    const roleSelect   = doc.querySelector('[data-role-select]');
    const partnerField = doc.querySelector('[data-partner-field]');
    const partnerInput = doc.querySelector('[data-partner-select]');

    if (!roleSelect || !partnerField || !partnerInput) return;

    // Permite override via atributo no <form>
    const configured = form?.dataset.rolesRequiringPartner;
    const requiring  = configured
        ? configured.split(',').map((r) => r.trim()).filter(Boolean)
        : DEFAULT_REQUIRING;

    const update = () => {
        const role     = roleSelect.value;
        const required = requiring.includes(role);

        partnerField.hidden = !required;
        partnerInput.required = required;

        if (!required) {
            partnerInput.value = '';
        }
    };

    roleSelect.addEventListener('change', update);
    update();
}

export default initAdminUser;
