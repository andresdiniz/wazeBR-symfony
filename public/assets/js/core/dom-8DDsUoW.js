export const qs = (selector, root = document) => root.querySelector(selector);
export const qsa = (selector, root = document) => [...root.querySelectorAll(selector)];
export const on = (root, event, selector, handler) => root.addEventListener(event, (e) => {
    const target = e.target.closest(selector);
    if (target) handler(e, target);
});
