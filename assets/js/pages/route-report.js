/**
 * pages/route-report.js — botão "Imprimir / Salvar em PDF" do relatório da rota.
 * O relatório em si é HTML/SVG estático (renderizado no servidor); este módulo só
 * liga o botão, porque a CSP do projeto não deve depender de handlers inline.
 */
document.querySelectorAll('[data-report-print]').forEach((button) => {
    button.addEventListener('click', () => window.print());
});
