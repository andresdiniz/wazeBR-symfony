/**
 * WazeBR - Main Application JavaScript
 */

(function() {
    'use strict';

    /**
     * Sidebar Toggle Functionality
     */
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    const toggle = document.getElementById('sidebar-toggle');

    if (sidebar && overlay && toggle) {
        function closeSidebar() {
            sidebar.classList.remove('is-open');
            overlay.classList.remove('is-visible');
            toggle.setAttribute('aria-expanded', 'false');
        }

        function openSidebar() {
            sidebar.classList.add('is-open');
            overlay.classList.add('is-visible');
            toggle.setAttribute('aria-expanded', 'true');
        }

        // Toggle button click
        toggle.addEventListener('click', function() {
            if (sidebar.classList.contains('is-open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });

        // Overlay click
        overlay.addEventListener('click', closeSidebar);

        // Escape key
        window.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeSidebar();
            }
        });
    }

    /**
     * Active Navigation Link
     */
    const currentPath = window.location.pathname;
    const navLinks = document.querySelectorAll('.nav-link');

    navLinks.forEach(function(link) {
        const href = link.getAttribute('href');
        if (href && currentPath.startsWith(href)) {
            link.classList.add('is-active');
        }
    });

    /**
     * Flash Message Auto-dismiss (optional)
     */
    const flashes = document.querySelectorAll('.flash');
    flashes.forEach(function(flash) {
        setTimeout(function() {
            flash.style.opacity = '0';
            flash.style.transition = 'opacity 0.3s ease';
            setTimeout(function() {
                flash.remove();
            }, 300);
        }, 5000);
    });


    
    /**
     * Console Welcome Message
     */
    console.log('%c WazeBR ', 'background: #0891b2; color: #fff; font-size: 16px; padding: 4px 8px; border-radius: 4px;', 'Application loaded successfully!');

})();
