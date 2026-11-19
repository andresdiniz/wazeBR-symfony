/**
 * Impressão da comparação de rotas.
 *
 * Substitui o onclick="window.print()" e o <script> inline que existiam no template:
 * o CSP do projeto (script-src sem 'unsafe-inline') bloqueia os dois.
 */
document.querySelectorAll('[data-print]').forEach((button) => {
    button.addEventListener('click', () => window.print());
});

// Abre a janela de impressão sozinha, como antes (o botão continua disponível se o navegador bloquear).
if (document.querySelector('[data-autoprint]')) {
    window.addEventListener('load', () => setTimeout(() => window.print(), 300));
}
