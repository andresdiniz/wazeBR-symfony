const toggle = document.querySelector('[data-password-toggle]');

if (toggle) {
    const wrapper = toggle.closest('.auth-password');
    const input = wrapper ? wrapper.querySelector('input') : document.getElementById('password');

    if (input) {
        toggle.addEventListener('click', () => {
            const visible = input.type === 'text';
            input.type = visible ? 'password' : 'text';
            toggle.textContent = visible ? 'Mostrar' : 'Ocultar';
            toggle.setAttribute('aria-pressed', String(!visible));
            toggle.setAttribute('aria-label', visible ? 'Mostrar senha' : 'Ocultar senha');
        });
    }
}

// Evita duplo envio do formulário de login (clique duplo, Enter repetido, etc.)
const authForm = document.querySelector('[data-auth-form]');
if (authForm) {
    authForm.addEventListener('submit', () => {
        const submitButton = authForm.querySelector('[data-submit-button]');
        if (submitButton && !submitButton.disabled) {
            submitButton.disabled = true;
            submitButton.textContent = 'Entrando...';
        }
    });
}
