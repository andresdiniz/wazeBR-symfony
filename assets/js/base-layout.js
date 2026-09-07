/**
 * wazeBR - Base Layout JavaScript
 * Header, Navbar, Footer functionality
 */

document.addEventListener('DOMContentLoaded', function() {
    initHeaderScroll();
    initUserMenu();
    initMobileNavigation();
    initSubmenus();
    initActiveNavLink();
});

/**
 * Header scroll effect
 */
function initHeaderScroll() {
    const header = document.querySelector('.site-header');
    if (!header) return;
    
    window.addEventListener('scroll', () => {
        if (window.scrollY > 50) {
            header.classList.add('scrolled');
        } else {
            header.classList.remove('scrolled');
        }
    });
}

/**
 * Toggle user dropdown menu
 */
function toggleUserMenu() {
    const dropdown = document.getElementById('user-dropdown');
    if (!dropdown) return;
    
    dropdown.classList.toggle('active');
}

function initUserMenu() {
    const userMenuBtn = document.querySelector('.user-menu-btn');
    const dropdown = document.getElementById('user-dropdown');
    
    if (!userMenuBtn || !dropdown) return;
    
    userMenuBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        toggleUserMenu();
    });
    
    // Close dropdown when clicking outside
    document.addEventListener('click', (e) => {
        if (!dropdown.contains(e.target) && !userMenuBtn.contains(e.target)) {
            dropdown.classList.remove('active');
        }
    });
}

/**
 * Toggle mobile navigation
 */
function toggleMobileNav() {
    const mobileNav = document.getElementById('mobile-nav');
    if (!mobileNav) return;
    
    mobileNav.classList.toggle('active');
    
    // Prevent body scroll when menu is open
    if (mobileNav.classList.contains('active')) {
        document.body.style.overflow = 'hidden';
    } else {
        document.body.style.overflow = '';
    }
}

function initMobileNavigation() {
    const mobileNav = document.getElementById('mobile-nav');
    const mobileNavToggle = document.getElementById('mobile-nav-toggle');
    
    if (!mobileNav || !mobileNavToggle) return;
    
    mobileNavToggle.addEventListener('click', toggleMobileNav);
    
    // Close mobile nav when clicking on a link
    const mobileNavLinks = mobileNav.querySelectorAll('a');
    mobileNavLinks.forEach(link => {
        link.addEventListener('click', () => {
            toggleMobileNav();
        });
    });
    
    // Close mobile nav on resize if desktop
    window.addEventListener('resize', () => {
        if (window.innerWidth > 992 && mobileNav.classList.contains('active')) {
            toggleMobileNav();
        }
    });
}

/**
 * Toggle submenu (for mobile/touch devices)
 */
function toggleSubmenu(event) {
    event.preventDefault();
    
    const navItem = event.currentTarget.closest('.nav-item');
    const submenu = navItem.querySelector('.submenu');
    
    if (!submenu) return;
    
    // Toggle active class
    navItem.classList.toggle('submenu-active');
}

function initSubmenus() {
    const submenuLinks = document.querySelectorAll('.has-submenu > .nav-link');
    
    submenuLinks.forEach(link => {
        link.addEventListener('click', toggleSubmenu);
    });
    
    // Close submenus when clicking outside
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.has-submenu')) {
            document.querySelectorAll('.has-submenu').forEach(item => {
                item.classList.remove('submenu-active');
            });
        }
    });
}

/**
 * Active navigation link based on current URL
 */
function initActiveNavLink() {
    const currentPath = window.location.pathname;
    const navLinks = document.querySelectorAll('.nav-link, .mobile-nav-list a');
    
    navLinks.forEach(link => {
        const href = link.getAttribute('href');
        
        if (href && href !== '#' && !href.startsWith('#')) {
            try {
                const linkPath = new URL(href, window.location.origin).pathname;
                
                if (linkPath === currentPath) {
                    link.classList.add('active');
                }
            } catch (e) {
                // Ignore invalid URLs
            }
        }
    });
}

/**
 * Smooth scroll for anchor links
 */
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

/**
 * Utility: Debounce function
 */
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

// Initialize smooth scroll
initSmoothScroll();

// Export functions for global access
window.toggleUserMenu = toggleUserMenu;
window.toggleMobileNav = toggleMobileNav;
window.toggleSubmenu = toggleSubmenu;

console.log('wazeBR Base Layout initialized successfully! 🧭');
