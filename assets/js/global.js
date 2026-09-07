/**
 * wazeBR - Global JavaScript
 * Loaded on every page
 */

(function() {
    'use strict';

    // ========================================
    // Initialize on DOM Ready
    // ========================================
    document.addEventListener('DOMContentLoaded', function() {
        initToastSystem();
        initModals();
        initDropdowns();
        initTooltips();
        initAlerts();
        initSmoothScroll();
        initLazyLoad();
        initFormValidation();
        initAutoHideAlerts();
        initConfirmDialogs();
    });

    // ========================================
    // Toast Notification System
    // ========================================
    function initToastSystem() {
        window.showToast = function(message, type = 'info', duration = 5000) {
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.innerHTML = `
                <div class="toast-icon">
                    <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : type === 'warning' ? 'exclamation-triangle' : 'info-circle'}"></i>
                </div>
                <div class="toast-message">${message}</div>
                <button class="toast-close" onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            `;
            
            // Add styles if not exists
            if (!document.getElementById('toast-styles')) {
                const styles = document.createElement('style');
                styles.id = 'toast-styles';
                styles.textContent = `
                    .toast {
                        position: fixed;
                        bottom: 20px;
                        right: 20px;
                        display: flex;
                        align-items: center;
                        gap: 1rem;
                        padding: 1rem 1.5rem;
                        background: var(--bg-card);
                        border: 1px solid var(--border);
                        border-radius: var(--radius-lg);
                        box-shadow: var(--shadow-xl);
                        z-index: 9999;
                        animation: slideInRight 0.3s ease;
                        max-width: 400px;
                    }
                    .toast-success { border-left: 4px solid var(--success); }
                    .toast-error { border-left: 4px solid var(--danger); }
                    .toast-warning { border-left: 4px solid var(--warning); }
                    .toast-info { border-left: 4px solid var(--info); }
                    .toast-icon { font-size: 1.25rem; }
                    .toast-success .toast-icon { color: var(--success); }
                    .toast-error .toast-icon { color: var(--danger); }
                    .toast-warning .toast-icon { color: var(--warning); }
                    .toast-info .toast-icon { color: var(--info); }
                    .toast-message { flex: 1; color: var(--text-primary); }
                    .toast-close { background: none; border: none; color: var(--text-muted); cursor: pointer; padding: 0.25rem; }
                    .toast-close:hover { color: var(--text-primary); }
                `;
                document.head.appendChild(styles);
            }
            
            document.body.appendChild(toast);
            
            // Auto remove
            setTimeout(() => {
                toast.style.animation = 'fadeOut 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            }, duration);
        };
    }

    // ========================================
    // Modal System
    // ========================================
    function initModals() {
        window.openModal = function(modalId) {
            const modal = document.getElementById(modalId);
            if (!modal) return;
            
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        };

        window.closeModal = function(modalId) {
            const modal = document.getElementById(modalId);
            if (!modal) return;
            
            modal.classList.remove('active');
            document.body.style.overflow = '';
        };

        // Close modal on backdrop click
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal')) {
                e.target.classList.remove('active');
                document.body.style.overflow = '';
            }
        });

        // Close modal on ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const activeModal = document.querySelector('.modal.active');
                if (activeModal) {
                    activeModal.classList.remove('active');
                    document.body.style.overflow = '';
                }
            }
        });
    }

    // ========================================
    // Dropdown System
    // ========================================
    function initDropdowns() {
        // Toggle dropdown
        document.addEventListener('click', function(e) {
            const dropdownToggle = e.target.closest('[data-dropdown-toggle]');
            if (dropdownToggle) {
                e.preventDefault();
                const dropdownId = dropdownToggle.getAttribute('data-dropdown-toggle');
                const dropdown = document.getElementById(dropdownId);
                
                if (dropdown) {
                    // Close all other dropdowns
                    document.querySelectorAll('.dropdown-menu.active').forEach(d => {
                        if (d.id !== dropdownId) d.classList.remove('active');
                    });
                    
                    dropdown.classList.toggle('active');
                }
            }
        });

        // Close dropdowns on outside click
        document.addEventListener('click', function(e) {
            if (!e.target.closest('[data-dropdown-toggle]') && !e.target.closest('.dropdown-menu')) {
                document.querySelectorAll('.dropdown-menu.active').forEach(d => {
                    d.classList.remove('active');
                });
            }
        });
    }

    // ========================================
    // Tooltip System
    // ========================================
    function initTooltips() {
        // Add tooltip styles
        if (!document.getElementById('tooltip-styles')) {
            const styles = document.createElement('style');
            styles.id = 'tooltip-styles';
            styles.textContent = `
                [data-tooltip] {
                    position: relative;
                    cursor: pointer;
                }
                [data-tooltip]:hover::after {
                    content: attr(data-tooltip);
                    position: absolute;
                    bottom: 100%;
                    left: 50%;
                    transform: translateX(-50%);
                    padding: 0.5rem 0.75rem;
                    background: var(--bg-dark);
                    color: var(--text-primary);
                    font-size: 0.75rem;
                    border-radius: var(--radius);
                    white-space: nowrap;
                    z-index: var(--z-tooltip);
                    margin-bottom: 0.5rem;
                    box-shadow: var(--shadow-lg);
                }
            `;
            document.head.appendChild(styles);
        }
    }

    // ========================================
    // Alert System
    // ========================================
    function initAlerts() {
        // Close alert button
        document.addEventListener('click', function(e) {
            const closeBtn = e.target.closest('[data-alert-close]');
            if (closeBtn) {
                const alert = closeBtn.closest('.alert');
                if (alert) {
                    alert.style.animation = 'fadeOut 0.3s ease';
                    setTimeout(() => alert.remove(), 300);
                }
            }
        });
    }

    // ========================================
    // Auto Hide Alerts
    // ========================================
    function initAutoHideAlerts() {
        document.querySelectorAll('.alert[data-auto-hide]').forEach(alert => {
            const duration = parseInt(alert.getAttribute('data-auto-hide')) || 5000;
            setTimeout(() => {
                alert.style.animation = 'fadeOut 0.3s ease';
                setTimeout(() => alert.remove(), 300);
            }, duration);
        });
    }

    // ========================================
    // Smooth Scroll
    // ========================================
    function initSmoothScroll() {
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                const targetId = this.getAttribute('href');
                if (targetId === '#') return;
                
                const targetElement = document.querySelector(targetId);
                if (targetElement) {
                    e.preventDefault();
                    targetElement.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
    }

    // ========================================
    // Lazy Load Images
    // ========================================
    function initLazyLoad() {
        if ('IntersectionObserver' in window) {
            const imageObserver = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        img.src = img.dataset.src;
                        img.classList.add('loaded');
                        observer.unobserve(img);
                    }
                });
            });

            document.querySelectorAll('img[data-src]').forEach(img => {
                imageObserver.observe(img);
            });
        }
    }

    // ========================================
    // Form Validation
    // ========================================
    function initFormValidation() {
        document.querySelectorAll('form[data-validate]').forEach(form => {
            form.addEventListener('submit', function(e) {
                const inputs = form.querySelectorAll('[required]');
                let isValid = true;

                inputs.forEach(input => {
                    if (!input.value.trim()) {
                        isValid = false;
                        input.classList.add('error');
                        
                        // Show error message
                        const errorDiv = input.parentElement.querySelector('.form-error');
                        if (!errorDiv) {
                            const error = document.createElement('div');
                            error.className = 'form-error';
                            error.textContent = 'Este campo é obrigat\u00f3rio';
                            input.parentElement.appendChild(error);
                        }
                    } else {
                        input.classList.remove('error');
                        const errorDiv = input.parentElement.querySelector('.form-error');
                        if (errorDiv) errorDiv.remove();
                    }
                });

                if (!isValid) {
                    e.preventDefault();
                    showToast('Por favor, preencha todos os campos obrigat\u00f3rios', 'error');
                }
            });

            // Remove error on input
            form.querySelectorAll('[required]').forEach(input => {
                input.addEventListener('input', function() {
                    this.classList.remove('error');
                    const errorDiv = this.parentElement.querySelector('.form-error');
                    if (errorDiv) errorDiv.remove();
                });
            });
        });
    }

    // ========================================
    // Confirm Dialogs
    // ========================================
    function initConfirmDialogs() {
        document.addEventListener('click', function(e) {
            const confirmBtn = e.target.closest('[data-confirm]');
            if (confirmBtn) {
                const message = confirmBtn.getAttribute('data-confirm') || 'Tem certeza?';
                
                if (!confirm(message)) {
                    e.preventDefault();
                    return false;
                }
            }
        });
    }

    // ========================================
    // Utility Functions
    // ========================================

    // Debounce
    window.debounce = function(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    };

    // Throttle
    window.throttle = function(func, limit) {
        let inThrottle;
        return function(...args) {
            if (!inThrottle) {
                func.apply(this, args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    };

    // Copy to clipboard
    window.copyToClipboard = async function(text) {
        try {
            await navigator.clipboard.writeText(text);
            showToast('Copiado para a \u00e1rea de transfer\u00eancia!', 'success');
            return true;
        } catch (err) {
            showToast('Erro ao copiar', 'error');
            return false;
        }
    };

    // Format currency
    window.formatCurrency = function(value, locale = 'pt-BR', currency = 'BRL') {
        return new Intl.NumberFormat(locale, {
            style: 'currency',
            currency: currency
        }).format(value);
    };

    // Format date
    window.formatDate = function(date, options = {}) {
        const defaultOptions = {
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        };
        return new Intl.DateTimeFormat('pt-BR', { ...defaultOptions, ...options }).format(new Date(date));
    };

    // Format number
    window.formatNumber = function(num, locale = 'pt-BR') {
        return new Intl.NumberFormat(locale).format(num);
    };

    // Get URL parameter
    window.getUrlParam = function(param) {
        const urlParams = new URLSearchParams(window.location.search);
        return urlParams.get(param);
    };

    // Set URL parameter
    window.setUrlParam = function(param, value) {
        const url = new URL(window.location);
        url.searchParams.set(param, value);
        window.history.pushState({}, '', url);
    };

    // Is element in viewport
    window.isInViewport = function(element) {
        const rect = element.getBoundingClientRect();
        return (
            rect.top >= 0 &&
            rect.left >= 0 &&
            rect.bottom <= (window.innerHeight || document.documentElement.clientHeight) &&
            rect.right <= (window.innerWidth || document.documentElement.clientWidth)
        );
    };

    // Scroll to element
    window.scrollToElement = function(element, offset = 0) {
        const elementPosition = element.getBoundingClientRect().top + window.pageYOffset;
        window.scrollTo({
            top: elementPosition - offset,
            behavior: 'smooth'
        });
    };

    // ========================================
    // Console Welcome Message
    // ========================================
    console.log('%c\ud83d\ude80 wazeBR', 'font-size: 24px; font-weight: bold; color: #2563eb;');
    console.log('%cSistema de Monitoramento de Tr\u00e2nsito', 'font-size: 14px; color: #94a3b8;');
    console.log('%cGlobal JavaScript loaded successfully!', 'font-size: 12px; color: #22c55e;');

})();
