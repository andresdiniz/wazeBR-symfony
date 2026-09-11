export function getFilterValues(form) {
    return Object.fromEntries(new FormData(form));
}
