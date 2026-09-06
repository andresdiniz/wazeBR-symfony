/**
 * wazeBR - Public Pages JavaScript
 * (Login, Register, Reset, Home)
 */

export function initPublicPages() {
  console.log('[wazeBR] Public pages initialized');
  
  // Initialize all auth forms
  initAuthForms();
  
  // Initialize password toggles
  initPasswordToggles();
  
  // Initialize form validation
  initFormValidation();
  
  // Initialize animations
  initAnimations();
}

/**
 * Initialize auth form submissions
 */
function initAuthForms() {
  const forms = document.querySelectorAll('.auth-form');
  
  forms.forEach(form => {
    form.addEventListener('submit', async (e) => {
      const submitBtn = form.querySelector('.auth-form-submit');
      if (!submitBtn) return;
      
      // Prevent default if form has data-validate attribute
      if (form.dataset.validate === 'true') {
        e.preventDefault();
        
        // Validate form
        const isValid = await validateForm(form);
        
        if (isValid) {
          // Show loading state
          setLoadingState(submitBtn, true);
          
          // Simulate API call (remove this and use real submission)
          await sleep(1500);
          
          // Show success
          setLoadingState(submitBtn, false);
          showToast('Operaçª£o realizada com sucesso!', 'success');
          
          // Redirect or submit form
          // form.submit();
        }
      }
    });
  });
}

/**
 * Initialize password visibility toggles
 */
function initPasswordToggles() {
  const toggles = document.querySelectorAll('.auth-password-toggle');
  
  toggles.forEach(toggle => {
    toggle.addEventListener('click', () => {
      const input = toggle.closest('.auth-input-wrapper')?.querySelector('.auth-form-input');
      if (!input) return;
      
      const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
      input.setAttribute('type', type);
      
      // Toggle icon
      const icon = toggle.querySelector('i');
      if (icon) {
        icon.className = type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
      }
      
      // Focus input
      input.focus();
    });
  });
}

/**
 * Initialize form validation
 */
function initFormValidation() {
  const inputs = document.querySelectorAll('.auth-form-input[data-required]');
  
  inputs.forEach(input => {
    // Real-time validation
    input.addEventListener('blur', () => {
      validateInput(input);
    });
    
    input.addEventListener('input', () => {
      // Remove error state on input
      if (input.classList.contains('error')) {
        input.classList.remove('error');
        clearError(input);
      }
      
      // Add success state if valid
      if (input.value.trim() !== '') {
        input.classList.add('success');
      } else {
        input.classList.remove('success');
      }
    });
  });
}

/**
 * Validate single input
 */
function validateInput(input) {
  const value = input.value.trim();
  const type = input.type;
  const name = input.name;
  
  // Clear previous states
  input.classList.remove('error', 'success');
  clearError(input);
  
  // Required validation
  if (input.dataset.required === 'true' && value === '') {
    showError(input, 'Este campo é obrigatá¡£io');
    return false;
  }
  
  // Email validation
  if (type === 'email' && value !== '') {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(value)) {
      showError(input, 'Digite um email vá­lido');
      return false;
    }
  }
  
  // Password validation
  if (type === 'password' && value !== '') {
    if (value.length < 6) {
      showError(input, 'A senha deve ter pelo menos 6 caracteres');
      return false;
    }
  }
  
  // Confirm password validation
  if (name === 'confirm_password' && value !== '') {
    const password = document.querySelector('input[name="password"]');
    if (password && value !== password.value) {
      showError(input, 'As senhas nã££o coincidem');
      return false;
    }
  }
  
  // Success
  if (value !== '') {
    input.classList.add('success');
  }
  
  return true;
}

/**
 * Validate entire form
 */
async function validateForm(form) {
  const inputs = form.querySelectorAll('.auth-form-input');
  let isValid = true;
  
  inputs.forEach(input => {
    if (!validateInput(input)) {
      isValid = false;
    }
  });
  
  return isValid;
}

