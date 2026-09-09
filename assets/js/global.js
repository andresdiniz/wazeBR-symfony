/**
 * wazeBR - Premium Global JavaScript
 * Production-ready utilities and components
 */

(function() {
    'use strict';

    // ========================================
    // Configuration
    // ========================================
    const CONFIG = {
        toastDuration: 5000,
        debounceDelay: 300,
        throttleDelay: 100,
        animationDuration: 300,
        storagePrefix: 'wazeBR_',
    };

    // ========================================
    // Initialize on DOM Ready
    // ========================================
    document.addEventListener('DOMContentLoaded', function() {
        initToastSystem();
        initModals();
        initDropdowns();
        initTooltips();
        initPopovers();
        initAlerts();
        initSmoothScroll();
        initLazyLoad();
        initFormValidation();
        initAutoHideAlerts();
        initConfirmDialogs();
        initTabs();
        initAccordions();
        initCounters();
        initParallax();
        initRippleEffect();
        initScrollAnimations();
        initKeyboardShortcuts();
        initOnlineStatus();
        initThemeSwitcher();
    });

    // ========================================
    // Toast Notification System (Premium)
    // ========================================
    function initToastSystem() {
        window.showToast = function(options = {}) {
            const message = typeof options === 'string' ? options : options.message;
            const type = options.type || 'info';
            const duration = options.duration || CONFIG.toastDuration;
            const position = options.position || 'bottom-right';
            const closable = options.closable !== false;
            const icon = options.icon || null;
            const title = options.title || null;
            const action = options.action || null;

            const toast = document.createElement('div');
            toast.className = `toast toast-${type} toast-${position}`;
            toast.setAttribute('role', 'alert');
            
            let iconHtml = '';
            if (icon) {
                iconHtml = `<i class="${icon}"></i>`;
            } else {
                const defaultIcons = {
                    success: 'fa-check-circle',
                    error: 'fa-exclamation-circle',
                    warning: 'fa-exclamation-triangle',
                    info: 'fa-info-circle',
                };
                iconHtml = `<i class="fas fa-${defaultIcons[type] || defaultIcons.info}"></i>`;
            }

            toast.innerHTML = `
                <div class="toast-icon">${iconHtml}</div>
                <div class="toast-content">
                    ${title ? `<div class="toast-title">${title}</div>` : ''}
                    <div class="toast-message">${message}</div>
                </div>
                ${action ? `<button class="toast-action">${action.label}</button>` : ''}
                ${closable ? `<button class="toast-close" aria-label="Fechar"><i class="fas fa-times"></i></button>` : ''}
            `;

            // Add styles if not exists
            if (!document.getElementById('toast-styles')) {
                const styles = document.createElement('style');
                styles.id = 'toast-styles';
                styles.textContent = `
                    .toast {
                        position: fixed;
                        display: flex;
                        align-items: center;
                        gap: 1rem;
                        padding: 1rem 1.5rem;
                        background: var(--bg-card);
                        border: 1px solid var(--border);
                        border-radius: var(--radius-xl);
                        box-shadow: var(--shadow-2xl);
                        z-index: var(--z-toast);
                        max-width: 420px;
                        animation: slideInRight 0.3s ease;
                    }
                    .toast-icon { font-size: 1.5rem; flex-shrink: 0; }
                    .toast-success .toast-icon { color: var(--success); }
                    .toast-error .toast-icon { color: var(--danger); }
                    .toast-warning .toast-icon { color: var(--warning); }
                    .toast-info .toast-icon { color: var(--info); }
                    .toast-content { flex: 1; }
                    .toast-title { font-weight: var(--font-semibold); margin-bottom: 0.25rem; }
                    .toast-message { color: var(--text-secondary); font-size: var(--text-sm); }
                    .toast-close { background: none; border: none; color: var(--text-muted); cursor: pointer; padding: 0.25rem; transition: color var(--transition-fast); }
                    .toast-close:hover { color: var(--text-primary); }
                    .toast-action { background: var(--primary); color: white; border: none; padding: 0.5rem 1rem; border-radius: var(--radius); font-size: var(--text-sm); font-weight: var(--font-medium); cursor: pointer; transition: all var(--transition); }
                    .toast-action:hover { background: var(--primary-dark); }
                    .toast-bottom-right { bottom: 20px; right: 20px; }
                    .toast-bottom-left { bottom: 20px; left: 20px; }
                    .toast-top-right { top: 20px; right: 20px; }
                    .toast-top-left { top: 20px; left: 20px; }
                    .toast-top-center { top: 20px; left: 50%; transform: translateX(-50%); }
                    .toast-bottom-center { bottom: 20px; left: 50%; transform: translateX(-50%); }
                `;
                document.head.appendChild(styles);
            }

            document.body.appendChild(toast);

            // Close button
            const closeBtn = toast.querySelector('.toast-close');
            if (closeBtn) {
                closeBtn.addEventListener('click', () => removeToast(toast));
            }

            // Action button
            const actionBtn = toast.querySelector('.toast-action');
            if (actionBtn && action) {
                actionBtn.addEventListener('click', () => {
                    action.onClick();
                    removeToast(toast);
                });
            }

            // Auto remove
            if (duration > 0) {
                setTimeout(() => removeToast(toast), duration);
            }

            return toast;
        };

        function removeToast(toast) {
            toast.style.animation = 'fadeOut 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }

        // Shortcut functions
        window.showSuccess = (message, options = {}) => showToast({ ...options, message, type: 'success' });
        window.showError = (message, options = {}) => showToast({ ...options, message, type: 'error' });
        window.showWarning = (message, options = {}) => showToast({ ...options, message, type: 'warning' });
        window.showInfo = (message, options = {}) => showToast({ ...options, message, type: 'info' });
    }

    // ========================================
    // Modal System (Premium)
    // ========================================
    function initModals() {
        window.openModal = function(modalId, options = {}) {
            const modal = document.getElementById(modalId);
            if (!modal) return;

            modal.classList.add('active');
            document.body.style.overflow = 'hidden';

            // Trap focus
            const focusableElements = modal.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
            if (focusableElements.length) {
                focusableElements[0].focus();
            }

            // Close on ESC
            const escHandler = (e) => {
                if (e.key === 'Escape' && options.closeOnEsc !== false) {
                    closeModal(modalId);
                    document.removeEventListener('keydown', escHandler);
                }
            };
            document.addEventListener('keydown', escHandler);
        };

        window.closeModal = function(modalId) {
            const modal = document.getElementById(modalId);
            if (!modal) return;

            modal.classList.remove('active');
            document.body.style.overflow = '';

            // Restore focus
            const previouslyFocused = document.querySelector('[data-modal-trigger]');
            if (previouslyFocused) {
                previouslyFocused.focus();
            }
        };

        // Close modal on backdrop click
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal') && e.target.classList.contains('active')) {
                e.target.classList.remove('active');
                document.body.style.overflow = '';
            }
        });
    }

    // ========================================
    // Dropdown System (Premium)
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
                    const isOpen = dropdown.classList.contains('active');

                    // Close all other dropdowns
                    document.querySelectorAll('.dropdown-menu.active').forEach(d => {
                        if (d.id !== dropdownId) d.classList.remove('active');
                    });

                    if (!isOpen) {
                        dropdown.classList.add('active');
                    }
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

        // Close dropdown on ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.dropdown-menu.active').forEach(d => {
                    d.classList.remove('active');
                });
            }
        });
    }

    // ========================================
    // Tooltip System (Premium)
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
                    font-size: var(--text-xs);
                    border-radius: var(--radius);
                    white-space: nowrap;
                    z-index: var(--z-tooltip);
                    margin-bottom: 0.5rem;
                    box-shadow: var(--shadow-lg);
                    pointer-events: none;
                }
                [data-tooltip-position="right"]:hover::after {
                    left: 100%;
                    top: 50%;
                    transform: translateY(-50%);
                    margin-bottom: 0;
                    margin-left: 0.5rem;
                }
                [data-tooltip-position="left"]:hover::after {
                    right: 100%;
                    left: auto;
                    top: 50%;
                    transform: translateY(-50%);
                    margin-bottom: 0;
                    margin-right: 0.5rem;
                }
                [data-tooltip-position="bottom"]:hover::after {
                    bottom: auto;
                    top: 100%;
                    margin-bottom: 0;
                    margin-top: 0.5rem;
                }
            `;
            document.head.appendChild(styles);
        }
    }

    // ========================================
    // Popover System
    // ========================================
    function initPopovers() {
        document.addEventListener('click', function(e) {
            const popoverTrigger = e.target.closest('[data-popover]');
            if (popoverTrigger) {
                e.preventDefault();
                const popoverId = popoverTrigger.getAttribute('data-popover');
                const popover = document.getElementById(popoverId);

                if (popover) {
                    const isVisible = popover.classList.contains('active');

                    // Close all popovers
                    document.querySelectorAll('.popover.active').forEach(p => {
                        p.classList.remove('active');
                    });

                    if (!isVisible) {
                        popover.classList.add('active');
                    }
                }
            }
        });

        // Close on outside click
        document.addEventListener('click', function(e) {
            if (!e.target.closest('[data-popover]') && !e.target.closest('.popover')) {
                document.querySelectorAll('.popover.active').forEach(p => {
                    p.classList.remove('active');
                });
            }
        });
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
                    const offset = this.getAttribute('data-offset') ? parseInt(this.getAttribute('data-offset')) : 0;

                    targetElement.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });

                    // Adjust for offset
                    if (offset) {
                        window.scrollBy(0, -offset);
                    }
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
                        
                        if (img.dataset.src) {
                            img.src = img.dataset.src;
                        }
                        
                        if (img.dataset.srcset) {
                            img.srcset = img.dataset.srcset;
                        }

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
    // Form Validation (Premium)
    // ========================================
    function initFormValidation() {
        document.querySelectorAll('form[data-validate]').forEach(form => {
            const validator = new FormValidator(form);

            form.addEventListener('submit', function(e) {
                if (!validator.validate()) {
                    e.preventDefault();
                    const firstError = form.querySelector('.error');
                    if (firstError) {
                        firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                    showToast('Por favor, corrija os erros no formulário', 'error');
                }
            });
        });

        class FormValidator {
            constructor(form) {
                this.form = form;
                this.inputs = form.querySelectorAll('[required], [data-validate]');

                this.inputs.forEach(input => {
                    input.addEventListener('blur', () => this.validateField(input));
                    input.addEventListener('input', () => {
                        if (input.classList.contains('error')) {
                            this.validateField(input);
                        }
                    });
                });
            }

            validateField(input) {
                const isValid = this.checkField(input);

                if (isValid) {
                    input.classList.remove('error');
                    input.classList.add('success');
                    this.removeError(input);
                } else {
                    input.classList.remove('success');
                    input.classList.add('error');
                    this.showError(input, this.getErrorMessage(input));
                }

                return isValid;
            }

            checkField(input) {
                if (input.hasAttribute('required') && !input.value.trim()) {
                    return false;
                }

                if (input.type === 'email' && input.value) {
                    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    return emailRegex.test(input.value);
                }

                if (input.type === 'tel' && input.value) {
                    const phoneRegex = /^\(?\d{2}\)?[\s-]?\d{4,5}[\s-]?\d{4}$/;
                    return phoneRegex.test(input.value.replace(/\D/g, ''));
                }

                if (input.dataset.validate) {
                    const rules = input.dataset.validate.split('|');
                    return rules.every(rule => this.checkRule(input, rule));
                }

                return true;
            }

            checkRule(input, rule) {
                if (rule === 'email') {
                    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    return emailRegex.test(input.value);
                }

                if (rule === 'phone') {
                    const phoneRegex = /^\(?\d{2}\)?[\s-]?\d{4,5}[\s-]?\d{4}$/;
                    return phoneRegex.test(input.value.replace(/\D/g, ''));
                }

                if (rule.startsWith('minlength:')) {
                    const minLength = parseInt(rule.split(':')[1]);
                    return input.value.length >= minLength;
                }

                if (rule.startsWith('maxlength:')) {
                    const maxLength = parseInt(rule.split(':')[1]);
                    return input.value.length <= maxLength;
                }

                if (rule.startsWith('min:')) {
                    const min = parseFloat(rule.split(':')[1]);
                    return parseFloat(input.value) >= min;
                }

                if (rule.startsWith('max:')) {
                    const max = parseFloat(rule.split(':')[1]);
                    return parseFloat(input.value) <= max;
                }

                return true;
            }

            getErrorMessage(input) {
                if (input.hasAttribute('required') && !input.value.trim()) {
                    return 'Este campo é obrigat\u00f3rio';
                }

                if (input.type === 'email' && input.value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(input.value)) {
                    return 'Digite um email v\u00e1lido';
                }

                if (input.dataset.errorMessage) {
                    return input.dataset.errorMessage;
                }

                return 'Campo inv\u00e1lido';
            }

            showError(input, message) {
                this.removeError(input);

                const errorDiv = document.createElement('div');
                errorDiv.className = 'form-error';
                errorDiv.textContent = message;
                input.parentElement.appendChild(errorDiv);
            }

            removeError(input) {
                const errorDiv = input.parentElement.querySelector('.form-error');
                if (errorDiv) {
                    errorDiv.remove();
                }
            }

            validate() {
                let isValid = true;

                this.inputs.forEach(input => {
                    if (!this.validateField(input)) {
                        isValid = false;
                    }
                });

                return isValid;
            }
        }
    }

    // ========================================
    // Confirm Dialogs
    // ========================================
    function initConfirmDialogs() {
        document.addEventListener('click', function(e) {
            const confirmBtn = e.target.closest('[data-confirm]');
            if (confirmBtn) {
                const message = confirmBtn.getAttribute('data-confirm') || 'Tem certeza?';
                const title = confirmBtn.getAttribute('data-confirm-title') || 'Confirma\u00e7\u00e3o';

                if (!confirm(`${title}\n\n${message}`)) {
                    e.preventDefault();
                    return false;
                }
            }
        });
    }

    // ========================================
    // Tabs System
    // ========================================
    function initTabs() {
        document.querySelectorAll('[data-tab]').forEach(tab => {
            tab.addEventListener('click', function(e) {
                e.preventDefault();

                const tabId = this.getAttribute('data-tab');
                const tabContainer = this.closest('[data-tabs]');

                if (!tabContainer) return;

                // Remove active from all tabs
                tabContainer.querySelectorAll('[data-tab]').forEach(t => {
                    t.classList.remove('active');
                });

                // Hide all tab content
                tabContainer.querySelectorAll('[data-tab-content]').forEach(c => {
                    c.classList.remove('active');
                });

                // Activate clicked tab
                this.classList.add('active');

                // Show corresponding content
                const content = tabContainer.querySelector(`[data-tab-content="${tabId}"]`);
                if (content) {
                    content.classList.add('active');
                }
            });
        });
    }

    // ========================================
    // Accordion System
    // ========================================
    function initAccordions() {
        document.querySelectorAll('[data-accordion]').forEach(accordion => {
            accordion.addEventListener('click', function(e) {
                const target = this.getAttribute('data-accordion');
                const content = document.getElementById(target);

                if (!content) return;

                const isActive = this.classList.contains('active');

                // Close all accordions in the same group
                const group = this.getAttribute('data-accordion-group');
                if (group) {
                    document.querySelectorAll(`[data-accordion-group="${group}"]`).forEach(a => {
                        a.classList.remove('active');
                        const c = document.getElementById(a.getAttribute('data-accordion'));
                        if (c) c.style.maxHeight = '0';
                    });
                } else {
                    this.classList.toggle('active');
                }

                if (!isActive) {
                    this.classList.add('active');
                    content.style.maxHeight = content.scrollHeight + 'px';
                } else {
                    content.style.maxHeight = '0';
                }
            });
        });
    }

    // ========================================
    // Counter Animation
    // ========================================
    function initCounters() {
        if ('IntersectionObserver' in window) {
            const counterObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const counter = entry.target;
                        animateCounter(counter);
                        counterObserver.unobserve(counter);
                    }
                });
            });

            document.querySelectorAll('[data-counter]').forEach(counter => {
                counterObserver.observe(counter);
            });
        }

        function animateCounter(counter) {
            const target = parseInt(counter.getAttribute('data-counter'));
            const duration = parseInt(counter.getAttribute('data-counter-duration')) || 2000;
            const increment = target / (duration / 16);
            let current = 0;

            const timer = setInterval(() => {
                current += increment;
                if (current >= target) {
                    counter.textContent = target.toLocaleString('pt-BR');
                    clearInterval(timer);
                } else {
                    counter.textContent = Math.floor(current).toLocaleString('pt-BR');
                }
            }, 16);
        }
    }

    // ========================================
    // Parallax Effect
    // ========================================
    function initParallax() {
        const parallaxElements = document.querySelectorAll('[data-parallax]');

        if (parallaxElements.length) {
            window.addEventListener('scroll', throttle(() => {
                parallaxElements.forEach(el => {
                    const speed = parseFloat(el.getAttribute('data-parallax')) || 0.5;
                    const yPos = -(window.pageYOffset * speed);
                    el.style.transform = `translateY(${yPos}px)`;
                });
            }, 10));
        }
    }

    // ========================================
    // Ripple Effect (Material Design)
    // ========================================
    function initRippleEffect() {
        document.querySelectorAll('.btn, [data-ripple]').forEach(button => {
            button.addEventListener('click', function(e) {
                const rect = this.getBoundingClientRect();
                const x = e.clientX - rect.left;
                const y = e.clientY - rect.top;

                const ripple = document.createElement('span');
                ripple.className = 'ripple';
                ripple.style.left = `${x}px`;
                ripple.style.top = `${y}px`;

                this.appendChild(ripple);

                setTimeout(() => ripple.remove(), 600);
            });
        });

        // Add ripple styles
        if (!document.getElementById('ripple-styles')) {
            const styles = document.createElement('style');
            styles.id = 'ripple-styles';
            styles.textContent = `
                .btn, [data-ripple] {
                    position: relative;
                    overflow: hidden;
                }
                .ripple {
                    position: absolute;
                    border-radius: 50%;
                    background: rgba(255, 255, 255, 0.4);
                    transform: scale(0);
                    animation: ripple-animation 0.6s ease-out;
                    pointer-events: none;
                }
                @keyframes ripple-animation {
                    to {
                        transform: scale(4);
                        opacity: 0;
                    }
                }
            `;
            document.head.appendChild(styles);
        }
    }

    // ========================================
    // Scroll Animations
    // ========================================
    function initScrollAnimations() {
        if ('IntersectionObserver' in window) {
            const animationObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const el = entry.target;
                        const animation = el.getAttribute('data-animation') || 'fade-in';

                        el.classList.add(`animate-${animation}`);
                        animationObserver.unobserve(el);
                    }
                });
            }, { threshold: 0.1 });

            document.querySelectorAll('[data-animation]').forEach(el => {
                animationObserver.observe(el);
            });
        }
    }

    // ========================================
    // Keyboard Shortcuts
    // ========================================
    function initKeyboardShortcuts() {
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + K: Search
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                const searchInput = document.querySelector('input[type="search"], .search-input');
                if (searchInput) {
                    searchInput.focus();
                }
            }

            // ESC: Close modals, dropdowns, etc.
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.active').forEach(modal => {
                    modal.classList.remove('active');
                });
                document.querySelectorAll('.dropdown-menu.active').forEach(dropdown => {
                    dropdown.classList.remove('active');
                });
            }
        });
    }

    // ========================================
    // Online/Offline Status
    // ========================================
    function initOnlineStatus() {
        function updateOnlineStatus() {
            if (navigator.onLine) {
                document.body.classList.remove('offline');
                document.body.classList.add('online');
            } else {
                document.body.classList.remove('online');
                document.body.classList.add('offline');
                showToast('Voc\u00ea est\u00e1 offline. Algumas funcionalidades podem n\u00e3o estar dispon\u00edveis.', 'warning');
            }
        }

        window.addEventListener('online', updateOnlineStatus);
        window.addEventListener('offline', updateOnlineStatus);
        updateOnlineStatus();
    }

    // ========================================
    // Theme Switcher (Dark/Light)
    // ========================================
    function initThemeSwitcher() {
        const themeToggle = document.querySelector('[data-theme-toggle]');

        if (themeToggle) {
            const savedTheme = localStorage.getItem(`${CONFIG.storagePrefix}theme`);
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

            if (savedTheme === 'dark' || (!savedTheme && prefersDark)) {
                document.documentElement.setAttribute('data-theme', 'dark');
                themeToggle.setAttribute('aria-pressed', 'true');
            }

            themeToggle.addEventListener('click', function() {
                const isDark = document.documentElement.getAttribute('data-theme') === 'dark';

                if (isDark) {
                    document.documentElement.removeAttribute('data-theme');
                    localStorage.setItem(`${CONFIG.storagePrefix}theme`, 'light');
                    this.setAttribute('aria-pressed', 'false');
                } else {
                    document.documentElement.setAttribute('data-theme', 'dark');
                    localStorage.setItem(`${CONFIG.storagePrefix}theme`, 'dark');
                    this.setAttribute('aria-pressed', 'true');
                }
            });
        }
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
            timeout = setTimeout(later, wait || CONFIG.debounceDelay);
        };
    };

    // Throttle
    window.throttle = function(func, limit) {
        let inThrottle;
        return function(...args) {
            if (!inThrottle) {
                func.apply(this, args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit || CONFIG.throttleDelay);
            }
        };
    };

    // Copy to clipboard
    window.copyToClipboard = async function(text) {
        try {
            await navigator.clipboard.writeText(text);
            showSuccess('Copiado para a \u00e1rea de transfer\u00eancia!');
            return true;
        } catch (err) {
            // Fallback for older browsers
            const textArea = document.createElement('textarea');
            textArea.value = text;
            textArea.style.position = 'fixed';
            textArea.style.left = '-999999px';
            document.body.appendChild(textArea);
            textArea.select();
            try {
                document.execCommand('copy');
                showSuccess('Copiado para a \u00e1rea de transfer\u00eancia!');
                return true;
            } catch (err) {
                showError('Erro ao copiar');
                return false;
            } finally {
                textArea.remove();
            }
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

    // Format percentage
    window.formatPercentage = function(num, decimals = 1) {
        return `${(num * 100).toFixed(decimals)}%`;
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

    // Remove URL parameter
    window.removeUrlParam = function(param) {
        const url = new URL(window.location);
        url.searchParams.delete(param);
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

    // Local Storage helpers
    window.storage = {
        get: function(key) {
            const item = localStorage.getItem(`${CONFIG.storagePrefix}${key}`);
            return item ? JSON.parse(item) : null;
        },
        set: function(key, value) {
            localStorage.setItem(`${CONFIG.storagePrefix}${key}`, JSON.stringify(value));
        },
        remove: function(key) {
            localStorage.removeItem(`${CONFIG.storagePrefix}${key}`);
        },
        clear: function() {
            const keys = Object.keys(localStorage).filter(k => k.startsWith(CONFIG.storagePrefix));
            keys.forEach(k => localStorage.removeItem(k));
        }
    };

    // Session Storage helpers
    // IMPORTANTE: não usar o nome "sessionStorage" aqui — isso sobrescreveria a API
    // nativa do navegador (window.sessionStorage) e pode quebrar qualquer outro
    // script/lib que dependa dela.
    window.wazeSession = {
        get: function(key) {
            const item = sessionStorage.getItem(`${CONFIG.storagePrefix}${key}`);
            return item ? JSON.parse(item) : null;
        },
        set: function(key, value) {
            sessionStorage.setItem(`${CONFIG.storagePrefix}${key}`, JSON.stringify(value));
        },
        remove: function(key) {
            sessionStorage.removeItem(`${CONFIG.storagePrefix}${key}`);
        }
    };

    // Random helpers
    window.random = {
        number: function(min, max) {
            return Math.floor(Math.random() * (max - min + 1)) + min;
        },
        string: function(length) {
            const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
            let result = '';
            for (let i = 0; i < length; i++) {
                result += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            return result;
        },
        color: function() {
            return '#' + Math.floor(Math.random()*16777215).toString(16).padStart(6, '0');
        }
    };

    // Device detection
    window.device = {
        isMobile: /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent),
        isTablet: /iPad|Android(?!.*Mobile)/i.test(navigator.userAgent),
        isDesktop: !/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent),
        isIOS: /iPad|iPhone|iPod/i.test(navigator.userAgent),
        isAndroid: /Android/i.test(navigator.userAgent),
        isChrome: /Chrome/i.test(navigator.userAgent),
        isFirefox: /Firefox/i.test(navigator.userAgent),
        isSafari: /Safari/i.test(navigator.userAgent) && !/Chrome/i.test(navigator.userAgent),
        isEdge: /Edg/i.test(navigator.userAgent)
    };

    // Browser language
    window.getBrowserLanguage = function() {
        return navigator.language || navigator.userLanguage || 'pt-BR';
    };

    // Performance timing
    // IMPORTANTE: não usar o nome "performance" aqui — isso sobrescreveria a API
    // nativa do navegador (window.performance / performance.now()) usada por libs
    // de terceiros e ferramentas de monitoramento.
    window.wazePerf = {
        start: function(label) {
            console.time(label);
        },
        end: function(label) {
            console.timeEnd(label);
        }
    };

})();
