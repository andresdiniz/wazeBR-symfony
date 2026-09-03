import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this.initPasswordToggle();
        this.initFormValidation();
    }

    initPasswordToggle() {
        const toggleButtons = document.querySelectorAll('.toggle-password');
        
        toggleButtons.forEach(button => {
            button.addEventListener('click', () => {
                const wrapper = button.closest('.password-input-wrapper');
                const input = wrapper.querySelector('input');
                const icon = button.querySelector('i');
                
                if (input.type === 'password') {
                    input.type = 'text';
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                } else {
                    input.type = 'password';
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                }
            });
        });
    }

    initFormValidation() {
        const forms = document.querySelectorAll('form[novalidate]');
        
        forms.forEach(form => {
            form.addEventListener('submit', (e) => {
                if (!form.checkValidity()) {
                    e.preventDefault();
                    e.stopPropagation();
                }
                form.classList.add('was-validated');
            });
        });
    }
}
