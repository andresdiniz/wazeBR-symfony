// assets/controllers/reset_password.js

document.addEventListener('DOMContentLoaded', function() {
    // ---------- Toggle de senha (Mostrar/Ocultar) ----------
    document.querySelectorAll('[data-password-toggle]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const wrapper = this.closest('.auth-password');
            const input = wrapper.querySelector('input');
            const isPassword = input.type === 'password';

            input.type = isPassword ? 'text' : 'password';
            this.textContent = isPassword ? 'Ocultar' : 'Mostrar';
            this.setAttribute('aria-pressed', isPassword ? 'true' : 'false');
        });
    });

    // ---------- Indicador de força da senha ----------
    const passwordInput = document.querySelector('#reset_password_plainPassword');
    const strengthIndicator = document.getElementById('password-strength');
    const strengthBar = document.getElementById('strength-bar');

    if (passwordInput && strengthIndicator && strengthBar) {
        passwordInput.addEventListener('input', function() {
            const value = this.value;
            let strength = 0;

            // Critérios
            if (value.length >= 8) strength++;
            if (/[a-z]/.test(value)) strength++;
            if (/[A-Z]/.test(value)) strength++;
            if (/[0-9]/.test(value)) strength++;
            if (/[^a-zA-Z0-9]/.test(value)) strength++;

            const levels = ['Muito fraca', 'Fraca', 'Média', 'Forte', 'Muito forte'];
            const colors = ['#dc2626', '#f59e0b', '#eab308', '#22c55e', '#16a34a'];
            const level = Math.min(strength, 4);

            // Atualiza texto
            strengthIndicator.textContent = 'Força: ' + levels[level];
            strengthIndicator.style.color = colors[level];

            // Atualiza barra
            const percent = (strength / 5) * 100;
            strengthBar.style.width = percent + '%';
            strengthBar.style.background = colors[level];

            // Dispara verificação de confirmação
            const confirmInput = document.querySelector('#reset_password_confirmPassword');
            if (confirmInput && confirmInput.value.length > 0) {
                checkPasswordMatch(passwordInput.value, confirmInput.value);
            }
        });
    }

    // ---------- Bloquear colar/copiar/recortar no campo de confirmação ----------
    const confirmInput = document.querySelector('#reset_password_confirmPassword');
    if (confirmInput) {
        // Os atributos onpaste/oncopy/oncut já estão no HTML, mas reforçamos com JS
        confirmInput.addEventListener('paste', function(e) {
            e.preventDefault();
            showConfirmMessage('Não é permitido colar neste campo.', '#dc2626');
        });
        confirmInput.addEventListener('copy', function(e) {
            e.preventDefault();
            showConfirmMessage('Não é permitido copiar neste campo.', '#dc2626');
        });
        confirmInput.addEventListener('cut', function(e) {
            e.preventDefault();
            showConfirmMessage('Não é permitido recortar neste campo.', '#dc2626');
        });
        confirmInput.addEventListener('drop', function(e) {
            e.preventDefault();
            showConfirmMessage('Não é permitido arrastar para este campo.', '#dc2626');
        });

        // Validação em tempo real da confirmação
        confirmInput.addEventListener('input', function() {
            const password = document.querySelector('#reset_password_plainPassword').value;
            checkPasswordMatch(password, this.value);
        });
    }

    // ---------- Função para verificar correspondência das senhas ----------
    function checkPasswordMatch(password, confirm) {
        const msg = document.getElementById('confirm-message');
        if (!msg) return;

        if (confirm.length === 0) {
            msg.textContent = 'Confirme sua nova senha';
            msg.style.color = '#64748b';
            return;
        }

        if (password === confirm) {
            msg.textContent = '✓ As senhas coincidem';
            msg.style.color = '#16a34a';
        } else {
            msg.textContent = '✗ As senhas não coincidem';
            msg.style.color = '#dc2626';
        }
    }

    // ---------- Função auxiliar para mensagens de confirmação ----------
    function showConfirmMessage(text, color) {
        const msg = document.getElementById('confirm-message');
        if (!msg) return;
        msg.textContent = text;
        msg.style.color = color;
        setTimeout(() => {
            // Restaura a mensagem baseada no valor atual
            const confirm = document.querySelector('#reset_password_confirmPassword');
            const password = document.querySelector('#reset_password_plainPassword');
            if (confirm && password) {
                checkPasswordMatch(password.value, confirm.value);
            }
        }, 1500);
    }
});