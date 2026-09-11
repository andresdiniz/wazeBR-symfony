/**
 * wazeBR - Reset Password Page JavaScript
 * Form validation, interactions, and UX enhancements
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialize all components
    initResetForm();
    initFormValidation();
    initAnimations();
    initEmailSuggestions();
});

/**
 * Reset password form handling
 */
function initResetForm() {
    const form = document.querySelector('.reset-form');
    const submitBtn = form ? form.querySelector('.btn-reset') : null;
    
    if (!form || !submitBtn) return;
    
    form.addEventListener('submit', function(e) {
        // Validate before submit
        if (!validateForm()) {
            e.preventDefault();
            return;
        }
        
        // Show loading state
        showLoading(submitBtn);
        
        // Form will submit normally to the backend
        // Backend should handle sending email and redirecting to check-email page
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
    const emailInput = document.getElementById('email');
    
    if (!emailInput) return;
    
    // Real-time validation on blur
    emailInput.addEventListener('blur', function() {
        validateEmail(this);
    });
    
    // Clear error on input
    emailInput.addEventListener('input', function() {
        clearError(this);
    });
}

/**
 * Validate email input
 */
function validateEmail(input) {
    const value = input.value.trim();
    let isValid = true;
    let errorMessage = '';
    
    // Remove previous error
    clearError(input);
    
    // Required validation
    if (!value) {
        isValid = false;
        errorMessage = 'Digite seu email';
    } else {
        // Email validation
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(value)) {
            isValid = false;
            errorMessage = 'Digite um email v&aacute;lido';
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
    const emailInput = document.getElementById('email');
    
    if (!emailInput) return true;
    
    return validateEmail(emailInput);
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
    const submitBtn = document.querySelector('.btn-reset');
    if (submitBtn) {
        submitBtn.style.opacity = '0';
        submitBtn.style.transform = 'translateY(10px)';
        
        setTimeout(() => {
            submitBtn.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            submitBtn.style.opacity = '1';
            submitBtn.style.transform = 'translateY(0)';
        }, 200 + (formGroups.length * 100));
    }
    
    // Info box animation
    const infoBox = document.querySelector('.info-box');
    if (infoBox) {
        infoBox.style.opacity = '0';
        infoBox.style.transform = 'translateY(10px)';
        
        setTimeout(() => {
            infoBox.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            infoBox.style.opacity = '1';
            infoBox.style.transform = 'translateY(0)';
        }, 400);
    }
}

/**
 * Email suggestions and auto-complete
 */
function initEmailSuggestions() {
    const emailInput = document.getElementById('email');
    if (!emailInput) return;
    
    // Common email domains
    const commonDomains = [
        'gmail.com',
        'yahoo.com',
        'hotmail.com',
        'outlook.com',
        'live.com',
        'icloud.com',
        'protonmail.com',
        'mail.com'
    ];
    
    // Show suggestion on @ typing
    emailInput.addEventListener('input', function() {
        const value = this.value;
        
        // If user typed @ but no domain
        if (value.includes('@') && !value.includes('@.')) {
            const parts = value.split('@');
            const username = parts[0];
            const partialDomain = parts[1] || '';
            
            // Find matching domains
            const matches = commonDomains.filter(domain => 
                domain.startsWith(partialDomain)
            );
            
            if (matches.length === 1) {
                // Auto-complete if only one match
                this.value = `${username}@${matches[0]}`;
            }
        }
    });
}

/**
 * Focus first empty field
 */
function focusFirstEmptyField() {
    const emailInput = document.getElementById('email');
    
    if (emailInput && !emailInput.value) {
        emailInput.focus();
    }
}

/**
 * Handle enter key on inputs
 */
function initEnterKey() {
    const inputs = document.querySelectorAll('.form-control');
    
    inputs.forEach((input) => {
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                
                // Submit form
                const form = this.closest('form');
                if (form) {
                    form.requestSubmit();
                }
            }
        });
    });
}

/**
 * Countdown timer (optional - for token expiration)
 */
function initCountdown() {
    const countdownElement = document.querySelector('.countdown');
    if (!countdownElement) return;
    
    const duration = 3600; // 1 hour in seconds
    let remaining = duration;
    
    const timer = setInterval(() => {
        remaining--;
        
        if (remaining <= 0) {
            clearInterval(timer);
            countdownElement.textContent = 'Expirado';
            return;
        }
        
        const hours = Math.floor(remaining / 3600);
        const minutes = Math.floor((remaining % 3600) / 60);
        const seconds = remaining % 60;
        
        countdownElement.textContent = 
            `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
    }, 1000);
}

// Initialize additional features
focusFirstEmptyField();
initEnterKey();

// Console log for debugging
console.log('wazeBR Reset Password Page initialized successfully! 🔑');
