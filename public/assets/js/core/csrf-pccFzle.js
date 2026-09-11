export function getToken(selector = 'input[name="_csrf_token"]') {
    return document.querySelector(selector)?.value ?? null;
}

export function addToken(data, token = getToken()) {
    if (!token) return data;
    const body = data instanceof FormData ? data : new FormData();
    if (!(data instanceof FormData)) Object.entries(data).forEach(([key, value]) => body.append(key, value));
    body.set('_csrf_token', token);
    return body;
}
