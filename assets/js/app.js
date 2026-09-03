/**
 * wazeBR - Modern Dashboard JavaScript
 * Works with Symfony AssetMapper / Importmap
 */

// DOM Ready
document.addEventListener('DOMContentLoaded', () => {
    initializeTheme();
    initializeAnimations();
    initializeComponents();
});

// Theme Management
function initializeTheme() {
    const html = document.documentElement;
    const savedTheme = localStorage.getItem('theme') || 'light';
    html.setAttribute('data-theme', savedTheme);

    const themeToggle = document.querySelector('[data-theme-toggle]');
    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            const currentTheme = html.getAttribute('data-theme');
            const newTheme = currentTheme === 'light' ? 'dark' : 'light';
            html.setAttribute('data-theme', newTheme);
            localStorage.setItem('theme', newTheme);
        });
    }
}

// Animations with Intersection Observer
function initializeAnimations() {
    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('animate-fade-in');
                    observer.unobserve(entry.target);
                }
            });
        },
        { threshold: 0.1 }
    );

    document.querySelectorAll('[data-animate]').forEach((el) => {
        observer.observe(el);
    });
}

// Components
function initializeComponents() {
    // Dropdowns
    document.querySelectorAll('[data-dropdown]').forEach((dropdown) => {
        const trigger = dropdown.querySelector('[data-dropdown-trigger]');
        const menu = dropdown.querySelector('[data-dropdown-menu]');

        if (trigger && menu) {
            trigger.addEventListener('click', (e) => {
                e.preventDefault();
                menu.classList.toggle('hidden');
            });

            document.addEventListener('click', (e) => {
                if (!dropdown.contains(e.target)) {
                    menu.classList.add('hidden');
                }
            });
        }
    });

    // Modals
    document.querySelectorAll('[data-modal-trigger]').forEach((trigger) => {
        const modalId = trigger.dataset.modalTrigger;
        const modal = document.getElementById(modalId);

        if (modal) {
            trigger.addEventListener('click', () => {
                modal.classList.remove('hidden');
                modal.querySelector('[data-modal-close]')?.addEventListener('click', () => {
                    modal.classList.add('hidden');
                });
            });
        }
    });

    // Auto-dismiss alerts
    document.querySelectorAll('[data-auto-dismiss]').forEach((alert) => {
        const timeout = parseInt(alert.dataset.autoDismiss) || 5000;
        setTimeout(() => {
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 300);
        }, timeout);
    });
}

// API Helper
window.api = {
    async get(url, options = {}) {
        const response = await fetch(url, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                ...options.headers
            },
            ...options
        });

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        return response.json();
    },

    async post(url, data, options = {}) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                ...options.headers
            },
            body: JSON.stringify(data),
            ...options
        });

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        return response.json();
    }
};

// Utility functions
window.utils = {
    debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    },

    formatDate(date, locale = 'pt-BR') {
        return new Intl.DateTimeFormat(locale, {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        }).format(new Date(date));
    },

    formatNumber(number, locale = 'pt-BR') {
        return new Intl.NumberFormat(locale).format(number);
    },

    truncate(str, length) {
        return str.length > length ? str.substring(0, length) + '...' : str;
    }
};

console.log('wazeBR Dashboard initialized');
