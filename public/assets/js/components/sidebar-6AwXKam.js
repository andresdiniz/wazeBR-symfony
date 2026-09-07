/**
 * wazeBR - Sidebar Component Initialization
 */

import { storage } from '../utils/storage.js';

let sidebar = null;
let toggleBtn = null;
let isCollapsed = false;

export function initSidebar(config) {
  sidebar = document.querySelector('.sidebar');
  toggleBtn = document.querySelector('.sidebar-toggle, .header-toggle');
  
  if (!sidebar) return;
  
  isCollapsed = storage.get('sidebar_collapsed') === true;
  
  if (isCollapsed) {
    sidebar.classList.add('collapsed');
  }
  
  if (toggleBtn) {
    toggleBtn.addEventListener('click', toggleSidebar);
  }
  
  setupSubmenus();
  setupMobile();
  
  console.log('[wazeBR] Sidebar initialized');
}

function toggleSidebar() {
  if (!sidebar) return;
  
  isCollapsed = !isCollapsed;
  sidebar.classList.toggle('collapsed', isCollapsed);
  
  storage.set('sidebar_collapsed', isCollapsed);
  
  window.dispatchEvent(new CustomEvent('sidebar:toggle', { 
    detail: { collapsed: isCollapsed } 
  }));
}

function setupSubmenus() {
  const submenuToggles = sidebar.querySelectorAll('.has-submenu > .sidebar-menu-item');
  
  submenuToggles.forEach(toggle => {
    toggle.addEventListener('click', (e) => {
      e.preventDefault();
      const parent = toggle.parentElement;
      parent.classList.toggle('open');
    });
  });
}

function setupMobile() {
  const overlay = document.createElement('div');
  overlay.className = 'sidebar-overlay';
  overlay.style.cssText = `
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 999;
    display: none;
  `;
  
  document.body.appendChild(overlay);
  
  overlay.addEventListener('click', () => {
    sidebar.classList.remove('open');
    overlay.style.display = 'none';
  });
  
  window.addEventListener('sidebar:open', () => {
    if (window.innerWidth <= 768) {
      sidebar.classList.add('open');
      overlay.style.display = 'block';
    }
  });
}

export function collapse() {
  if (!sidebar) return;
  isCollapsed = true;
  sidebar.classList.add('collapsed');
  storage.set('sidebar_collapsed', true);
}

export function expand() {
  if (!sidebar) return;
  isCollapsed = false;
  sidebar.classList.remove('collapsed');
  storage.set('sidebar_collapsed', false);
}

export function toggle() {
  toggleSidebar();
}

export default { init: initSidebar, collapse, expand, toggle };
