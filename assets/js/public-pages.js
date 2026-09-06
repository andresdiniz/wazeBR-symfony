/**
 * wazeBR - Public Pages JavaScript
 * Home, Login, Register, Reset Password
 * Professional, Modern & Interactive
 */

// ==========================================================================
// Initialization
// ==========================================================================

export function initPublicPages() {
  console.log('[wazeBR] Public pages initialized');
  
  initAuthForms();
  initPasswordToggles();
  initFormValidation();
  initAnimations();
  initAlerts();
  initHomeAnimations();
}

// ==========================================================================
// Form Handling
// ==========================================================================

function initAuthForms() {
  const forms = document.querySelectorAll('.auth-form');
  
  forms.forEach(form => {
    form.addEventListener('submit', async (e) => {
      const submitBtn = form.querySelector('.auth-submit');
      if (!submitBtn) return;
      
      // Only prevent if has data-validate
      if (form.dataset.validate === 'true') {
        e.preventDefault();
        
        const isValid = await validateForm(form);
        
        if (isValid) {
          setLoadingState(submitBtn, true);
          
          // Simulate API (remove in production)
          await sleep(1500);
          
          setLoadingState(submitBtn, false);
          showToast('Operação realizada com sucesso!', 'success');
        }
      }
    });
  });
}

// ==========================================================================
// Password Toggle
// ==========================================================================

function initPasswordToggles() {
  const toggles = document.querySelectorAll('.auth-password-toggle');
  
  toggles.forEach(toggle => {
    toggle.addEventListener('click', () => {
      const wrapper = toggle.closest('.auth-input-wrapper');
      const input = wrapper?.querySelector('.auth-input');
      if (!input) return;
      
      const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
      input.setAttribute('type', type);
      
      const icon = toggle.querySelector('i') || toggle;
      icon.className = type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
      
      input.focus();
    });
  });
}

// ==========================================================================
// Form Validation
// ==========================================================================

function initFormValidation() {
  const inputs = document.querySelectorAll('.auth-input[data-required]');
  
  inputs.forEach(input => {
    input.addEventListener('blur', () => validateInput(input));
    
    input.addEventListener('input', () => {
      if (input.classList.contains('error')) {
        input.classList.remove('error');
        clearError(input);
      }
      
      if (input.value.trim() !== '') {
        input.classList.add('success');
      } else {
        input.classList.remove('success');
      }
    });
  });
}

function validateInput(input) {
  const value = input.value.trim();
  const type = input.type;
  const name = input.name;
  
  input.classList.remove('error', 'success');
  clearError(input);
  
  // Required
  if (input.dataset.required === 'true' && value === '') {
    showError(input, 'Este campo é obrigatório');
    return false;
  }
  
  // Email
  if (type === 'email' && value !== '') {
    const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!regex.test(value)) {
      showError(input, 'Digite um email válido');
      return false;
    }
  }
  
  // Password
  if (type === 'password' && value !== '') {
    if (value.length < 6) {
      showError(input, 'Mínimo de 6 caracteres');
      return false;
    }
  }
  
  // Confirm Password
  if (name === 'confirm_password' && value !== '') {
    const password = document.querySelector('input[name="password"]');
    if (password && value !== password.value) {
      showError(input, 'As senhas não coincidem');
      return false;
    }
  }
  
  if (value !== '') {
    input.classList.add('success');
  }
  
  return true;
}

async function validateForm(form) {
  const inputs = form.querySelectorAll('.auth-input');
  let isValid = true;
  
  inputs.forEach(input => {
    if (!validateInput(input)) {
      isValid = false;
    }
  });
  
  return isValid;
}

// ==========================================================================
// Error Handling
// ==========================================================================

function showError(input, message) {
  input.classList.add('error');
  
  const errorDiv = document.createElement('div');
  errorDiv.className = 'auth-input-error';
  errorDiv.style.cssText = `
    color: #ef4444;
    font-size: 0.8rem;
    margin-top: 0.5rem;
    animation: slideDown 0.3s ease;
  `;
  errorDiv.innerHTML = `<i class="bi bi-exclamation-circle"></i> ${message}`;
  
  const wrapper = input.closest('.auth-input-wrapper') || input.parentElement;
  wrapper?.appendChild(errorDiv);
}

function clearError(input) {
  const errorDiv = input.closest('.auth-input-wrapper')?.querySelector('.auth-input-error') ||
                   input.parentElement?.querySelector('.auth-input-error');
  errorDiv?.remove();
}

// ==========================================================================
// Loading States
// ==========================================================================

function setLoadingState(button, loading) {
  if (loading) {
    button.classList.add('loading');
    button.disabled = true;
  } else {
    button.classList.remove('loading');
    button.disabled = false;
  }
}

// ==========================================================================
// Toast Notifications
// ==========================================================================

function showToast(message, type = 'info') {
  const toast = document.createElement('div');
  toast.className = `auth-toast auth-toast-${type}`;
  toast.style.cssText = `
    position: fixed;
    top: 1.5rem;
    right: 1.5rem;
    padding: 1rem 1.5rem;
    background: ${type === 'success' ? '#f0fdf4' : (type === 'error' ? '#fef2f2' : '#eff6ff')};
    color: ${type === 'success' ? '#166534' : (type === 'error' ? '#991b1b' : '#1e40af')};
    border-left: 4px solid ${type === 'success' ? '#22c55e' : (type === 'error' ? '#ef4444' : '#2563eb')};
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
    z-index: 9999;
    animation: slideIn 0.3s ease;
    font-size: 0.95rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.75rem;
  `;
  toast.innerHTML = `
    <i class="bi bi-${type === 'success' ? 'check-circle' : (type === 'error' ? 'exclamation-circle' : 'info-circle')}"></i>
    ${message}
  `;
  
  document.body.appendChild(toast);
  
  setTimeout(() => {
    toast.style.animation = 'slideOut 0.3s ease';
    setTimeout(() => toast.remove(), 300);
  }, 3000);
}

// ==========================================================================
// Animations
// ==========================================================================

function initAnimations() {
  // Fade in elements
  const elements = document.querySelectorAll('.auth-card, .auth-logo, .auth-title');
  
  elements.forEach((el, index) => {
    el.style.opacity = '0';
    el.style.transform = 'translateY(20px)';
    
    setTimeout(() => {
      el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
      el.style.opacity = '1';
      el.style.transform = 'translateY(0)';
    }, index * 100);
  });
}

function initHomeAnimations() {
  // Animate feature cards on scroll
  const cards = document.querySelectorAll('.home-feature-card');
  
  const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry, index) => {
      if (entry.isIntersecting) {
        setTimeout(() => {
          entry.target.style.opacity = '1';
          entry.target.style.transform = 'translateY(0)';
        }, index * 100);
      }
    });
  }, { threshold: 0.1 });
  
  cards.forEach(card => {
    card.style.opacity = '0';
    card.style.transform = 'translateY(30px)';
    card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
    observer.observe(card);
  });
}

// ==========================================================================
// Alerts Auto-Dismiss
// ==========================================================================

export function initAlerts() {
  const alerts = document.querySelectorAll('.alert-dismissible, .auth-error, .auth-success');
  
  alerts.forEach(alert => {
    const timeout = parseInt(alert.dataset.timeout) || 5000;
    
    setTimeout(() => {
      alert.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
      alert.style.opacity = '0';
      alert.style.transform = 'translateX(100%)';
      setTimeout(() => alert.remove(), 300);
    }, timeout);
  });
}

// ==========================================================================
// Utilities
// ==========================================================================

function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

// ==========================================================================
// Export
// ==========================================================================

export default initPublicPages;
