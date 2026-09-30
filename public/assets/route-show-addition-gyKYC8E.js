// ─────────────────────────────────────────────────────────────────────────────
// Export dropdown — adicionar DENTRO de initRouteShow(), após as chamadas
// existentes de renderHeatmap / initCharts / initMainMap / initSubRouteMaps
// ─────────────────────────────────────────────────────────────────────────────

function initExportMenu(root) {
    const page = resolvePage(root);
    if (!page) return;

    const wrapper  = page.querySelector('[data-export-menu]');
    const toggle   = page.querySelector('[data-export-toggle]');
    const dropdown = page.querySelector('[data-export-dropdown]');

    if (!wrapper || !toggle || !dropdown) return;

    function open() {
        dropdown.hidden = false;
        toggle.setAttribute('aria-expanded', 'true');
        // Foca o primeiro item para acessibilidade
        dropdown.querySelector('.rs-export__item')?.focus();
    }

    function close() {
        dropdown.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
    }

    function isOpen() {
        return !dropdown.hidden;
    }

    toggle.addEventListener('click', (e) => {
        e.stopPropagation();
        isOpen() ? close() : open();
    });

    // Fecha ao clicar fora
    document.addEventListener('click', (e) => {
        if (!wrapper.contains(e.target)) close();
    });

    // Fecha com Escape
    wrapper.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            close();
            toggle.focus();
        }
    });

    // Fecha automaticamente após iniciar o download
    dropdown.querySelectorAll('.rs-export__item').forEach((link) => {
        link.addEventListener('click', () => {
            setTimeout(close, 200);
        });
    });
}

// ─────────────────────────────────────────────────────────────────────────────
// Bootstrap — versão atualizada de initRouteShow()
// Substitui a função existente no final do arquivo
// ─────────────────────────────────────────────────────────────────────────────

let bootstrapped = false;

export function initRouteShow(root = document) {
    const page = resolvePage(root);
    if (!page || bootstrapped) return;
    bootstrapped = true;

    renderHeatmap(page);
    initCharts(page);
    initMainMap(page);
    initSubRouteMaps(page);
    initExportMenu(page);   // ← novo
}

export default initRouteShow;
