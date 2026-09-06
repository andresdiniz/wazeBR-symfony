/**
 * Main Layout JavaScript
 * Handles sidebar toggle, mobile menu, and common utilities
 */

(function() {
  'use strict';

  // ===== Sidebar Toggle =====
  const SIDEBAR_STORAGE_KEY = 'wazebr_sidebar_collapsed';

  function initSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const toggleBtn = document.querySelector('.sidebar-toggle');

    if (!sidebar || !toggleBtn) return;

    // Restore collapsed state from localStorage
    const isCollapsed = localStorage.getItem(SIDEBAR_STORAGE_KEY) === 'true';
    if (isCollapsed) {
      sidebar.classList.add('collapsed');
    }

    toggleBtn.addEventListener('click', function(e) {
      e.preventDefault();
      sidebar.classList.toggle('collapsed');
      localStorage.setItem(SIDEBAR_STORAGE_KEY, sidebar.classList.contains('collapsed'));
    });

    // Mobile menu toggle
    const mobileToggle = document.querySelector('.mobile-sidebar-toggle');
    if (mobileToggle) {
      mobileToggle.addEventListener('click', function(e) {
        e.preventDefault();
        sidebar.classList.toggle('mobile-open');
      });
    }

    // Close sidebar on outside click (mobile)
    document.addEventListener('click', function(e) {
      if (window.innerWidth <= 1024) {
        const isClickInsideSidebar = sidebar.contains(e.target);
        const isClickOnToggle = mobileToggle && mobileToggle.contains(e.target);

        if (!isClickInsideSidebar && !isClickOnToggle && sidebar.classList.contains('mobile-open')) {
          sidebar.classList.remove('mobile-open');
        }
      }
    });
  }

  // ===== Utility Functions =====
  window.WazeBR = {
    /**
     * Show a toast notification
     * @param {string} message - Message to display
     * @param {'success'|'error'|'info'|'warning'} type - Toast type
     * @param {number} duration - Duration in ms
     */
    toast: function(message, type = 'info', duration = 3000) {
      const toast = document.createElement('div');
      toast.className = `toast toast-${type}`;
      toast.textContent = message;
      toast.style.cssText = `
        position: fixed;
        bottom: 20px;
        right: 20px;
        padding: 12px 20px;
        background: var(--color-${type === 'success' ? 'success' : type === 'error' ? 'danger' : type === 'warning' ? 'warning' : 'info'});
        color: white;
        border-radius: var(--border-radius-md);
        box-shadow: var(--shadow-lg);
        z-index: 9999;
        animation: slideIn 0.3s ease;
      `;

      document.body.appendChild(toast);

      setTimeout(() => {
        toast.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => toast.remove(), 300);
      }, duration);
    },

    /**
     * Confirm action with user
     * @param {string} message - Confirmation message
     * @returns {Promise<boolean>}
     */
    confirm: function(message) {
      return new Promise((resolve) => {
        if (window.confirm(message)) {
          resolve(true);
        } else {
          resolve(false);
        }
      });
    },

    /**
     * Debounce function
     * @param {Function} func - Function to debounce
     * @param {number} wait - Wait time in ms
     */
    debounce: function(func, wait) {
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

    /**
     * Format date to Brazilian format
     * @param {Date|string} date - Date to format
     * @returns {string}
     */
    formatDate: function(date) {
      const d = new Date(date);
      return d.toLocaleDateString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric'
      });
    },

    /**
     * Format datetime to Brazilian format
     * @param {Date|string} date - Date to format
     * @returns {string}
     */
    formatDateTime: function(date) {
      const d = new Date(date);
      return d.toLocaleString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
      });
    }
  };

  // ===== Initialize on DOM Ready =====
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSidebar);
  } else {
    initSidebar();
  }
})();