/**
 * Show error message
 */
function showError(input, message) {
  input.classList.add('error');
  
  const errorDiv = document.createElement('div');
  errorDiv.className = 'auth-form-error';
  errorDiv.style.cssText = `
    color: var(--color-danger);
    font-size: var(--font-size-xs);
    margin-top: var(--spacing-1);
    animation: slideDown 0.2s ease;
  `;
  errorDiv.textContent = message;
  
  const wrapper = input.closest('.auth-input-wrapper') || input.parentElement;
  wrapper.appendChild(errorDiv);
}

/**
 * Clear error message
 */
function clearError(input) {
  const errorDiv = input.closest('.auth-input-wrapper')?.querySelector('.auth-form-error') ||
                   input.parentElement?.querySelector('.auth-form-error');
  if (errorDiv) {
    errorDiv.remove();
  }
}

/**
 * Set loading state on button
 */
function setLoadingState(button, loading) {
  if (loading) {
    button.classList.add('loading');
    button.disabled = true;
  } else {
    button.classList.remove('loading');
    button.disabled = false;
  }
}

/**
 * Show toast notification
 */
function showToast(message, type = 'info') {
  const toast = document.createElement('div');
  toast.className = `auth-toast auth-toast-${type}`;
  toast.style.cssText = `
    position: fixed;
    top: var(--spacing-4);
    right: var(--spacing-4);
    padding: var(--spacing-3) var(--spacing-4);
    background: var(--color-${type === 'success' ? 'success-bg' : (type === 'error' ? 'danger-bg' : 'info-bg')});
    color: var(--color-${type === 'success' ? 'success-dark' : (type === 'error' ? 'danger-dark' : 'info-dark')});
    border-left: 4px solid var(--color-${type});
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-lg);
    z-index: 9999;
    animation: slideIn 0.3s ease;
    font-size: var(--font-size-sm);
    font-weight: var(--font-weight-medium);
  `;
  toast.textContent = message;
  
  document.body.appendChild(toast);
  
  setTimeout(() => {
    toast.style.animation = 'slideOut 0.3s ease';
    setTimeout(() => toast.remove(), 300);
  }, 3000);
}

/**
 * Initialize animations
 */
function initAnimations() {
  // Fade in elements on load
  const elements = document.querySelectorAll('.auth-card, .auth-logo, .auth-title');
  
  elements.forEach((el, index) => {
    el.style.opacity = '0';
    el.style.transform = 'translateY(20px)';
    
    setTimeout(() => {
      el.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
      el.style.opacity = '1';
      el.style.transform = 'translateY(0)';
    }, index * 100);
  });
  
  // Add animation styles
  if (!document.getElementById('auth-animations')) {
    const style = document.createElement('style');
    style.id = 'auth-animations';
    style.textContent = `
      @keyframes slideIn {
        from {
          opacity: 0;
          transform: translateX(100%);
        }
        to {
          opacity: 1;
          transform: translateX(0);
        }
      }
      
      @keyframes slideOut {
        from {
          opacity: 1;
          transform: translateX(0);
        }
        to {
          opacity: 0;
          transform: translateX(100%);
        }
      }
      
      @keyframes slideDown {
        from {
          opacity: 0;
          transform: translateY(-10px);
        }
        to {
          opacity: 1;
          transform: translateY(0);
        }
      }
    `;
    document.head.appendChild(style);
  }
}

/**
 * Sleep helper
 */
function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

/**
 * Auto-dismiss alerts
 */
export function initAlerts() {
  const alerts = document.querySelectorAll('.alert-dismissible');
  
  alerts.forEach(alert => {
    const timeout = parseInt(alert.dataset.timeout) || 5000;
    
    setTimeout(() => {
      alert.style.transition = 'opacity 0.3s ease';
      alert.style.opacity = '0';
      setTimeout(() => alert.remove(), 300);
    }, timeout);
  });
}

export default initPublicPages;
