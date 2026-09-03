const toggle = document.querySelector('[data-password-toggle]');

if (toggle) {
    const input = document.getElementById('password');

    toggle.addEventListener('click', () => {
        const visible = input.type === 'text';
        input.type = visible ? 'password' : 'text';
        toggle.textContent = visible ? 'Mostrar' : 'Ocultar';
        toggle.setAttribute('aria-pressed', String(!visible));
        toggle.setAttribute('aria-label', visible ? 'Mostrar senha' : 'Ocultar senha');
    });
}
