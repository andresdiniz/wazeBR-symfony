export function notify(message, type = 'info') {
    document.dispatchEvent(new CustomEvent('app:notification', {
        detail: { message, type },
    }));
}
