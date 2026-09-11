import { qs } from '../core/dom.js';

export function getChartElement(root = document) {
    return qs('[data-chart]', root);
}

export function initChartPlaceholder(root = document) {
    return getChartElement(root);
}
