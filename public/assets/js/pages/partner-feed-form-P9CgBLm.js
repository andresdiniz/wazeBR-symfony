// assets/js/pages/partner-feed-form.js

'use strict';

// ── Estado global do mapa ──────────────────────────────────────────────────

let map = null;
let marker = null;
let polylinePath = null;
let polylinePoints = [];
let isRoadClosedMode = false;

// ── Configuração injetada pelo Twig via data-attributes ────────────────────

function getConfig(root) {
    const el = root.querySelector('[data-form-config]');
    if (!el) return {};
    return {
        defaultLat:   parseFloat(el.dataset.defaultLat  ?? '-20.6597'),
        defaultLng:   parseFloat(el.dataset.defaultLng  ?? '-43.8081'),
        defaultZoom:  parseInt(el.dataset.defaultZoom   ?? '13', 10),
        geoToken:     el.dataset.geoToken   ?? '',
        geoRegion:    el.dataset.geoRegion  ?? 'ROW',
        existingPolyline: el.dataset.existingPolyline
            ? JSON.parse(el.dataset.existingPolyline)
            : null,
    };
}

// ── Toast de validação (substitui alert()) ─────────────────────────────────

function showToast(message, duration = 3500) {
    let toast = document.getElementById('pf-validation-toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'pf-validation-toast';
        toast.className = 'pf-toast';
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-live', 'assertive');
        document.body.appendChild(toast);
    }

    toast.innerHTML = `
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="8" x2="12" y2="12"/>
            <line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        ${message}
    `;

    toast.classList.add('is-visible');
    clearTimeout(toast._hideTimer);
    toast._hideTimer = setTimeout(() => toast.classList.remove('is-visible'), duration);
}

// ── Mapa ───────────────────────────────────────────────────────────────────

function initMap(root, config) {
    const mapEl = root.getElementById('pf-map');
    if (!mapEl || typeof L === 'undefined') return;

    const { defaultLat, defaultLng, defaultZoom, geoToken, geoRegion, existingPolyline } = config;

    map = L.map('pf-map').setView([defaultLat, defaultLng], defaultZoom);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        maxZoom: 19,
    }).addTo(map);

    map.on('click', (e) => onMapClick(e, root, geoToken, geoRegion));

    root.getElementById('pf-clear-map-btn')
        ?.addEventListener('click', () => clearMap(root, defaultLat, defaultLng, defaultZoom));

    // Restaura geometria existente
    if (existingPolyline && existingPolyline.length > 0) {
        polylinePoints = existingPolyline;

        if (polylinePoints.length > 1) {
            isRoadClosedMode = true;
            drawPolyline(root);
            updateCoordsDisplay(root);
            updateMapInstructions(root);
        } else {
            const [lat, lng] = polylinePoints[0];
            marker = L.marker([lat, lng]).addTo(map);
            updateCoordsDisplay(root, lat, lng);
            reverseGeocode(lat, lng, root, geoToken, geoRegion);
        }
    }

    onTypeChange(root);
}

function onMapClick(e, root, geoToken, geoRegion) {
    const { lat, lng } = e.latlng;

    if (isRoadClosedMode) {
        polylinePoints.push([lat, lng]);
        drawPolyline(root);
        updateCoordsDisplay(root);
    } else {
        polylinePoints = [[lat, lng]];
        if (marker) map.removeLayer(marker);
        marker = L.marker([lat, lng]).addTo(map);
        updateCoordsDisplay(root, lat, lng);
        reverseGeocode(lat, lng, root, geoToken, geoRegion);
    }
}

function drawPolyline(root) {
    if (polylinePath) map.removeLayer(polylinePath);

    if (polylinePoints.length > 0) {
        polylinePath = L.polyline(polylinePoints, {
            color: '#ef4444',
            weight: 5,
            opacity: 0.9,
        }).addTo(map);

        if (polylinePoints.length > 1) {
            map.fitBounds(polylinePath.getBounds(), { padding: [50, 50] });
        }
    }

    const input = root.getElementById('pf-polyline-input');
    if (input) input.value = JSON.stringify(polylinePoints);
}

