/**
 * WazeBR — Sidebar
 *
 * Responsabilidades:
 *
 * - Recolher / expandir sidebar no desktop
 * - Abrir / fechar sidebar no mobile
 * - Controlar overlay
 * - Persistir estado da sidebar
 * - Atualizar ARIA
 * - Fechar com ESC
 * - Fechar ao clicar no overlay
 * - Fechar ao navegar no mobile
 * - Sincronizar estado com .app-shell
 */

export function initSidebar(document = globalThis.document) {
  if (!document) return;

  const sidebar = document.querySelector(
    '[data-sidebar]'
  );

  if (!sidebar) return;


  /* ==========================================================
     EVITA DUPLA INICIALIZAÇÃO
     ========================================================== */

  if (
    sidebar.dataset.wazebrInitialized === 'true'
  ) {
    return;
  }

  sidebar.dataset.wazebrInitialized = 'true';


  /* ==========================================================
     ELEMENTOS
     ========================================================== */

  const shell = sidebar.closest(
    '.app-shell'
  );

  const collapseButton = sidebar.querySelector(
    '[data-sidebar-collapse]'
  );

  const overlay = document.querySelector(
    '[data-sidebar-overlay]'
  );


  /* ==========================================================
     CONFIGURAÇÃO
     ========================================================== */

  const STORAGE_KEY =
    'wazebr_sidebar_collapsed';

  const MOBILE_BREAKPOINT = 1180;


  /* ==========================================================
     MEDIA QUERY
     ========================================================== */

  const mediaQuery =
    window.matchMedia(
      `(max-width: ${MOBILE_BREAKPOINT}px)`
    );


  const isMobile = () => {
    return mediaQuery.matches;
  };


  /* ==========================================================
     SINCRONIZA SHELL
     ========================================================== */

  const syncShellState = (collapsed) => {

    if (!shell) {
      return;
    }

    shell.classList.toggle(
      'sidebar-collapsed',
      collapsed
    );
  };


  /* ==========================================================
     ESTADO COLLAPSED
     ========================================================== */

  const setCollapseState = (
    collapsed,
    persist = true
  ) => {

    /*
     * No mobile não existe estado collapsed.
     * A sidebar simplesmente abre/fecha.
     */

    if (isMobile()) {
      return;
    }


    sidebar.classList.toggle(
      'is-collapsed',
      collapsed
    );


    syncShellState(
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
        /*
         * localStorage pode estar indisponível.
         */
      }

    }
  };


  /* ==========================================================
     ABRIR MOBILE
     ========================================================== */

  const openMobileSidebar = () => {

    if (!isMobile()) {
      return;
    }


    sidebar.classList.add(
      'is-open'
    );


    if (overlay) {
      overlay.hidden = false;
    }


    document.body.classList.add(
      'sidebar-is-open'
    );


    document.dispatchEvent(
      new CustomEvent(
        'wazebr:sidebar:open'
      )
    );
  };


  /* ==========================================================
     FECHAR MOBILE
     ========================================================== */

  const closeMobileSidebar = () => {

    sidebar.classList.remove(
      'is-open'
    );


    if (overlay) {
      overlay.hidden = true;
    }


    document.body.classList.remove(
      'sidebar-is-open'
    );


    document.dispatchEvent(
      new CustomEvent(
        'wazebr:sidebar:close'
      )
    );
  };


  /* ==========================================================
     TOGGLE MOBILE
     ========================================================== */

  const toggleMobileSidebar = () => {

    if (
      sidebar.classList.contains(
        'is-open'
      )
    ) {

      closeMobileSidebar();

    } else {

      openMobileSidebar();

    }
  };


  /* ==========================================================
     RESTAURA ESTADO
     ========================================================== */

  if (!isMobile()) {

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

      setCollapseState(
        false,
        false
      );
    }
  }


  /* ==========================================================
     BOTÃO COLLAPSE
     ========================================================== */

  if (collapseButton) {

    collapseButton.addEventListener(
      'click',
      () => {

        if (isMobile()) {
          return;
        }


        const collapsed =
          !sidebar.classList.contains(
            'is-collapsed'
          );


        setCollapseState(
          collapsed
        );
      }
    );
  }


  /* ==========================================================
     OVERLAY
     ========================================================== */

  if (overlay) {

    overlay.addEventListener(
      'click',
      () => {
        closeMobileSidebar();
      }
    );

  }


  /* ==========================================================
     ESC
     ========================================================== */

  document.addEventListener(
    'keydown',
    (event) => {

      if (
        event.key !== 'Escape'
      ) {
        return;
      }


      if (
        isMobile() &&
        sidebar.classList.contains(
          'is-open'
        )
      ) {

        closeMobileSidebar();

      }

    }
  );


  /* ==========================================================
     LINKS
     ========================================================== */

  sidebar
    .querySelectorAll(
      '[data-sidebar-link]'
    )
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

  const handleResponsiveChange = () => {

    if (mediaQuery.matches) {

      /*
       * Entrou no mobile.
       */

      sidebar.classList.remove(
        'is-collapsed'
      );


      syncShellState(
        false
      );


      closeMobileSidebar();


      if (collapseButton) {

        collapseButton.setAttribute(
          'aria-expanded',
          'true'
        );

        collapseButton.setAttribute(
          'aria-label',
          'Recolher menu lateral'
        );

      }

      return;
    }


    /*
     * Voltou para desktop.
     */

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

      setCollapseState(
        false,
        false
      );

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
     CONTROLLER PÚBLICO
     ========================================================== */

  sidebar.sidebarController = {

    open: openMobileSidebar,

    close: closeMobileSidebar,

    toggle: () => {

      if (isMobile()) {

        toggleMobileSidebar();

      } else {

        setCollapseState(
          !sidebar.classList.contains(
            'is-collapsed'
          )
        );

      }

    },

    collapse: () => {

      if (!isMobile()) {

        setCollapseState(
          true
        );

      }

    },

    expand: () => {

      if (!isMobile()) {

        setCollapseState(
          false
        );

      }

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
