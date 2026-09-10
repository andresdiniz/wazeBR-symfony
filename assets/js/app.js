/**
 * wazeBR — Entry point principal (AssetMapper / Webpack Encore)
 *
 * Este arquivo é o ponto de entrada declarado em config/packages/asset_mapper.yaml
 * (ou webpack.config.js se o projeto usa Webpack Encore).
 *
 * Importa os assets globais e inicializa os módulos de cada página.
 * Módulos específicos de página (cifs.js, dashboard.js, login.js) são
 * carregados pelo próprio template via <script src="{{ asset('js/xxx.js') }}">,
 * portanto não precisam ser importados aqui — apenas os globals.
 */

/* ── CSS global (ordem importa) ──────────────────────────────────── */
// import '../css/global.css';      // ← descomente se usar Webpack Encore
// import '../css/dashboard.css';
// import '../css/login.css';
// import '../css/cifs.css';

/* ── Inicializações globais ──────────────────────────────────────── */

document.addEventListener('DOMContentLoaded', function () {
    initGlobalFlashAutoDismiss();
    initSmoothPageTransitions();
});

/**
 * Descarta automaticamente flash messages após 8 s.
 * Funciona em qualquer template que use .cifs-flash ou .alert com botão
 * de fechar com a classe [data-dismiss].
 */
function initGlobalFlashAutoDismiss() {
    const AUTO_DISMISS_MS = 8000;

    document.querySelectorAll('[data-dismiss], .cifs-flash__close').forEach(btn => {
        btn.addEventListener('click', function () {
            this.closest('.alert, .cifs-flash')?.remove();
        });
    });

    document.querySelectorAll('.alert[data-auto-dismiss], .cifs-flash').forEach(el => {
        setTimeout(() => {
            el.style.transition = 'opacity .4s ease';
            el.style.opacity = '0';
            setTimeout(() => el.remove(), 400);
        }, AUTO_DISMISS_MS);
    });
}

/**
 * Adiciona classe .is-leaving ao body antes de navegações internas
 * para permitir transição de saída via CSS.
 */
function initSmoothPageTransitions() {
    document.addEventListener('click', function (e) {
        const link = e.target.closest('a[href]');
        if (!link) return;

        const href = link.getAttribute('href');
        if (!href || href.startsWith('#') || href.startsWith('mailto:') ||
            href.startsWith('tel:') || link.target === '_blank') return;

        try {
            const url = new URL(href, window.location.href);
            if (url.origin !== window.location.origin) return;
        } catch {
            return;
        }

        // Transição opcional (CSS deve definir body.is-leaving)
        document.body.classList.add('is-leaving');
    });
}
