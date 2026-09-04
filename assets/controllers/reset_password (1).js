// assets/controllers/reset_password.js

document.addEventListener('DOMContentLoaded', function () {
    // ---------- Toggle de senha (Mostrar/Ocultar) ----------
    document.querySelectorAll('[data-password-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const wrapper = this.closest('.auth-password');
            const input = wrapper.querySelector('input');
            const isPassword = input.type === 'password';

            input.type = isPassword ? 'text' : 'password';
            this.textContent = isPassword ? 'Ocultar' : 'Mostrar';
            this.setAttribute('aria-pressed', isPassword ? 'true' : 'false');
            this.setAttribute('aria-label', isPassword ? 'Ocultar senha' : 'Mostrar senha');
        });
    });

    // Seleciona os campos pelos atributos data-*, não por id: o id gerado pelo
    // Symfony depende do nome do FormType (ex.: reset_password_form_plainPassword)
    // e mudar o form quebraria seletores fixos por id.
    const passwordInput = document.querySelector('[data-strength]');
    const confirmInput = document.querySelector('[data-confirm]');
    const strengthIndicator = document.getElementById('password-strength');
    const strengthBar = document.getElementById('strength-bar');
    const confirmMessage = document.getElementById('confirm-message');

    // ---------- Indicador de força da senha ----------
    if (passwordInput && strengthIndicator && strengthBar) {
        passwordInput.addEventListener('input', function () {
            const value = this.value;
            let strength = 0;

            if (value.length >= 8) strength++;
            if (/[a-z]/.test(value)) strength++;
            if (/[A-Z]/.test(value)) strength++;
            if (/[0-9]/.test(value)) strength++;
            if (/[^a-zA-Z0-9]/.test(value)) strength++;

            const levels = ['Muito fraca', 'Fraca', 'Média', 'Forte', 'Muito forte'];
            const colors = ['#dc2626', '#f59e0b', '#eab308', '#22c55e', '#16a34a'];
            const level = value.length === 0 ? -1 : Math.min(strength, 4);

            if (level === -1) {
                strengthIndicator.textContent = '';
                strengthBar.style.width = '0%';
                strengthBar.style.background = '#94a3b8';
            } else {
                strengthIndicator.textContent = 'Força: ' + levels[level];
                strengthIndicator.style.color = colors[level];
                strengthBar.style.width = ((strength / 5) * 100) + '%';
                strengthBar.style.background = colors[level];
            }

            if (confirmInput && confirmInput.value.length > 0) {
                checkPasswordMatch(passwordInput.value, confirmInput.value);
            }
        });
    }

    // ---------- Validação em tempo real da confirmação ----------
    if (confirmInput) {
        confirmInput.addEventListener('input', function () {
            const password = passwordInput ? passwordInput.value : '';
            checkPasswordMatch(password, this.value);
        });
    }

    function checkPasswordMatch(password, confirm) {
        if (!confirmMessage) return;

        if (confirm.length === 0) {
            confirmMessage.textContent = 'Confirme sua nova senha';
            confirmMessage.style.color = '#64748b';
            return;
        }

        if (password === confirm) {
            confirmMessage.textContent = '✓ As senhas coincidem';
            confirmMessage.style.color = '#16a34a';
        } else {
            confirmMessage.textContent = '✗ As senhas não coincidem';
            confirmMessage.style.color = '#dc2626';
        }
    }

    // ---------- Evita duplo envio do formulário ----------
    const form = document.querySelector('[data-auth-form]');
    if (form) {
        form.addEventListener('submit', function () {
            const submitButton = form.querySelector('[data-submit-button]');
            if (submitButton && !submitButton.disabled) {
                submitButton.disabled = true;
                submitButton.textContent = 'Redefinindo...';
            }
        });
    }
});
