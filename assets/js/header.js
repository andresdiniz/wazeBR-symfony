/**
 * Header - Professional Interactions
 */

(function() {
    'use strict';

    const header = document.getElementById('appHeader');
    const searchInput = document.querySelector('.search-input');
    const notificationLink = document.querySelector('.notification-link');
    const userMenu = document.querySelector('.user-menu');

    // Scroll Effect
    function handleScroll() {
        if (window.scrollY > 20) {
            header.classList.add('header-scrolled');
        } else {
            header.classList.remove('header-scrolled');
        }
    }

    // Search Focus Effect
    function initSearch() {
        if (!searchInput) return;

        searchInput.addEventListener('focus', function() {
            this.parentElement.classList.add('search-focused');
        });

        searchInput.addEventListener('blur', function() {
            this.parentElement.classList.remove('search-focused');
        });

        // Search on Enter
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                const query = this.value.trim();
                if (query) {
                    // Implement search logic here
                    console.log('Searching for:', query);
                }
            }
        });
    }

    // Notification Click
    function initNotifications() {
        if (!notificationLink) return;

        notificationLink.addEventListener('click', function(e) {
            e.preventDefault();
            // Implement notification dropdown logic here
            const badge = this.querySelector('.notification-badge');
            if (badge) {
                badge.style.transform = 'scale(0)';
                setTimeout(() => {
                    badge.remove();
                }, 200);
            }
        });
    }

    // User Menu Animation
    function initUserMenu() {
        if (!userMenu) return;

        userMenu.addEventListener('click', function() {
            const dropdown = this.querySelector('.user-dropdown');
            if (dropdown) {
                dropdown.classList.toggle('show');
            }
        });

        // Close on outside click
        document.addEventListener('click', function(e) {
            if (!userMenu.contains(e.target)) {
                const dropdown = userMenu.querySelector('.user-dropdown');
                if (dropdown) {
                    dropdown.classList.remove('show');
                }
            }
        });
    }

    // Mobile Menu Animation
    function initMobileMenu() {
        const toggler = document.querySelector('.navbar-toggler');
        const collapse = document.querySelector('.navbar-collapse');

        if (!toggler || !collapse) return;

        toggler.addEventListener('click', function() {
            collapse.classList.toggle('show');
            this.classList.toggle('active');
        });
    }

    // Initialize
    function init() {
        window.addEventListener('scroll', handleScroll, { passive: true });
        handleScroll(); // Check initial state

        initSearch();
        initNotifications();
        initUserMenu();
        initMobileMenu();
    }

    // Run when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
