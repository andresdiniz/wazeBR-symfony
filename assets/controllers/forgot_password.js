// assets/controllers/forgot_password.js
// Evita duplo envio do formulário de recuperação de senha (clique duplo, Enter repetido).

document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('[data-auth-form]');
    if (!form) return;

    form.addEventListener('submit', function () {
        const submitButton = form.querySelector('[data-submit-button]');
        if (submitButton && !submitButton.disabled) {
            submitButton.disabled = true;
            submitButton.textContent = 'Enviando...';
        }
    });
});
