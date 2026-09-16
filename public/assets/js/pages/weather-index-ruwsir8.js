/**
 * pages/weather-index.js — Página /weather
 *
 * Por enquanto, sem JS específico (cards são links diretos).
 * O módulo existe pra manter o padrão do registry e permitir
 * adicionar filtro/ordenação depois sem mudar a infra.
 */

export function initWeatherIndex(root = document) {
    const page = root.querySelector?.('[data-weather-index]') ?? root;
    if (!page || page.dataset.wxIndexInit === '1') return;
    page.dataset.wxIndexInit = '1';

    // Reservado para futuras interações (filtros, ordenação).
}

export default initWeatherIndex;
