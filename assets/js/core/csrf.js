export function getToken(selector = 'input[name="_csrf_token"]') {
    return document.querySelector(selector)?.value ?? null;
}
