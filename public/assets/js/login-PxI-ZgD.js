/**
 * wazeBR - Login Page JavaScript
 * Form validation, interactions, and UX enhancements
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialize all components
    initLoginForm();
    initPasswordToggle();
    initFormValidation();
    initAutoFill();
    initAnimations();
});

/**
 * Toggle password visibility
 */
function togglePassword() {
    const passwordInput = document.getElementById('password');
    const toggleIcon = document.getElementById('toggleIcon');
    
    if (!passwordInput || !toggleIcon) return;
    
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        toggleIcon.classList.remove('fa-eye');
        toggleIcon.classList.add('fa-eye-slash');
    } else {
        passwordInput.type = 'password';
        toggleIcon.classList.remove('fa-eye-slash');
        toggleIcon.classList.add('fa-eye');
    }
}

/**
 * Login form handling
 */
function initLoginForm() {
    const form = document.querySelector('.login-form');
    const submitBtn = form.querySelector('.btn-login');
    
    if (!form || !submitBtn) return;
    
    form.addEventListener('submit', function(e) {
        // Validate before submit
        if (!validateForm()) {
            e.preventDefault();
            return;
        }
        
        // Show loading state
        showLoading(submitBtn);
        
        // Form will submit normally
        // Loading state will be cleared on page reload
    });
}

/**
 * Show loading state on button
 */
function showLoading(button) {
    button.classList.add('btn-loading');
    button.disabled = true;
    
    // Optional: Add timeout to prevent multiple submissions
    setTimeout(() => {
        button.classList.remove('btn-loading');
        button.disabled = false;
    }, 5000); // 5 seconds timeout
}

/**
 * Form validation
 */
function initFormValidation() {
    const inputs = document.querySelectorAll('.form-control');
    
    inputs.forEach(input => {
        // Real-time validation on blur
        input.addEventListener('blur', function() {
            validateInput(this);
        });
        
        // Clear error on input
        input.addEventListener('input', function() {
            clearError(this);
        });
    });
}

/**
 * Validate individual input
 */
function validateInput(input) {
    const value = input.value.trim();
    const type = input.type;
    let isValid = true;
    let errorMessage = '';
    
    // Remove previous error
    clearError(input);
    
    // Required validation
    if (input.required && !value) {
        isValid = false;
        errorMessage = 'Este campo é obrigat&oacute;rio';
    }
    
    // Email validation
    if (type === 'email' && value) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(value)) {
            isValid = false;
            errorMessage = 'Digite um email v&aacute;lido';
        }
    }
    
    // Password validation
    if (type === 'password' && value) {
        if (value.length < 6) {
            isValid = false;
            errorMessage = 'A senha deve ter pelo menos 6 caracteres';
        }
    }
    
    // Show error if invalid
    if (!isValid) {
        showError(input, errorMessage);
    }
    
    return isValid;
}

/**
 * Validate entire form
 */
function validateForm() {
    const emailInput = document.getElementById('username');
    const passwordInput = document.getElementById('password');
    
    let isValid = true;
    
    // Validate email
    if (emailInput) {
        if (!validateInput(emailInput)) {
            isValid = false;
        }
    }
    
    // Validate password
    if (passwordInput) {
        if (!validateInput(passwordInput)) {
            isValid = false;
        }
    }
    
    return isValid;
}

/**
 * Show error message
 */
function showError(input, message) {
    input.classList.add('error');
    
    // Remove existing error message
    const existingError = input.parentElement.querySelector('.error-message');
    if (existingError) {
        existingError.remove();
    }
    
    // Create error message
    const errorDiv = document.createElement('div');
    errorDiv.className = 'error-message';
    errorDiv.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${message}`;
    
    // Insert after input
    input.parentElement.appendChild(errorDiv);
}

/**
 * Clear error message
 */
function clearError(input) {
    input.classList.remove('error');
    
    const errorDiv = input.parentElement.querySelector('.error-message');
    if (errorDiv) {
        errorDiv.remove();
    }
}

/**
 * Password toggle functionality
 */
function initPasswordToggle() {
    const toggleBtn = document.querySelector('.toggle-password');
    if (!toggleBtn) return;
    
    toggleBtn.addEventListener('click', togglePassword);
}

/**
 * Auto-fill detection
 */
function initAutoFill() {
    const inputs = document.querySelectorAll('.form-control');
    
    inputs.forEach(input => {
        // Handle browser auto-fill
        input.addEventListener('animationstart', function() {
            if (this.value) {
                clearError(this);
            }
        });
    });
}

/**
 * Animations and effects
 */
function initAnimations() {
    // Fade in animation for alerts
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach((alert, index) => {
        alert.style.opacity = '0';
        alert.style.transform = 'translateY(-10px)';
        
        setTimeout(() => {
            alert.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            alert.style.opacity = '1';
            alert.style.transform = 'translateY(0)';
        }, index * 100);
    });
    
    // Stagger animation for form elements
    const formGroups = document.querySelectorAll('.form-group');
    formGroups.forEach((group, index) => {
        group.style.opacity = '0';
        group.style.transform = 'translateY(10px)';
        
        setTimeout(() => {
            group.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            group.style.opacity = '1';
            group.style.transform = 'translateY(0)';
        }, 200 + (index * 100));
    });
    
    // Button animation
    const submitBtn = document.querySelector('.btn-login');
    if (submitBtn) {
        submitBtn.style.opacity = '0';
        submitBtn.style.transform = 'translateY(10px)';
        
        setTimeout(() => {
            submitBtn.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            submitBtn.style.opacity = '1';
            submitBtn.style.transform = 'translateY(0)';
        }, 200 + (formGroups.length * 100));
    }
}

/**
 * Remember me checkbox state
 */
function saveRememberMeState() {
    const rememberMe = document.getElementById('remember_me');
    if (!rememberMe) return;
    
    rememberMe.addEventListener('change', function() {
        localStorage.setItem('rememberMe', this.checked);
    });
    
    // Restore state
    const saved = localStorage.getItem('rememberMe');
    if (saved === 'true') {
        rememberMe.checked = true;
    }
}

/**
 * Focus first empty field
 */
function focusFirstEmptyField() {
    const emailInput = document.getElementById('username');
    const passwordInput = document.getElementById('password');
    
    if (emailInput && !emailInput.value) {
        emailInput.focus();
    } else if (passwordInput && !passwordInput.value) {
        passwordInput.focus();
    }
}

/**
 * Handle enter key on inputs
 */
function initEnterKey() {
    const inputs = document.querySelectorAll('.form-control');
    
    inputs.forEach((input, index) => {
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                
                // Move to next input or submit
                const nextInput = inputs[index + 1];
                if (nextInput) {
                    nextInput.focus();
                } else {
                    // Submit form
                    const form = this.closest('form');
                    if (form) {
                        form.requestSubmit();
                    }
                }
            }
        });
    });
}

// Initialize additional features
saveRememberMeState();
focusFirstEmptyField();
initEnterKey();

// Console log for debugging
console.log('wazeBR Login Page initialized successfully! 🔐');

// Export functions for global access
window.togglePassword = togglePassword;
