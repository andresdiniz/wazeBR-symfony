/**
 * wazeBR – Admin › Usuários
 * Comportamento de: templates/admin/users/index.html.twig
 *                    templates/admin/users/form.html.twig
 *
 * Sem handlers inline (onclick/onsubmit) no HTML — tudo é
 * ligado aqui via data-attributes, para manter o markup limpo
 * e compatível com uma futura CSP.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        initDeleteConfirm();
        initPasswordToggle();
        initUserForm();
    });

    /**
     * Substitui o antigo onsubmit="return confirm(...)" inline.
     * Uso: <form data-confirm="mensagem...">
     */
    function initDeleteConfirm() {
        document.querySelectorAll('form[data-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                var message = form.getAttribute('data-confirm') || 'Tem certeza?';

                if (!window.confirm(message)) {
                    event.preventDefault();
                    return;
                }

                setLoading(form.querySelector('button[type="submit"]'), true);
            });
        });
    }

    /**
     * Botão de mostrar/ocultar senha.
     * Uso: <button data-password-toggle="ID_DO_INPUT">
     */
    function initPasswordToggle() {
        document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
            var input = document.getElementById(button.getAttribute('data-password-toggle'));
            if (!input) {
                return;
            }

            button.addEventListener('click', function () {
                var showing = input.type === 'password';
                input.type = showing ? 'text' : 'password';
                button.setAttribute('aria-label', showing ? 'Ocultar senha' : 'Mostrar senha');

                var icon = button.querySelector('i');
                if (icon) {
                    icon.classList.toggle('fa-eye', !showing);
                    icon.classList.toggle('fa-eye-slash', showing);
                }
            });
        });
    }

    /**
     * Validação client-side do formulário de criação/edição de usuário.
     * Não substitui a validação do backend — só evita um round-trip
     * óbvio e dá feedback imediato ao usuário.
     */
    function initUserForm() {
        var form = document.querySelector('.user-form');
        if (!form) {
            return;
        }

        var isEdit = form.dataset.mode === 'edit';

        form.querySelectorAll('.form-control').forEach(function (field) {
            field.addEventListener('input', function () {
                clearFieldError(field);
            });
        });

        form.addEventListener('submit', function (event) {
            var nameField = form.querySelector('#name');
            var emailField = form.querySelector('#email');
            var passwordField = form.querySelector('#password');

            var nameOk = validateRequired(nameField, 'Informe o nome.');
            var emailOk = validateEmail(emailField);
            var passwordOk = validatePassword(passwordField, isEdit);

            if (!nameOk || !emailOk || !passwordOk) {
                event.preventDefault();

                var firstInvalid = form.querySelector('.is-invalid');
                if (firstInvalid) {
                    firstInvalid.focus();
                }

                return;
            }

            setLoading(form.querySelector('button[type="submit"]'), true);
        });
    }

    function validateRequired(field, message) {
        if (!field) {
            return true;
        }

        if (!field.value.trim()) {
            setFieldError(field, message);
            return false;
        }

        clearFieldError(field);
        return true;
    }

    function validateEmail(field) {
        if (!field) {
            return true;
        }

        var value = field.value.trim();
        var pattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

        if (!value || !pattern.test(value)) {
            setFieldError(field, 'Informe um email válido.');
            return false;
        }

        clearFieldError(field);
        return true;
    }

    function validatePassword(field, isEdit) {
        if (!field) {
            return true;
        }

        var value = field.value;

        if (!value) {
            if (isEdit) {
                clearFieldError(field);
                return true;
            }

            setFieldError(field, 'A senha é obrigatória.');
            return false;
        }

        if (value.length < 6) {
            setFieldError(field, 'A senha deve ter no mínimo 6 caracteres.');
            return false;
        }

        clearFieldError(field);
        return true;
    }

    function setFieldError(field, message) {
        field.classList.add('is-invalid');

        var group = field.closest('.form-group') || field.closest('.form-password-wrapper');
        if (!group) {
            return;
        }

        var container = field.closest('.form-group') || group.parentElement;
        var errorEl = container.querySelector('.form-error');

        if (!errorEl) {
            errorEl = document.createElement('span');
            errorEl.className = 'form-error';
            container.appendChild(errorEl);
        }

        errorEl.textContent = message;
    }

    function clearFieldError(field) {
        field.classList.remove('is-invalid');

        var container = field.closest('.form-group');
        if (!container) {
            return;
        }

        var errorEl = container.querySelector('.form-error');
        if (errorEl) {
            errorEl.remove();
        }
    }

    function setLoading(button, isLoading) {
        if (!button) {
            return;
        }

        button.disabled = isLoading;
        button.classList.toggle('btn-loading', isLoading);
    }
})();
