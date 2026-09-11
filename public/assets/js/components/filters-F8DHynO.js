export function getFilterValues(form) {
    return Object.fromEntries(new FormData(form));
}

export function bindFilterSubmit(form, callback) {
    form?.addEventListener('submit', (event) => {
        event.preventDefault();
        callback(getFilterValues(form), event);
    });
}
