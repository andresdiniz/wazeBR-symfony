import { qs } from '../core/dom.js';

export function initCifs(root = document) {
    if (!qs('[data-page="cifs"]', root) && !qs('.cifs-page', root)) return;
}
