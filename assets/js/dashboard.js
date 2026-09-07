/**
 * wazeBR - Dashboard JavaScript
 * Complete dashboard functionality
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialize all components
    initSidebar();
    initMobileMenu();
    initNotifications();
    initSearch();
    initTaskCheckboxes();
    initQuickActions();
    initAnimations();
});

/**
 * Sidebar toggle functionality
 */
function initSidebar() {
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebar-toggle');
    
    if (!sidebar || !sidebarToggle) return;
    
    sidebarToggle.addEventListener('click', () => {
        sidebar.classList.toggle('collapsed');
        
        // Save state to localStorage
        localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
    });
    
    // Restore state from localStorage
    const collapsed = localStorage.getItem('sidebarCollapsed') === 'true';
    if (collapsed) {
        sidebar.classList.add('collapsed');
    }
}

/**
 * Mobile menu toggle
 */
function initMobileMenu() {
    const sidebar = document.getElementById('sidebar');
    const mobileMenuToggle = document.getElementById('mobile-menu-toggle');
    
    if (!sidebar || !mobileMenuToggle) return;
    
    mobileMenuToggle.addEventListener('click', () => {
        sidebar.classList.toggle('active');
    });
    
    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', (e) => {
        if (window.innerWidth <= 992) {
            if (!sidebar.contains(e.target) && !mobileMenuToggle.contains(e.target)) {
                sidebar.classList.remove('active');
            }
        }
    });
    
    // Close sidebar on window resize
    window.addEventListener('resize', () => {
        if (window.innerWidth > 992) {
            sidebar.classList.remove('active');
        }
    });
}

/**
 * Notifications functionality
 */
function initNotifications() {
    const notificationBtn = document.querySelector('.notification-btn');
    
    if (!notificationBtn) return;
    
    notificationBtn.addEventListener('click', () => {
        // TODO: Implement notification dropdown
        showNotificationPanel();
    });
}

/**
 * Show notification panel
 */
function showNotificationPanel() {
    // TODO: Create notification dropdown
    console.log('Notifications panel');
}

/**
 * Search functionality
 */
function initSearch() {
    const searchInput = document.querySelector('.search-input');
    
    if (!searchInput) return;
    
    // Debounce search
    let debounceTimer;
    searchInput.addEventListener('input', (e) => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
            performSearch(e.target.value);
        }, 300);
    });
    
    // Search on Enter
    searchInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            performSearch(e.target.value);
        }
    });
}

/**
 * Perform search
 */
function performSearch(query) {
    if (!query.trim()) return;
    
    // TODO: Implement search functionality
    console.log('Searching for:', query);
}

/**
 * Task checkboxes functionality
 */
function initTaskCheckboxes() {
    const taskCheckboxes = document.querySelectorAll('.task-checkbox input[type="checkbox"]');
    
    taskCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', (e) => {
            const taskItem = e.target.closest('.task-item');
            
            if (e.target.checked) {
                taskItem.classList.add('completed');
                // TODO: Update task status in backend
            } else {
                taskItem.classList.remove('completed');
                // TODO: Update task status in backend
            }
        });
    });
}

/**
 * Quick actions functionality
 */
function initQuickActions() {
    const quickActionBtns = document.querySelectorAll('.quick-action-btn');
    
    quickActionBtns.forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            
            const action = btn.querySelector('span').textContent;
            
            // TODO: Implement quick action
            console.log('Quick action:', action);
            
            // Show feedback
            showFeedback(`A\u00e7\u00e3o: ${action}`);
        });
    });
}

/**
 * Show feedback message
 */
function showFeedback(message) {
    // TODO: Implement toast/notification
    console.log('Feedback:', message);
}

/**
 * Animations
 */
function initAnimations() {
    // Animate stats on load
    const statCards = document.querySelectorAll('.stat-card');
    statCards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        
        setTimeout(() => {
            card.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 100);
    });
    
    // Animate cards on load
    const cards = document.querySelectorAll('.card');
    cards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        
        setTimeout(() => {
            card.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, 300 + (index * 100));
    });
    
    // Animate list items
    const listItems = document.querySelectorAll('.activity-item, .task-item, .route-item, .alert-item, .partner-item');
    listItems.forEach((item, index) => {
        item.style.opacity = '0';
        item.style.transform = 'translateX(-20px)';
        
        setTimeout(() => {
            item.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            item.style.opacity = '1';
            item.style.transform = 'translateX(0)';
        }, 500 + (index * 50));
    });
}

/**
 * Utility: Format number with K/M suffix
 */
function formatNumber(num) {
    if (num >= 1000000) {
        return (num / 1000000).toFixed(1) + 'M';
    } else if (num >= 1000) {
        return (num / 1000).toFixed(1) + 'K';
    }
    return num.toString();
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

/**
 * Utility: Throttle function
 */
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

/**
 * Utility: Check if element is in viewport
 */
function isInViewport(element) {
    const rect = element.getBoundingClientRect();
    return (
        rect.top >= 0 &&
        rect.left >= 0 &&
        rect.bottom <= (window.innerHeight || document.documentElement.clientHeight) &&
        rect.right <= (window.innerWidth || document.documentElement.clientWidth)
    );
}

/**
 * Handle window resize events
 */
function initResizeHandler() {
    const resizeHandler = debounce(() => {
        // Adjust layout on resize
        if (window.innerWidth > 992) {
            const sidebar = document.getElementById('sidebar');
            if (sidebar) {
                sidebar.classList.remove('active');
            }
        }
    }, 250);
    
    window.addEventListener('resize', resizeHandler);
}

// Initialize resize handler
initResizeHandler();

// Console log for debugging
console.log('wazeBR Dashboard initialized successfully! \ud83d\ude80');
