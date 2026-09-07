/**
 * wazeBR - Premium Header, Nav & Footer JavaScript
 * Advanced interactions and animations
 */

(function() {
    'use strict';

    // ========================================
    // Configuration
    // ========================================
    const CONFIG = {
        scrollThreshold: 50,
        debounceDelay: 300,
        animationDuration: 300,
    };

    // ========================================
    // Initialize on DOM Ready
    // ========================================
    document.addEventListener('DOMContentLoaded', function() {
        initHeaderScroll();
        initMobileNav();
        initDropdowns();
        initSubmenus();
        initSearch();
        initNotifications();
        initThemeToggle();
        initBackToTop();
        initMobileSubmenus();
        initSkipLink();
        initKeyboardShortcuts();
        
        console.log('%c🧭 wazeBR Header', 'font-size: 14px; font-weight: bold; color: #2563eb;');
        console.log('%cHeader, Nav & Footer JavaScript loaded successfully!', 'font-size: 12px; color: #22c55e;');
    });

    // ========================================
    // Header Scroll Effect
    // ========================================
    function initHeaderScroll() {
        const header = document.getElementById('site-header');
        if (!header) return;

        let lastScroll = 0;
        let ticking = false;

        function updateHeader() {
            const scrollTop = window.pageYOffset;

            // Add scrolled class
            if (scrollTop > CONFIG.scrollThreshold) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }

            // Hide/show on scroll direction
            if (scrollTop > lastScroll && scrollTop > CONFIG.scrollThreshold + 100) {
                header.style.transform = 'translateY(-100%)';
            } else {
                header.style.transform = 'translateY(0)';
            }

            lastScroll = scrollTop;
            ticking = false;
        }

        window.addEventListener('scroll', function() {
            if (!ticking) {
                requestAnimationFrame(updateHeader);
                ticking = true;
            }
        }, { passive: true });
    }

    // ========================================
    // Mobile Navigation
    // ========================================
    function initMobileNav() {
        const mobileMenuToggle = document.getElementById('mobile-menu-toggle');
        const mobileNav = document.getElementById('mobile-nav');
        const mobileNavClose = document.querySelector('.mobile-nav-close');

        if (!mobileMenuToggle || !mobileNav) return;

        // Toggle mobile nav
        mobileMenuToggle.addEventListener('click', function() {
            const isActive = mobileNav.classList.toggle('active');
            mobileMenuToggle.classList.toggle('active');
            mobileMenuToggle.setAttribute('aria-expanded', isActive);
            
            // Prevent body scroll
            document.body.style.overflow = isActive ? 'hidden' : '';
        });

        // Close on close button
        if (mobileNavClose) {
            mobileNavClose.addEventListener('click', function() {
                mobileNav.classList.remove('active');
                mobileMenuToggle.classList.remove('active');
                mobileMenuToggle.setAttribute('aria-expanded', 'false');
                document.body.style.overflow = '';
            });
        }

        // Close on link click
        mobileNav.querySelectorAll('a:not(.mobile-nav-submenu-toggle)').forEach(link => {
            link.addEventListener('click', function() {
                mobileNav.classList.remove('active');
                mobileMenuToggle.classList.remove('active');
                mobileMenuToggle.setAttribute('aria-expanded', 'false');
                document.body.style.overflow = '';
            });
        });

        // Close on outside click
        document.addEventListener('click', function(e) {
            if (!mobileNav.contains(e.target) && !mobileMenuToggle.contains(e.target)) {
                mobileNav.classList.remove('active');
                mobileMenuToggle.classList.remove('active');
                mobileMenuToggle.setAttribute('aria-expanded', 'false');
                document.body.style.overflow = '';
            }
        });

        // Close on resize
        window.addEventListener('resize', debounce(function() {
            if (window.innerWidth > 992 && mobileNav.classList.contains('active')) {
                mobileNav.classList.remove('active');
                mobileMenuToggle.classList.remove('active');
                mobileMenuToggle.setAttribute('aria-expanded', 'false');
                document.body.style.overflow = '';
            }
        }, CONFIG.debounceDelay));
    }

    // ========================================
    // Dropdown System
    // ========================================
    function initDropdowns() {
        // Toggle dropdowns
        document.querySelectorAll('[data-dropdown-toggle]').forEach(toggle => {
            toggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();

                const dropdownId = this.getAttribute('data-dropdown-toggle');
                const dropdown = document.getElementById(dropdownId);

                if (!dropdown) return;

                const isOpen = dropdown.classList.contains('active');

                // Close all dropdowns
                closeAllDropdowns();

                // Open clicked dropdown
                if (!isOpen) {
                    dropdown.classList.add('active');
                    this.setAttribute('aria-expanded', 'true');
                }
            });
        });

        // Close on outside click
        document.addEventListener('click', function(e) {
            if (!e.target.closest('[data-dropdown-toggle]') && !e.target.closest('.dropdown-menu')) {
                closeAllDropdowns();
            }
        });

        // Close on ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAllDropdowns();
            }
        });

        function closeAllDropdowns() {
            document.querySelectorAll('.dropdown-menu.active').forEach(dropdown => {
                dropdown.classList.remove('active');
                const toggle = document.querySelector(`[data-dropdown-toggle="${dropdown.id}"]`);
                if (toggle) {
                    toggle.setAttribute('aria-expanded', 'false');
                }
            });
        }
    }

    // ========================================
    // Desktop Submenus
    // ========================================
    function initSubmenus() {
        document.querySelectorAll('.nav-item.has-submenu').forEach(item => {
            const toggle = item.querySelector('.nav-link');
            const submenu = item.querySelector('.submenu');

            if (!toggle || !submenu) return;

            // Mouse enter - show submenu
            item.addEventListener('mouseenter', function() {
                submenu.style.opacity = '1';
                submenu.style.visibility = 'visible';
                submenu.style.transform = 'translateY(0)';
                toggle.setAttribute('aria-expanded', 'true');
            });

            // Mouse leave - hide submenu
            item.addEventListener('mouseleave', function() {
                submenu.style.opacity = '0';
                submenu.style.visibility = 'hidden';
                submenu.style.transform = 'translateY(10px)';
                toggle.setAttribute('aria-expanded', 'false');
            });

            // Keyboard navigation
            toggle.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    const isOpen = submenu.style.visibility === 'visible';
                    
                    // Close all other submenus
                    document.querySelectorAll('.nav-item.has-submenu .submenu').forEach(s => {
                        s.style.opacity = '0';
                        s.style.visibility = 'hidden';
                        s.style.transform = 'translateY(10px)';
                    });

                    if (!isOpen) {
                        submenu.style.opacity = '1';
                        submenu.style.visibility = 'visible';
                        submenu.style.transform = 'translateY(0)';
                        toggle.setAttribute('aria-expanded', 'true');
                    }
                }
            });
        });
    }

    // ========================================
    // Mobile Submenus
    // ========================================
    function initMobileSubmenus() {
        document.querySelectorAll('.mobile-nav-submenu-toggle').forEach(toggle => {
            toggle.addEventListener('click', function() {
                const parent = this.closest('.mobile-nav-submenu');
                const isActive = parent.classList.toggle('active');
                this.setAttribute('aria-expanded', isActive);
            });
        });
    }

    // ========================================
    // Search
    // ========================================
    function initSearch() {
        const searchInput = document.querySelector('[data-search]');
        if (!searchInput) return;

        // Debounced search
        let debounceTimer;
        searchInput.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
                performSearch(this.value);
            }, CONFIG.debounceDelay);
        });

        // Search on Enter
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                performSearch(this.value);
            }
        });

        function performSearch(query) {
            if (!query.trim()) return;
            
            // TODO: Implement search functionality
            console.log('Searching for:', query);
            
            // Example: Redirect to search page
            // window.location.href = `/search?q=${encodeURIComponent(query)}`;
        }
    }

    // ========================================
    // Notifications
    // ========================================
    function initNotifications() {
        const markAllRead = document.querySelector('.mark-all-read');
        if (!markAllRead) return;

        markAllRead.addEventListener('click', function() {
            // Mark all notifications as read
            document.querySelectorAll('.notification-item.unread').forEach(item => {
                item.classList.remove('unread');
            });

            // Update badge
            const badge = document.getElementById('notification-count');
            if (badge) {
                badge.textContent = '0';
                badge.style.display = 'none';
            }

            showToast('Todas as notifica\u00e7\u00f5es foram marcadas como lidas', 'success');
        });

        // Click on notification
        document.querySelectorAll('.notification-item').forEach(item => {
            item.addEventListener('click', function() {
                this.classList.remove('unread');
                
                // TODO: Navigate to notification link
                // window.location.href = this.dataset.link;
            });
        });
    }

    // ========================================
    // Theme Toggle
    // ========================================
    function initThemeToggle() {
        const themeToggle = document.querySelector('[data-theme-toggle]');
        if (!themeToggle) return;

        themeToggle.addEventListener('click', function() {
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';

            if (isDark) {
                document.documentElement.removeAttribute('data-theme');
                localStorage.setItem('wazeBR_theme', 'light');
                this.setAttribute('aria-pressed', 'false');
                showToast('Tema claro ativado', 'info');
            } else {
                document.documentElement.setAttribute('data-theme', 'dark');
                localStorage.setItem('wazeBR_theme', 'dark');
                this.setAttribute('aria-pressed', 'true');
                showToast('Tema escuro ativado', 'info');
            }
        });
    }

    // ========================================
    // Back to Top
    // ========================================
    function initBackToTop() {
        const backToTop = document.getElementById('back-to-top');
        if (!backToTop) return;

        // Show/hide on scroll
        window.addEventListener('scroll', throttle(function() {
            if (window.pageYOffset > 300) {
                backToTop.style.opacity = '1';
                backToTop.style.visibility = 'visible';
                backToTop.style.transform = 'translateY(0)';
            } else {
                backToTop.style.opacity = '0';
                backToTop.style.visibility = 'hidden';
                backToTop.style.transform = 'translateY(20px)';
            }
        }, 100));

        // Scroll to top on click
        backToTop.addEventListener('click', function() {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });

        // Initial state
        backToTop.style.opacity = '0';
        backToTop.style.visibility = 'hidden';
        backToTop.style.transform = 'translateY(20px)';
        backToTop.style.transition = 'all 0.3s ease';
    }

    // ========================================
    // Skip Link (Accessibility)
    // ========================================
    function initSkipLink() {
        const skipLink = document.querySelector('.skip-link');
        const mainContent = document.getElementById('main-content');

        if (skipLink && mainContent) {
            skipLink.addEventListener('click', function(e) {
                e.preventDefault();
                mainContent.focus();
                mainContent.scrollIntoView({ behavior: 'smooth' });
            });
        }
    }

    // ========================================
    // Keyboard Shortcuts
    // ========================================
    function initKeyboardShortcuts() {
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + K: Focus search
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                const searchInput = document.querySelector('[data-search], .search-input');
                if (searchInput) {
                    searchInput.focus();
                }
            }

            // Ctrl/Cmd + /: Toggle theme
            if ((e.ctrlKey || e.metaKey) && e.key === '/') {
                e.preventDefault();
                const themeToggle = document.querySelector('[data-theme-toggle]');
                if (themeToggle) {
                    themeToggle.click();
                }
            }

            // Escape: Close all dropdowns and mobile nav
            if (e.key === 'Escape') {
                const mobileNav = document.getElementById('mobile-nav');
                const mobileMenuToggle = document.getElementById('mobile-menu-toggle');
                
                if (mobileNav && mobileNav.classList.contains('active')) {
                    mobileNav.classList.remove('active');
                    mobileMenuToggle.classList.remove('active');
                    mobileMenuToggle.setAttribute('aria-expanded', 'false');
                    document.body.style.overflow = '';
                }

                document.querySelectorAll('.dropdown-menu.active').forEach(dropdown => {
                    dropdown.classList.remove('active');
                    const toggle = document.querySelector(`[data-dropdown-toggle="${dropdown.id}"]`);
                    if (toggle) {
                        toggle.setAttribute('aria-expanded', 'false');
                    }
                });
            }
        });
    }

    // ========================================
    // Utility: Debounce
    // ========================================
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    // ========================================
    // Utility: Throttle
    // ========================================
    function throttle(func, limit) {
        let inThrottle;
        return function(...args) {
            if (!inThrottle) {
                func.apply(this, args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    }

})();
