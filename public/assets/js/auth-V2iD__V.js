/**
 * Auth — WazeBR
 * Cobre: login, reset-password/request, reset, check_email
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        if (!document.querySelector('.auth-page')) return;

        initPasswordToggle();
        initFormSubmit();
        initFieldValidation();
        initPasswordStrength();
        initPasswordRequirements();
        focusFirst();
    }

    /* ── Toggle visibilidade de senha ──────────────────────────── */
    function initPasswordToggle() {
        document.querySelectorAll('.auth-toggle-pw').forEach(btn => {
            btn.addEventListener('click', () => {
                const target = document.getElementById(btn.dataset.target);
                if (!target) return;

                const isPassword = target.type === 'password';
                target.type = isPassword ? 'text' : 'password';

                const icon = btn.querySelector('i');
                if (icon) {
                    icon.className = isPassword ? 'bi bi-eye-slash' : 'bi bi-eye';
                }

                btn.setAttribute('aria-label', isPassword ? 'Ocultar senha' : 'Mostrar senha');
            });
        });
    }

    /* ── Submit com loading ─────────────────────────────────────── */
    function initFormSubmit() {
        document.querySelectorAll('.auth-form').forEach(form => {
            const btn = form.querySelector('.auth-btn-primary');
            if (!btn) return;

            form.addEventListener('submit', (e) => {
                // Validação antes de submeter
                const valid = validateForm(form);
                if (!valid) {
                    e.preventDefault();
                    return;
                }

                // Estado de loading
                btn.classList.add('is-loading');
                btn.disabled = true;

                // Safety timeout — libera depois de 8s caso algo dê errado
                setTimeout(() => {
                    btn.classList.remove('is-loading');
                    btn.disabled = false;
                }, 8000);
            });
        });
    }

    /* ── Validação de campo individual ─────────────────────────── */
    function initFieldValidation() {
        document.querySelectorAll('.auth-input').forEach(input => {
            input.addEventListener('blur', () => validateInput(input));
            input.addEventListener('input', () => clearError(input));
        });
    }

    function validateInput(input) {
        const value = input.value.trim();
        clearError(input);

        if (input.required && !value) {
            showError(input, 'Este campo é obrigatório');
            return false;
        }

        if (input.type === 'email' && value) {
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
                showError(input, 'Digite um email válido');
                return false;
            }
        }

        if (input.type === 'password' && value && input.minLength > 0) {
            if (value.length < input.minLength) {
                showError(input, `Mínimo de ${input.minLength} caracteres`);
                return false;
            }
        }

        // Confirmação de senha
        if (input.id === 'confirm_password') {
            const pw = document.getElementById('new_password') || document.getElementById('password');
            if (pw && value && value !== pw.value) {
                showError(input, 'As senhas não coincidem');
                return false;
            }
        }

        return true;
    }

    function validateForm(form) {
        let valid = true;
        form.querySelectorAll('.auth-input[required]').forEach(input => {
            if (!validateInput(input)) valid = false;
        });
        return valid;
    }

    function showError(input, message) {
        input.classList.add('is-error');

        const field = input.closest('.auth-field');
        if (!field) return;

        // Remove erro anterior
        field.querySelector('.auth-field-error')?.remove();

        const el = document.createElement('span');
        el.className = 'auth-field-error';
        el.innerHTML = `<i class="bi bi-exclamation-circle"></i> ${message}`;

        // Insere após o input ou o wrap
        const wrap = field.querySelector('.auth-input-wrap') ?? input;
        wrap.insertAdjacentElement('afterend', el);
    }

    function clearError(input) {
        input.classList.remove('is-error');
        input.closest('.auth-field')?.querySelector('.auth-field-error')?.remove();
    }

    /* ── Barra de força da senha ────────────────────────────────── */
    function initPasswordStrength() {
        const pwInput = document.getElementById('new_password');
        const fill    = document.getElementById('strengthFill');
        const label   = document.getElementById('strengthLabel');
        if (!pwInput || !fill || !label) return;

        pwInput.addEventListener('input', () => {
            const score = getStrengthScore(pwInput.value);

            const levels = [
                { pct: 0,   color: 'transparent',  text: 'Digite a senha' },
                { pct: 25,  color: '#ef4444',       text: 'Muito fraca' },
                { pct: 50,  color: '#f59e0b',       text: 'Fraca' },
                { pct: 75,  color: '#0ea5e9',       text: 'Boa' },
                { pct: 100, color: '#10b981',       text: 'Forte' },
            ];

            const lvl = levels[score];
            fill.style.width     = `${lvl.pct}%`;
            fill.style.background = lvl.color;
            label.textContent    = lvl.text;
            label.style.color    = score >= 3 ? lvl.color : '';
        });
    }

    function getStrengthScore(pw) {
        if (!pw) return 0;
        let score = 1;
        if (pw.length >= 8)           score++;
        if (/[0-9]/.test(pw))         score++;
        if (/[^a-zA-Z0-9]/.test(pw)) score++;
        return Math.min(score, 4);
    }

    /* ── Checagem visual de requisitos ──────────────────────────── */
    function initPasswordRequirements() {
        const pwInput = document.getElementById('new_password');
        if (!pwInput) return;

        const reqs = {
            'req-length': pw => pw.length >= 6,
            'req-letter': pw => /[a-zA-Z]/.test(pw),
            'req-number': pw => /[0-9]/.test(pw),
        };

        pwInput.addEventListener('input', () => {
            const pw = pwInput.value;
            Object.entries(reqs).forEach(([id, check]) => {
                const el = document.getElementById(id);
                if (!el) return;

                const met = check(pw);
                el.classList.toggle('is-met', met);

                const icon = el.querySelector('i');
                if (icon) {
                    icon.className = met
                        ? 'bi bi-check-circle-fill'
                        : 'bi bi-circle';
                }
            });
        });
    }

    /* ── Foca primeiro campo vazio ──────────────────────────────── */
    function focusFirst() {
        const first = document.querySelector('.auth-input:not([value]), .auth-input[value=""]');
        if (first && !first.value) first.focus();
    }

})();
