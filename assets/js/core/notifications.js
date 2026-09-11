export function notify(message, type = 'info') {
    document.dispatchEvent(new CustomEvent('app:notification', { detail: { message, type } }));
}

export function showError(error, fallback = 'Não foi possível concluir a operação.') {
    console.error(error);
    notify(error?.message || fallback, 'error');
}
