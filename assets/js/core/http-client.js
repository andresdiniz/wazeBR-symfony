export async function request(url, options = {}) {
    const response = await fetch(url, options);
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    return response;
}

export async function requestJson(url, options = {}) {
    const response = await request(url, {
        ...options,
        headers: { Accept: 'application/json', ...(options.headers ?? {}) },
    });
    return response.json();
}