function updateCoordsDisplay(root, lat = null, lng = null) {
    const display = root.getElementById('pf-coords-display');
    if (!display) return;

    if (isRoadClosedMode && polylinePoints.length > 1) {
        const first = polylinePoints[0];
        const last  = polylinePoints[polylinePoints.length - 1];
        display.innerHTML = `
            <strong>Pontos:</strong> ${polylinePoints.length}<br>
            <strong>Início:</strong> ${first[0].toFixed(6)}, ${first[1].toFixed(6)}<br>
            <strong>Fim:</strong> ${last[0].toFixed(6)}, ${last[1].toFixed(6)}
        `;
    } else if (lat !== null && lng !== null) {
        display.innerHTML = `
            <strong>Lat:</strong> ${lat.toFixed(6)}<br>
            <strong>Lng:</strong> ${lng.toFixed(6)}
        `;
    } else {
        display.innerHTML = 'Lat: —&nbsp; Lng: —';
    }
}

// ✅ CORRIGIDO — usa GET com query string, conforme documentação oficial:
// https://support.google.com/waze/partners/answer/11486981
async function reverseGeocode(lat, lng, root, geoToken, geoRegion) {
    const streetInput    = root.getElementById('street');
    const referenceInput = root.getElementById('reference');
    if (!streetInput) return;

    streetInput.value    = 'Buscando endereço…';
    streetInput.disabled = true;

    // Tentativa 1: API Waze (se token configurado)
    if (geoToken) {
        try {
            // URLs base corretas conforme documentação oficial
            const baseUrl = {
                NA:  'https://www.waze.com/partnerhub-api/waze-map/streetsInfo',
                IL:  'https://www.waze.com/il-partnerhub-api/waze-map/streetsInfo',
                ROW: 'https://www.waze.com/row-partnerhub-api/waze-map/streetsInfo',
            };
            const base = baseUrl[geoRegion] ?? baseUrl.ROW;

            // Monta a URL como GET, com lat/lon/token na query string
            const url = new URL(base);
            url.searchParams.set('lat', String(lat));
            url.searchParams.set('lon', String(lng));
            url.searchParams.set('token', geoToken);

            const res = await fetch(url.toString(), { method: 'GET' });

            if (res.ok) {
                const data = await res.json();

                // Resposta esperada:
                // { "result":[{"streetNames":["..."],"distance":4.54}], "lon":..., "radius":50, "lat":... }
                const top = Array.isArray(data?.result) ? data.result[0] : null;
                const streetName = top?.streetNames?.[0] ?? null;

                if (streetName) {
                    streetInput.value = streetName;
                    streetInput.disabled = false;

                    // A API de Reverse Geocoding do Waze NÃO retorna cidade.
                    // Chamamos o Nominatim apenas para preencher 'reference' (cidade/bairro),
                    // sem sobrescrever o nome da rua já obtido do Waze.
                    if (referenceInput && !referenceInput.value) {
                        try {
                            const nomRes = await fetch(
                                `https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&addressdetails=1`
                            );
                            const nomData = await nomRes.json();
                            const addr = nomData?.address;
                            if (addr) {
                                const ref = addr.city || addr.town || addr.village
                                    || addr.county || addr.state || '';
                                if (ref) referenceInput.value = ref;
                            }
                        } catch (_) { /* silencioso — só enriquece o campo */ }
                    }
                    return;
                }
            } else {
                console.warn('[pf-form] Waze geocoding HTTP', res.status);
            }
        } catch (err) {
            console.warn('[pf-form] Waze geocoding falhou:', err);
        }
    }

    // Tentativa 2: Nominatim (fallback completo)
    try {
        const res  = await fetch(
            `https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&addressdetails=1`
        );
        const data = await res.json();

        if (data?.address) {
            const { address } = data;
            const street = address.road || address.residential || address.pedestrian
                || address.neighbourhood || address.city || address.town || address.village || '';
            const ref = address.city || address.town || address.village
                || address.county || address.state || '';

            streetInput.value = street || 'Endereço não encontrado';
            if (referenceInput && ref) referenceInput.value = ref;
        } else {
            streetInput.value = 'Endereço não encontrado';
        }
    } catch (err) {
        console.error('[pf-form] Nominatim falhou:', err);
        streetInput.value = 'Erro ao buscar endereço';
    } finally {
        streetInput.disabled = false;
    }
}

