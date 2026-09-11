/* Shared HTTP extension point. Existing request helpers remain in assets/js/global.js. */
export async function request(url, options = {}) {
    const response = await fetch(url, options);

    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }

    return response;
}
