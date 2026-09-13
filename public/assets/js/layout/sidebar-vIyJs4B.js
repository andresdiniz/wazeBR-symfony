/**
 * WazeBR — Sidebar
 *
 * Responsabilidades:
 * - Recolher/expandir sidebar no desktop
 * - Abrir/fechar sidebar no mobile
 * - Controlar overlay
 * - Persistir estado de collapse
 * - Fechar com ESC
 * - Fechar ao clicar no overlay
 * - Evitar inicialização duplicada
 */

export function initSidebar(document = globalThis.document) {
  if (!document) return;

  const sidebar = document.querySelector('[data-sidebar]');
  if (!sidebar) return;

  if (sidebar.dataset.wazebrInitialized === 'true') {
    return;
  }

  sidebar.dataset.wazebrInitialized = 'true';

  const collapseButton = sidebar.querySelector(
    '[data-sidebar-collapse]'
  );

  const overlay = document.querySelector(
    '[data-sidebar-overlay]'
  );

  const STORAGE_KEY = 'wazebr_sidebar_collapsed';
  const MOBILE_BREAKPOINT = 1180;


  /* ==========================================================
     HELPERS
     ========================================================== */

  const isMobile = () => {
    return window.matchMedia(
      `(max-width: ${MOBILE_BREAKPOINT}px)`
    ).matches;
  };


  const setCollapseState = (collapsed, persist = true) => {
    if (isMobile()) {
      return;
    }

    sidebar.classList.toggle(
      'is-collapsed',
      collapsed
    );

    if (collapseButton) {
      collapseButton.setAttribute(
        'aria-expanded',
        String(!collapsed)
      );

      collapseButton.setAttribute(
        'aria-label',
        collapsed
          ? 'Expandir menu lateral'
          : 'Recolher menu lateral'
      );
    }

    if (persist) {
      try {
        localStorage.setItem(
          STORAGE_KEY,
          collapsed ? '1' : '0'
        );
      } catch {
        // localStorage pode estar bloqueado.
      }
    }
  };


  const openMobileSidebar = () => {
    if (!isMobile()) {
      return;
    }

    sidebar.classList.add('is-open');

    if (overlay) {
      overlay.hidden = false;
    }

    document.body.classList.add(
      'sidebar-is-open'
    );

    document.dispatchEvent(
      new CustomEvent('wazebr:sidebar:open')
    );
  };


  const closeMobileSidebar = () => {
    sidebar.classList.remove('is-open');

    if (overlay) {
      overlay.hidden = true;
    }

    document.body.classList.remove(
      'sidebar-is-open'
    );

    document.dispatchEvent(
      new CustomEvent('wazebr:sidebar:close')
    );
  };


  const toggleMobileSidebar = () => {
    if (sidebar.classList.contains('is-open')) {
      closeMobileSidebar();
    } else {
      openMobileSidebar();
    }
  };


  /* ==========================================================
     RESTAURA ESTADO
     ========================================================== */

  try {
    const savedState = localStorage.getItem(
      STORAGE_KEY
    );

    if (savedState === '1' && !isMobile()) {
      setCollapseState(true, false);
    }
  } catch {
    // Estado inicial continua funcionando normalmente.
  }


  /* ==========================================================
     COLLAPSE DESKTOP
     ========================================================== */

  collapseButton?.addEventListener(
    'click',
    () => {
      if (isMobile()) {
        return;
      }

      const collapsed =
        !sidebar.classList.contains(
          'is-collapsed'
        );

      setCollapseState(collapsed);
    }
  );


  /* ==========================================================
     OVERLAY
     ========================================================== */

  overlay?.addEventListener(
    'click',
    closeMobileSidebar
  );


  /* ==========================================================
     ESC
     ========================================================== */

  document.addEventListener(
    'keydown',
    (event) => {
      if (event.key !== 'Escape') {
        return;
      }

      if (
        isMobile() &&
        sidebar.classList.contains('is-open')
      ) {
        closeMobileSidebar();
      }
    }
  );


  /* ==========================================================
     LINKS MOBILE
     ========================================================== */

  sidebar
    .querySelectorAll('[data-sidebar-link]')
    .forEach((link) => {
      link.addEventListener(
        'click',
        () => {
          if (isMobile()) {
            closeMobileSidebar();
          }
        }
      );
    });


  /* ==========================================================
     RESPONSIVIDADE
     ========================================================== */

  const mediaQuery = window.matchMedia(
    `(max-width: ${MOBILE_BREAKPOINT}px)`
  );

  const handleResponsiveChange = () => {
    if (mediaQuery.matches) {
      sidebar.classList.remove(
        'is-collapsed'
      );

      closeMobileSidebar();

      if (collapseButton) {
        collapseButton.setAttribute(
          'aria-expanded',
          'true'
        );
      }

      return;
    }

    closeMobileSidebar();

    try {
      const savedState =
        localStorage.getItem(
          STORAGE_KEY
        );

      setCollapseState(
        savedState === '1',
        false
      );
    } catch {
      setCollapseState(false, false);
    }
  };


  if (
    typeof mediaQuery.addEventListener ===
    'function'
  ) {
    mediaQuery.addEventListener(
      'change',
      handleResponsiveChange
    );
  } else {
    mediaQuery.addListener(
      handleResponsiveChange
    );
  }


  /* ==========================================================
     API PÚBLICA
     ========================================================== */

  sidebar.sidebarController = {
    open: openMobileSidebar,
    close: closeMobileSidebar,
    toggle: toggleMobileSidebar,

    collapse: () => {
      setCollapseState(true);
    },

    expand: () => {
      setCollapseState(false);
    },

    isCollapsed: () => {
      return sidebar.classList.contains(
        'is-collapsed'
      );
    },

    isOpen: () => {
      return sidebar.classList.contains(
        'is-open'
      );
    }
  };
}


/**
 * Opcional:
 * Permite inicializar automaticamente quando
 * o módulo é carregado.
 */
export function autoInitSidebar() {
  if (
    typeof document === 'undefined'
  ) {
    return;
  }

  initSidebar(document);
}