function clearMap(root, defaultLat, defaultLng, defaultZoom) {
    if (marker)       { map.removeLayer(marker);       marker       = null; }
    if (polylinePath) { map.removeLayer(polylinePath); polylinePath = null; }
    polylinePoints = [];

    const polylineInput = root.getElementById('pf-polyline-input');
    const streetInput   = root.getElementById('street');
    const coordDisplay  = root.getElementById('pf-coords-display');

    if (polylineInput) polylineInput.value = '';
    if (streetInput)   streetInput.value   = '';
    if (coordDisplay)  coordDisplay.innerHTML = 'Lat: —&nbsp; Lng: —';

    map.setView([defaultLat, defaultLng], defaultZoom);
}

// ── Tipo / subtipo ─────────────────────────────────────────────────────────

function onTypeChange(root) {
    const typeSelect = root.getElementById('cifsType');
    if (!typeSelect) return;

    const selectedType = typeSelect.value;
    isRoadClosedMode = (selectedType === 'ROAD_CLOSED');

    updateMapInstructions(root);
    updateSubtypes(root);

    // Se sair de ROAD_CLOSED com >1 ponto, mantém só o primeiro
    if (!isRoadClosedMode && polylinePoints.length > 1) {
        const first = polylinePoints[0];
        polylinePoints = [first];

        if (polylinePath) { map.removeLayer(polylinePath); polylinePath = null; }
        if (marker)       { map.removeLayer(marker);       marker       = null; }

        marker = L.marker([first[0], first[1]]).addTo(map);
        updateCoordsDisplay(root, first[0], first[1]);

        const input = root.getElementById('pf-polyline-input');
        if (input) input.value = JSON.stringify(polylinePoints);
    }
}

function updateMapInstructions(root) {
    const badge        = root.getElementById('pf-map-mode-badge');
    const instructions = root.getElementById('pf-map-instructions');
    if (!badge || !instructions) return;

    if (isRoadClosedMode) {
        badge.textContent = '🚧 Via interditada (polyline)';
        badge.classList.add('pf-map__mode-badge--road');
        badge.classList.remove('pf-map__mode-badge');
        instructions.textContent = 'Clique no mapa para adicionar pontos. Mínimo 2 pontos.';
    } else {
        badge.textContent = '📍 Ponto único';
        badge.classList.add('pf-map__mode-badge');
        badge.classList.remove('pf-map__mode-badge--road');
        instructions.textContent = 'Clique no mapa para marcar o local exato do evento.';
    }
}

function updateSubtypes(root) {
    const typeSelect    = root.getElementById('cifsType');
    const subtypeSelect = root.getElementById('cifsSubtype');
    if (!typeSelect || !subtypeSelect) return;

    const selectedType = typeSelect.value;

    subtypeSelect.querySelectorAll('option').forEach((opt) => {
        if (opt.value === '') {
            opt.hidden = false;
        } else {
            opt.hidden = opt.dataset.type !== selectedType;
        }
    });

    subtypeSelect.value = '';
}

// ── Validação do formulário ────────────────────────────────────────────────

function initFormValidation(root) {
    const form = root.getElementById('event-form');
    if (!form) return;

    form.addEventListener('submit', (e) => {
        if (isRoadClosedMode) {
            if (polylinePoints.length < 2) {
                e.preventDefault();
                showToast('Via interditada precisa de pelo menos 2 pontos no mapa.');
            }
        } else {
            if (polylinePoints.length !== 1) {
                e.preventDefault();
                showToast('Selecione um local no mapa antes de salvar.');
            }
        }
    });
}

// ── Ponto de entrada ───────────────────────────────────────────────────────

export function init(root = document) {
    const config = getConfig(root);

    // Leaflet é carregado via <script> no bloco javascripts — aguarda estar pronto
    if (typeof L !== 'undefined') {
        initMap(root, config);
    } else {
        window.addEventListener('load', () => initMap(root, config), { once: true });
    }

    // Listener do select de tipo via JS (sem onchange inline)
    root.getElementById('cifsType')
        ?.addEventListener('change', () => onTypeChange(root));

    initFormValidation(root);
}

export default init;
