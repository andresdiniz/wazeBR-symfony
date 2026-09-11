import { qs } from '../core/dom.js';

export function getMapElement(root = document) {
    return qs('[data-map]', root);
}

export function initMapPlaceholder(root = document) {
    return getMapElement(root);
}
