/**
 * Carrega o Leaflet por <script> externo (o CSP do projeto não aceita script inline).
 * Tenta unpkg (com SRI) e, se falhar, cdnjs. Resolve true/false; nunca rejeita.
 */
const SOURCES = [
    {
        src: 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
        integrity: 'sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=',
    },
    { src: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js' },
];

let pending = null;

function loadScript({ src, integrity }) {
    return new Promise((resolve, reject) => {
        const el = document.createElement('script');
        el.src = src;
        el.async = true;
        if (integrity) {
            el.integrity = integrity;
            el.crossOrigin = 'anonymous';
        }
        el.onload = () => resolve();
        el.onerror = () => { el.remove(); reject(new Error(`falha ao carregar ${src}`)); };
        document.head.appendChild(el);
    });
}

export function ensureLeaflet() {
    if (typeof window.L !== 'undefined') return Promise.resolve(true);
    if (pending) return pending;

    pending = (async () => {
        for (const source of SOURCES) {
            try {
                await loadScript(source);
                if (typeof window.L !== 'undefined') return true;
            } catch (error) {
                console.warn('[leaflet-loader]', error.message);
            }
        }
        return false;
    })();

    return pending;
}

export default ensureLeaflet;
