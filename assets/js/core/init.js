/**
 * wazeBR - Application Initialization
 * Core entry point for all JavaScript modules
 */

// Import utilities
import { helpers } from '../utils/helpers.js';
import { storage } from '../utils/storage.js';

/**
 * Initialize the application
 */
export function initApp(config) {
  console.log('[wazeBR] Initializing app...');
  
  // Initialize storage
  storage.init(config?.storage);
  
  // Initialize UI components
  initSidebar();
  initNavbar();
  initNotifications();
  initModals();
  initTables();
  initForms();
  
  // Setup global error handler
  setupErrorHandler();
  
  console.log('[wazeBR] App initialized successfully');
}

/**
 * Initialize sidebar functionality
 */
function initSidebar() {
  const sidebar = document.querySelector('.sidebar');
  const toggle = document.querySelector('.sidebar-toggle, .header-toggle');
  
  if (!sidebar) return;
  
  // Load saved state
  const isCollapsed = storage.get('sidebar_collapsed') === true;
  if (isCollapsed) {
    sidebar.classList.add('collapsed');
  }
  
  // Toggle functionality
  toggle?.addEventListener('click', () => {
    sidebar.classList.toggle('collapsed');
    storage.set('sidebar_collapsed', !sidebar.classList.contains('collapsed'));
  });
  
  // Submenu toggles
  const submenus = sidebar.querySelectorAll('.has-submenu > .sidebar-menu-item');
  submenus.forEach(item => {
    item.addEventListener('click', (e) => {
      e.preventDefault();
      item.parentElement.classList.toggle('open');
    });
  });
  
  console.log('[wazeBR] Sidebar initialized');
}

/**
 * Initialize navbar functionality
 */
function initNavbar() {
  const searchInput = document.getElementById('headerSearch');
  const notificationsDropdown = document.getElementById('notificationsDropdown');
  
  // Search with debounce
  if (searchInput) {
    searchInput.addEventListener('input', helpers.debounce((e) => {
      const query = e.target.value;
      if (query.length >= 3) {
        console.log('[wazeBR] Searching:', query);
      }
    }, 300));
  }
  
  // Notifications dropdown
  if (notificationsDropdown) {
    const toggle = notificationsDropdown.querySelector('[data-bs-toggle="dropdown"]');
    toggle?.addEventListener('click', (e) => {
      e.preventDefault();
      notificationsDropdown.classList.toggle('open');
    });
  }
  
  console.log('[wazeBR] Navbar initialized');
}

/**
 * Initialize notifications
 */
function initNotifications() {
  const badge = document.querySelector('.header-action-badge');
  if (badge) {
    const count = parseInt(badge.textContent) || 0;
    if (count > 0) {
      console.log('[wazeBR] Unread notifications:', count);
    }
  }
}

/**
 * Initialize modals
 */
function initModals() {
  // Bootstrap modals are auto-initialized
  console.log('[wazeBR] Modals ready');
}

/**
 * Initialize tables
 */
function initTables() {
  const tables = document.querySelectorAll('.table');
  tables.forEach(table => {
    table.classList.add('table-hover');
  });
  console.log('[wazeBR] Tables initialized');
}

/**
 * Initialize forms
 */
function initForms() {
  const forms = document.querySelectorAll('form');
  forms.forEach(form => {
    if (form.dataset.validate === 'true') {
      form.addEventListener('submit', (e) => {
        const isValid = validateForm(form);
        if (!isValid) {
          e.preventDefault();
        }
      });
    }
  });
  console.log('[wazeBR] Forms initialized');
}

/**
 * Validate form
 */
function validateForm(form) {
  const inputs = form.querySelectorAll('[data-required]');
  let isValid = true;
  
  inputs.forEach(input => {
    if (!input.value.trim()) {
      input.classList.add('error');
      isValid = false;
    } else {
      input.classList.remove('error');
    }
  });
  
  return isValid;
}

/**
 * Setup global error handler
 */
function setupErrorHandler() {
  window.addEventListener('error', (event) => {
    console.error('[wazeBR] Global error:', event.error);
  });
  
  window.addEventListener('unhandledrejection', (event) => {
    console.error('[wazeBR] Unhandled promise rejection:', event.reason);
  });
}

export default initApp;
